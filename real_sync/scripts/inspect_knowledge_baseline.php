<?php
/**
 * Read-only production knowledge-base baseline report.
 *
 * Usage: php inspect_knowledge_baseline.php [--output=path]
 * This script only reads information_schema and the allowlisted knowledge tables.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(2);
}

require_once __DIR__ . '/../api/config.php';

$allowedTables = [
    'knowledge_categories',
    'knowledge_items',
    'user_knowledge_progress',
    'drill_templates',
];

$output = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--output=')) {
        $output = substr($argument, 9);
    } elseif ($argument !== '--json') {
        fwrite(STDERR, "Unknown argument: {$argument}\n");
        exit(2);
    }
}

$pdo = getDB();
$report = [
    'schema_version' => 'knowledge-baseline.v1',
    'database' => DB_NAME,
    'read_only' => true,
    'tables' => [],
];

$columnStatement = $pdo->prepare(
    'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY, EXTRA '
    . 'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table '
    . 'ORDER BY ORDINAL_POSITION'
);
$tableStatement = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table'
);

foreach ($allowedTables as $table) {
    $tableStatement->execute(['schema' => DB_NAME, 'table' => $table]);
    $exists = (bool) $tableStatement->fetchColumn();
    $entry = ['exists' => $exists, 'columns' => []];
    if ($exists) {
        $columnStatement->execute(['schema' => DB_NAME, 'table' => $table]);
        $entry['columns'] = $columnStatement->fetchAll();
        $entry['row_count'] = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    }
    $report['tables'][$table] = $entry;
}

if (($report['tables']['knowledge_items']['exists'] ?? false) === true) {
    $report['knowledge_items_status_counts'] = $pdo->query(
        'SELECT status, is_public, COUNT(*) AS count FROM knowledge_items GROUP BY status, is_public ORDER BY status, is_public'
    )->fetchAll();
}
if (($report['tables']['user_knowledge_progress']['exists'] ?? false) === true) {
    $report['progress_pairs'] = $pdo->query(
        'SELECT COUNT(*) AS rows_count, COUNT(DISTINCT user_id) AS users_count, '
        . 'COUNT(DISTINCT knowledge_id) AS knowledge_count FROM user_knowledge_progress'
    )->fetch();
}

$payload = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
if ($output !== null) {
    $directory = dirname($output);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException("Cannot create output directory");
    }
    $temporary = tempnam($directory, '.knowledge-baseline-');
    if ($temporary === false || file_put_contents($temporary, $payload, LOCK_EX) === false) {
        throw new RuntimeException("Cannot write report");
    }
    chmod($temporary, 0600);
    if (!rename($temporary, $output)) {
        @unlink($temporary);
        throw new RuntimeException("Cannot publish report");
    }
} else {
    echo $payload;
}
