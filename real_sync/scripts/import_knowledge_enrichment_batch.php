<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(2);
}

require_once __DIR__ . '/../api/config.php';

$input = $argv[1] ?? '';
if ($input === '' || $input[0] !== '/') {
    fwrite(STDERR, "Usage: php scripts/import_knowledge_enrichment_batch.php /absolute/path/batch.json\n");
    exit(2);
}
$payload = json_decode((string)file_get_contents($input), true, 512, JSON_THROW_ON_ERROR);
if (($payload['schema_version'] ?? '') !== 'knowledge-enrichment-batch.v1' || !is_array($payload['tasks'] ?? null)) {
    throw new InvalidArgumentException('批次文件格式无效');
}

$db = getDB();
$db->beginTransaction();
try {
    $batch = $db->prepare('INSERT INTO knowledge_enrichment_batches (batch_id, scope, source_snapshot_sha256, cursor_offset, page_size, status, total_count, error_summary_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE source_snapshot_sha256 = VALUES(source_snapshot_sha256), total_count = VALUES(total_count), updated_at = CURRENT_TIMESTAMP');
    $batch->execute([(string)$payload['batch_id'], 'full_snapshot', (string)$payload['source_snapshot_sha256'], (int)($payload['offset'] ?? 0), (int)($payload['limit'] ?? 100), 'running', count($payload['tasks']), json_encode([], JSON_UNESCAPED_UNICODE)]);

    $insert = $db->prepare('INSERT INTO knowledge_enrichment_records (knowledge_item_id, source_version_id, source_content_sha256, content_type, task_type, missing_fields_json, risk_flags_json, enriched_content_json, enriched_content_sha256, enrichment_status, review_status, release_batch_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE source_version_id = VALUES(source_version_id), source_content_sha256 = VALUES(source_content_sha256), content_type = VALUES(content_type), task_type = VALUES(task_type), missing_fields_json = VALUES(missing_fields_json), risk_flags_json = VALUES(risk_flags_json), enriched_content_json = VALUES(enriched_content_json), enriched_content_sha256 = VALUES(enriched_content_sha256), enrichment_status = VALUES(enrichment_status), review_status = VALUES(review_status), release_batch_id = NULL, updated_at = CURRENT_TIMESTAMP');
    $counts = [];
    foreach ($payload['tasks'] as $task) {
        $status = (string)($task['enrichment_status'] ?? 'failed');
        $content = isset($task['enriched_content']) ? canonicalJson($task['enriched_content']) : null;
        $insert->execute([
            (int)$task['knowledge_item_id'],
            (int)$task['source_version_id'],
            (string)$task['source_content_sha256'],
            (string)($task['content_type'] ?? ''),
            (string)$task['task_type'],
            json_encode($task['missing_fields'] ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($task['risk_flags'] ?? [], JSON_UNESCAPED_UNICODE),
            $content,
            $content === null ? null : hash('sha256', $content),
            $status,
            'pending',
            null,
        ]);
        $counts[$status] = ($counts[$status] ?? 0) + 1;
    }
    $update = $db->prepare('UPDATE knowledge_enrichment_batches SET status = ?, processed_count = ?, success_count = ?, failed_count = ?, skipped_count = ?, completed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE batch_id = ?');
    $update->execute(['completed', count($payload['tasks']), ($counts['draft'] ?? 0) + ($counts['needs_review'] ?? 0), $counts['failed'] ?? 0, $counts['skipped'] ?? 0, (string)$payload['batch_id']]);
    $db->commit();
    echo json_encode(['batch_id' => $payload['batch_id'], 'total_count' => count($payload['tasks']), 'counts' => $counts], JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
}

function canonicalJson(mixed $value): string
{
    if (is_array($value)) {
        if (array_keys($value) !== range(0, count($value) - 1)) ksort($value);
        foreach ($value as $key => $child) $value[$key] = json_decode(canonicalJson($child), true, 512, JSON_THROW_ON_ERROR);
    }
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
