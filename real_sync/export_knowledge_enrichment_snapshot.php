<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(2);
}

require_once __DIR__ . '/../api/config.php';

$output = $argv[1] ?? '';
if ($output === '' || $output[0] !== '/') {
    fwrite(STDERR, "Usage: php scripts/export_knowledge_enrichment_snapshot.php /absolute/path/output.json\n");
    exit(2);
}

$db = getDB();
$sql = <<<'SQL'
SELECT
    k.id,
    k.item_code,
    k.content_type,
    k.domain_code,
    k.publication_status,
    k.current_version_id AS version_id,
    COALESCE(NULLIF(v.title, ''), k.title) AS title,
    COALESCE(NULLIF(v.summary, ''), k.summary) AS summary,
    COALESCE(NULLIF(v.content, ''), k.content, '') AS content,
    k.tags,
    k.risk_level
FROM knowledge_items k
INNER JOIN knowledge_item_versions v
    ON v.version_id = k.current_version_id
   AND v.knowledge_item_id = k.id
WHERE k.status = 1
  AND k.publication_status = 'published'
  AND v.status = 'active'
ORDER BY k.id ASC
SQL;
$rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as &$row) {
    $row['id'] = (int)$row['id'];
    $row['version_id'] = (int)$row['version_id'];
    $row['content_sha256'] = hash('sha256', (string)$row['content']);
}

$payload = [
    'schema_version' => 'knowledge-enrichment-source-snapshot.v1',
    'created_at' => gmdate('c'),
    'record_count' => count($rows),
    'records' => $rows,
];
$payload['snapshot_sha256'] = hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

if (file_put_contents($output, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL) === false) {
    throw new RuntimeException('无法写入快照');
}
echo json_encode(['record_count' => count($rows), 'snapshot_sha256' => $payload['snapshot_sha256']], JSON_UNESCAPED_UNICODE) . PHP_EOL;
