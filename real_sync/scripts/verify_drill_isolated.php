<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/config.php';

$manifest = require __DIR__ . '/../database/migration_manifest.php';
$versions = [
    '202607270001',
    '202607270002',
    '202607270003',
    '202607270004',
    '202607270005',
    '202607270006',
    '202607270007',
    '202607270008',
    '202607280005',
    '202607280006',
    '202607280007',
    '202607280008',
];

$pdo = getDB();
$schema = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
$tables = array_fill_keys($pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchAll(PDO::FETCH_COLUMN), true);
$columns = [];
foreach ($pdo->query('SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = DATABASE()')->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $columns[(string) $row['table_name']][(string) $row['column_name']] = true;
}
$indexes = [];
foreach ($pdo->query('SELECT table_name, index_name FROM information_schema.statistics WHERE table_schema = DATABASE()')->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $indexes[(string) $row['table_name']][(string) $row['index_name']] = true;
}

$errors = [];
foreach ($versions as $version) {
    $expectation = $manifest[$version] ?? [];
    foreach ($expectation['tables'] ?? [] as $table) {
        if (!isset($tables[$table])) {
            $errors[] = $version . ': missing table ' . $table;
        }
    }
    foreach ($expectation['columns'] ?? [] as $table => $requiredColumns) {
        foreach ($requiredColumns as $column) {
            if (!isset($columns[$table][$column])) {
                $errors[] = $version . ': missing column ' . $table . '.' . $column;
            }
        }
    }
    foreach ($expectation['indexes'] ?? [] as $table => $requiredIndexes) {
        foreach ($requiredIndexes as $index) {
            $alternatives = is_array($index) ? $index : [$index];
            $present = array_filter($alternatives, static fn(string $name): bool => isset($indexes[$table][$name]));
            if ($present === []) {
                $errors[] = $version . ': missing index ' . $table . '.' . implode('|', $alternatives);
            }
        }
    }
}

$result = [
    'ok' => $errors === [],
    'schema' => $schema,
    'versions' => $versions,
    'checked_tables' => count($tables),
    'errors' => $errors,
];
echo json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($errors === [] ? 0 : 1);
