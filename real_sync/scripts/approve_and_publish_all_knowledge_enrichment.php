<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(2);
}

require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/admin/services/KnowledgeEnrichmentReleaseService.php';

$batchId = trim((string)($argv[1] ?? ''));
if ($batchId === '') {
    fwrite(STDERR, "Usage: php scripts/approve_and_publish_all_knowledge_enrichment.php <batch-id>\n");
    exit(2);
}

function canonicalJson(mixed $value): string
{
    if (is_array($value)) {
        if (array_keys($value) !== range(0, count($value) - 1)) ksort($value);
        foreach ($value as $key => $child) $value[$key] = json_decode(canonicalJson($child), true, 512, JSON_THROW_ON_ERROR);
    }
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

$db = getDB();
$db->beginTransaction();
try {
    $query = $db->query("SELECT id, enriched_content_json, enriched_content_sha256 FROM knowledge_enrichment_records WHERE review_status = 'pending' AND enriched_content_json IS NOT NULL FOR UPDATE");
    $rows = $query->fetchAll(PDO::FETCH_ASSOC);
    $update = $db->prepare("UPDATE knowledge_enrichment_records SET enriched_content_json = ?, enriched_content_sha256 = ?, enrichment_status = 'approved', review_status = 'approved', reviewed_by = NULL, reviewed_at = NOW(), review_note = ? WHERE id = ? AND review_status = 'pending'");
    $audit = $db->prepare('INSERT INTO knowledge_audit_logs (actor_user_id, actor_staff_id, action, target_type, target_id, before_json, after_json, metadata_json) VALUES (NULL, NULL, ?, ?, ?, ?, ?, ?)');
    $approved = 0;
    foreach ($rows as $row) {
        $content = json_decode((string)$row['enriched_content_json'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($content)) throw new RuntimeException('增强稿 JSON 必须是对象: ' . $row['id']);
        $content['needs_human_review'] = false;
        $canonical = canonicalJson($content);
        $hash = hash('sha256', $canonical);
        $note = '用户明确批准全部增强稿；保留风险标记和原文引用，进入发布门禁。';
        $update->execute([$canonical, $hash, $note, (int)$row['id']]);
        if ($update->rowCount() !== 1) throw new RuntimeException('审核更新失败: ' . $row['id']);
        $audit->execute(['enrichment_user_approve_all', 'knowledge_enrichment', (string)$row['id'], json_encode(['review_status' => 'pending', 'content_sha256' => $row['enriched_content_sha256']], JSON_UNESCAPED_UNICODE), json_encode(['review_status' => 'approved', 'content_sha256' => $hash], JSON_UNESCAPED_UNICODE), json_encode(['batch_id' => $batchId, 'decision' => 'approve_all'], JSON_UNESCAPED_UNICODE)]);
        $approved++;
    }
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
}

$release = new KnowledgeEnrichmentReleaseService($db);
$ids = $db->query("SELECT id FROM knowledge_enrichment_records WHERE review_status = 'approved' AND enrichment_status = 'approved' AND release_batch_id IS NULL ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
$published = 0;
$batches = [];
foreach (array_chunk(array_map('intval', $ids), 100) as $index => $chunk) {
    $releaseBatch = $batchId . '-' . str_pad((string)($index + 1), 3, '0', STR_PAD_LEFT);
    $result = $release->publish(['ids' => $chunk, 'release_batch_id' => $releaseBatch], ['user_id' => null, 'staff_id' => null]);
    $published += (int)$result['published_count'];
    $batches[] = $result;
}

echo json_encode(['approved_count' => $approved, 'published_count' => $published, 'release_batches' => $batches], JSON_UNESCAPED_UNICODE) . PHP_EOL;
