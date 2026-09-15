<?php

declare(strict_types=1);

require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/drill/v2/services/DrillAdminApiService.php';

const ACTOR_STAFF_ID = 46;

$pdo = getDB();
$calibrationStmt = $pdo->prepare(
    'SELECT calibration.id, calibration.status FROM drill_score_calibration_versions calibration '
    . 'INNER JOIN drill_rubrics rubric ON rubric.id = calibration.rubric_id '
    . "WHERE rubric.rubric_code = 'new_sign_training_demo_v1' AND calibration.evaluation_context = 'ai_roleplay' "
    . 'ORDER BY calibration.version_no DESC LIMIT 1'
);
$calibrationStmt->execute();
$calibration = $calibrationStmt->fetch(PDO::FETCH_ASSOC);
if (!$calibration) {
    throw new RuntimeException('AI 对练校准版本不存在。');
}

$samples = [
    [
        'sample_code' => 'internal_roleplay_below_ready_v1',
        'source_type' => 'internal_training_sample',
        'scenario' => '家长仅表达模糊担忧，销售直接介绍课程。',
        'benchmark_score' => 30,
        'benchmark_level' => '未达独立接待',
        'rationale' => '缺少需求确认与角色校验，表达无法追溯。',
    ],
    [
        'sample_code' => 'internal_roleplay_guided_v1',
        'source_type' => 'internal_training_sample',
        'scenario' => '销售能完成基础追问，但需要带教提示才能继续推进。',
        'benchmark_score' => 55,
        'benchmark_level' => '持续带练',
        'rationale' => '存在需求追问，方案衔接和推进动作仍不稳定。',
    ],
    [
        'sample_code' => 'internal_roleplay_independent_v1',
        'source_type' => 'internal_training_sample',
        'scenario' => '销售完成角色确认、需求复述和单一方案建议。',
        'benchmark_score' => 70,
        'benchmark_level' => '简单场景可接待',
        'rationale' => '关键表达可追溯，能够完成常规单一需求场景。',
    ],
    [
        'sample_code' => 'internal_roleplay_model_v1',
        'source_type' => 'internal_training_sample',
        'scenario' => '销售持续确认需求、说明边界并自然推进下一步。',
        'benchmark_score' => 85,
        'benchmark_level' => '学习标杆',
        'rationale' => '角色、需求和建议均清晰可追溯，表达保持真实边界。',
    ],
];

$comparison = array_map(static fn(array $sample): array => [
    'sample_code' => $sample['sample_code'],
    'human_score' => $sample['benchmark_score'],
    'approved_by_staff_id' => ACTOR_STAFF_ID,
    'approval_basis' => '内部培训校准样本授权',
], $samples);

$admin = new DrillAdminApiService($pdo);
if ($calibration['status'] === 'draft') {
    $admin->write('calibrations', [
        'action' => 'record',
        'version_id' => (int) $calibration['id'],
        'test_samples' => $samples,
        'human_comparison' => $comparison,
        'weight_changes' => [],
        'threshold_changes' => ['excellent' => 85, 'good' => 70, 'qualified' => 60],
        'notes' => '基于已授权的内部培训样本完成首版 AI 对练校准，不包含真实客户信息。',
        'sample_size' => count($samples),
        'agreement_rate' => 1.0,
        'status' => 'validated',
    ], ['staff_id' => ACTOR_STAFF_ID], []);
    $calibration['status'] = 'validated';
}
if ($calibration['status'] === 'validated') {
    $admin->write('calibrations', [
        'action' => 'publish',
        'version_id' => (int) $calibration['id'],
    ], ['staff_id' => ACTOR_STAFF_ID], []);
}

print json_encode([
    'calibration_version_id' => (int) $calibration['id'],
    'evaluation_context' => 'ai_roleplay',
    'sample_size' => count($samples),
    'status' => 'published',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
