<?php

declare(strict_types=1);

require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/drill/v2/services/DrillLearningService.php';
require_once __DIR__ . '/../api/drill/v2/services/DrillRubricService.php';

const ACTOR_STAFF_ID = 46;
const DOMAIN_CODE = 'new_signing';
const RUBRIC_CODE = 'new_sign_training_demo_v1';

$pdo = getDB();
$domainStmt = $pdo->prepare('SELECT id FROM drill_training_domains WHERE domain_code = ? LIMIT 1');
$domainStmt->execute([DOMAIN_CODE]);
$domainId = (int) $domainStmt->fetchColumn();
if ($domainId <= 0) {
    throw new RuntimeException('新签训练域不存在。');
}

$rubricStmt = $pdo->prepare(
    'SELECT version.id FROM drill_rubric_versions version '
    . 'INNER JOIN drill_rubrics rubric ON rubric.id = version.rubric_id '
    . 'WHERE rubric.domain_id = ? AND rubric.rubric_code = ? ORDER BY version.version_no DESC LIMIT 1'
);
$rubricStmt->execute([$domainId, RUBRIC_CODE]);
$rubricVersionId = (int) $rubricStmt->fetchColumn();
if ($rubricVersionId <= 0) {
    throw new RuntimeException('新签培训演练评分规则不存在。');
}

$learning = new DrillLearningService($pdo);
$foundation = [
    [
        'criterion_code' => 'speaker_mapping_complete',
        'dimension_code' => 'needs_discovery',
        'knowledge_code' => 'new_sign_roleplay_speaker_mapping',
        'knowledge_title' => '角色识别与沟通对象确认',
        'resource_code' => 'new_sign_roleplay_speaker_mapping_exercise',
        'resource_title' => '角色识别练习',
        'knowledge_content' => [
            'objective' => '在开始表达前确认自己、家长和孩子的对话角色与当前问题。',
            'checklist' => ['确认称呼与关系', '确认孩子年龄和当前关注点', '用复述校准理解'],
        ],
        'resource_content' => [
            'type' => 'guided_exercise',
            'steps' => ['先复述家长描述', '确认本轮要解决的一个问题', '再进入需求追问'],
            'success_criteria' => '对话中能清楚区分角色和需求来源。',
        ],
    ],
    [
        'criterion_code' => 'evidence_traceable',
        'dimension_code' => 'needs_discovery',
        'knowledge_code' => 'new_sign_roleplay_evidence_traceability',
        'knowledge_title' => '可追溯沟通证据',
        'resource_code' => 'new_sign_roleplay_evidence_traceability_exercise',
        'resource_title' => '需求追问与复述练习',
        'knowledge_content' => [
            'objective' => '每项建议都能回到家长已经表达的关注点。',
            'checklist' => ['记录家长原始关注', '追问具体情境', '用家长原话复述后再回应'],
        ],
        'resource_content' => [
            'type' => 'guided_exercise',
            'steps' => ['提出开放问题', '记录一个明确需求', '用复述确认需求', '围绕确认后的需求给出下一步'],
            'success_criteria' => '建议与前序沟通内容存在清晰对应关系。',
        ],
    ],
    [
        'criterion_code' => 'no_fabricated_quote',
        'dimension_code' => 'solution_value',
        'knowledge_code' => 'new_sign_roleplay_truthful_expression',
        'knowledge_title' => '真实表达与边界说明',
        'resource_code' => 'new_sign_roleplay_truthful_expression_exercise',
        'resource_title' => '真实表达练习',
        'knowledge_content' => [
            'objective' => '只陈述已确认的沟通事实，不虚构家长表达、案例或效果。',
            'checklist' => ['区分已知事实和待确认信息', '不引用未出现的对话内容', '信息不足时明确提出确认问题'],
        ],
        'resource_content' => [
            'type' => 'guided_exercise',
            'steps' => ['标记本轮已经确认的信息', '将推测改写为确认问题', '删除无法追溯的案例和承诺'],
            'success_criteria' => '表达内容可回溯到当轮沟通或经批准的资料。',
        ],
    ],
];

function currentVersionId(PDO $pdo, string $table, string $parentTable, int $domainId, string $code): int
{
    $codeColumn = $table === 'drill_knowledge_point_versions' ? 'knowledge_code' : 'resource_code';
    $parentId = $table === 'drill_knowledge_point_versions' ? 'knowledge_point_id' : 'learning_resource_id';
    $stmt = $pdo->prepare(
        "SELECT version.id FROM {$table} version INNER JOIN {$parentTable} parent ON parent.id = version.{$parentId} "
        . "WHERE parent.domain_id = ? AND parent.{$codeColumn} = ? ORDER BY version.id DESC LIMIT 1"
    );
    $stmt->execute([$domainId, $code]);
    return (int) $stmt->fetchColumn();
}

function versionStatus(PDO $pdo, string $table, int $versionId): string
{
    $stmt = $pdo->prepare("SELECT status FROM {$table} WHERE id = ? LIMIT 1");
    $stmt->execute([$versionId]);
    return (string) $stmt->fetchColumn();
}

function publishResource(DrillLearningService $learning, PDO $pdo, int $versionId): void
{
    if (versionStatus($pdo, 'drill_learning_resource_versions', $versionId) === 'draft') {
        $learning->transitionLearningResourceVersion($versionId, 'submit_review', ACTOR_STAFF_ID);
    }
    if (versionStatus($pdo, 'drill_learning_resource_versions', $versionId) === 'review_pending') {
        $learning->transitionLearningResourceVersion($versionId, 'approve', ACTOR_STAFF_ID);
    }
}

function publishKnowledge(DrillLearningService $learning, PDO $pdo, int $versionId): void
{
    if (versionStatus($pdo, 'drill_knowledge_point_versions', $versionId) === 'draft') {
        $learning->transitionKnowledgePointVersion($versionId, 'submit_review', ACTOR_STAFF_ID);
    }
    if (versionStatus($pdo, 'drill_knowledge_point_versions', $versionId) === 'review_pending') {
        $result = $learning->transitionKnowledgePointVersion($versionId, 'approve', ACTOR_STAFF_ID);
        if (($result['publication_blocked'] ?? false) === true) {
            throw new RuntimeException('知识点发布被阻断：' . json_encode($result['failures'], JSON_UNESCAPED_UNICODE));
        }
    }
}

$links = [];
foreach ($foundation as $item) {
    $resourceVersionId = currentVersionId($pdo, 'drill_learning_resource_versions', 'drill_learning_resources', $domainId, $item['resource_code']);
    if ($resourceVersionId <= 0) {
        $resourceVersionId = (int) $learning->createLearningResourceDraft($domainId, [
            'resource_code' => $item['resource_code'],
            'name' => $item['resource_title'],
            'resource_type' => 'exercise',
            'title' => $item['resource_title'],
            'mobile_locator' => '/mobile/drill.html',
            'content' => $item['resource_content'],
            'estimated_minutes' => 5,
        ], ACTOR_STAFF_ID)['version_id'];
    }
    publishResource($learning, $pdo, $resourceVersionId);

    $knowledgeVersionId = currentVersionId($pdo, 'drill_knowledge_point_versions', 'drill_knowledge_points', $domainId, $item['knowledge_code']);
    if ($knowledgeVersionId <= 0) {
        $knowledgeVersionId = (int) $learning->createKnowledgePointDraft($domainId, [
            'knowledge_code' => $item['knowledge_code'],
            'name' => $item['knowledge_title'],
            'title' => $item['knowledge_title'],
            'description' => $item['knowledge_title'],
            'content' => $item['knowledge_content'],
        ], ACTOR_STAFF_ID)['version_id'];
    }
    $links[] = [
        'criterion_code' => $item['criterion_code'],
        'dimension_code' => $item['dimension_code'],
        'knowledge_point_version_id' => $knowledgeVersionId,
        'learning_resource_version_ids' => [$resourceVersionId],
        'is_primary' => true,
        'priority' => 100,
    ];
}

$mappingStmt = $pdo->prepare(
    "SELECT id FROM drill_knowledge_mapping_versions WHERE rubric_version_id = ? AND status IN ('draft', 'review_pending', 'published') ORDER BY version_no DESC LIMIT 1"
);
$mappingStmt->execute([$rubricVersionId]);
$mappingVersionId = (int) $mappingStmt->fetchColumn();
if ($mappingVersionId <= 0) {
    $mappingVersionId = (int) $learning->createMappingDraft($domainId, $rubricVersionId, $links, ACTOR_STAFF_ID)['mapping_version_id'];
}

foreach ($links as $link) {
    publishKnowledge($learning, $pdo, (int) $link['knowledge_point_version_id']);
}

$mappingStatusStmt = $pdo->prepare('SELECT status FROM drill_knowledge_mapping_versions WHERE id = ? LIMIT 1');
$mappingStatusStmt->execute([$mappingVersionId]);
$mappingStatus = (string) $mappingStatusStmt->fetchColumn();
if ($mappingStatus === 'draft') {
    $learning->transitionMappingVersion($mappingVersionId, 'submit_review', ACTOR_STAFF_ID);
    $mappingStatus = 'review_pending';
}
if ($mappingStatus === 'review_pending') {
    $result = $learning->transitionMappingVersion($mappingVersionId, 'approve', ACTOR_STAFF_ID);
    if (($result['publication_blocked'] ?? false) === true) {
        throw new RuntimeException('知识映射发布被阻断：' . json_encode($result['failures'], JSON_UNESCAPED_UNICODE));
    }
}

$rubric = new DrillRubricService($pdo);
$rubricStatusStmt = $pdo->prepare('SELECT status FROM drill_rubric_versions WHERE id = ? LIMIT 1');
$rubricStatusStmt->execute([$rubricVersionId]);
$rubricStatus = (string) $rubricStatusStmt->fetchColumn();
if ($rubricStatus === 'draft') {
    $rubric->transitionVersion($rubricVersionId, 'submit_review', ACTOR_STAFF_ID);
    $rubricStatus = 'in_review';
}
if ($rubricStatus === 'in_review') {
    $rubric->transitionVersion($rubricVersionId, 'approve', ACTOR_STAFF_ID);
}

print json_encode([
    'domain_id' => $domainId,
    'rubric_version_id' => $rubricVersionId,
    'mapping_version_id' => $mappingVersionId,
    'knowledge_points' => count($links),
    'resources' => count($links),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
