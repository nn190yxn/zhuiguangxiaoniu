<?php
declare(strict_types=1);

require_once __DIR__ . '/../database/MigrationRunner.php';
require_once __DIR__ . '/../database/MigrationReadiness.php';

const MIGRATION_HARNESS_CONFIRMATION = 'ALLOW_MIGRATION_HARNESS';

function migrationTestConfig(): array
{
    $config = [];
    foreach (['TEST_DB_HOST', 'TEST_DB_NAME', 'TEST_DB_USER', 'TEST_DB_PASSWORD', 'TEST_DB_CONFIRM'] as $key) {
        $value = getenv($key);
        if ($value === false || trim($value) === '') {
            throw new RuntimeException($key . ' is required');
        }
        $config[$key] = trim($value);
    }
    if (preg_match('/^mc_migration_test_[a-z0-9_]+$/', $config['TEST_DB_NAME']) !== 1) {
        throw new RuntimeException('TEST_DB_NAME must match mc_migration_test_[a-z0-9_]+');
    }
    if (!hash_equals(MIGRATION_HARNESS_CONFIRMATION, $config['TEST_DB_CONFIRM'])) {
        throw new RuntimeException('TEST_DB_CONFIRM must equal ' . MIGRATION_HARNESS_CONFIRMATION);
    }
    $port = getenv('TEST_DB_PORT');
    $config['TEST_DB_PORT'] = $port === false || trim($port) === '' ? 3306 : (int)$port;
    if ($config['TEST_DB_PORT'] < 1 || $config['TEST_DB_PORT'] > 65535) {
        throw new RuntimeException('TEST_DB_PORT must be a valid TCP port');
    }
    return $config;
}

function connectMigrationTestDb(array $config): PDO
{
    return new PDO(
        'mysql:host=' . $config['TEST_DB_HOST'] . ';port=' . $config['TEST_DB_PORT']
        . ';dbname=' . $config['TEST_DB_NAME'] . ';charset=utf8mb4',
        $config['TEST_DB_USER'],
        $config['TEST_DB_PASSWORD'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function assertEmptyTargetDatabase(PDO $db): void
{
    $count = (int)$db->query(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE IN ('BASE TABLE', 'VIEW')"
    )->fetchColumn();
    if ($count !== 0) {
        throw new RuntimeException('Migration harness target database must be empty');
    }
}

function executeBaseline(PDO $db, MigrationRunner $runner): void
{
    $path = __DIR__ . '/fixtures/migration-mysql/baseline.sql';
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Unable to read baseline.sql');
    }
    foreach ($runner->splitSqlStatements($sql) as $statement) {
        $db->exec($statement);
    }
}

function databaseFingerprint(PDO $db): string
{
    $tables = $db->query(
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"
    )->fetchAll(PDO::FETCH_COLUMN);
    $snapshot = [];
    foreach ($tables as $table) {
        $quoted = '`' . str_replace('`', '``', (string)$table) . '`';
        $create = $db->query('SHOW CREATE TABLE ' . $quoted)->fetch(PDO::FETCH_NUM);
        $count = (int)$db->query('SELECT COUNT(*) FROM ' . $quoted)->fetchColumn();
        $checksum = $db->query('CHECKSUM TABLE ' . $quoted)->fetch(PDO::FETCH_ASSOC);
        $snapshot[(string)$table] = [
            'create' => $create[1] ?? null,
            'count' => $count,
            'checksum' => $checksum['Checksum'] ?? null,
        ];
    }
    return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
}

function assertSameFingerprint(string $before, string $after): void
{
    if (!hash_equals($before, $after)) {
        throw new RuntimeException('Dry-run changed database structure or data');
    }
}

function scalar(PDO $db, string $sql): int
{
    return (int)$db->query($sql)->fetchColumn();
}

function assertKeyData(PDO $db): array
{
    $checks = [
        'staff_organization_backfill' => "SELECT COUNT(*) FROM staffs WHERE employee_no = 'migration-baseline-staff' AND lifecycle_status = 'active' AND primary_position_id IS NOT NULL",
        'store_code_backfill' => "SELECT COUNT(*) FROM stores WHERE id = 1 AND store_code = 'STORE-0001'",
        'staff_assignment_backfill' => 'SELECT COUNT(*) FROM staff_assignments WHERE staff_id = 1 AND store_id = 1',
        'workload_version_backfill' => "SELECT COUNT(*) FROM workload_daily_reports WHERE remarks = 'migration-baseline-report' AND metric_version_id IS NOT NULL AND rule_version_id IS NOT NULL",
        'workload_obligation_backfill' => "SELECT COUNT(*) FROM workload_submission_obligations WHERE report_id = 1 AND source = 'backfill'",
        'knowledge_current_version' => 'SELECT COUNT(*) FROM knowledge_items item JOIN knowledge_item_versions version ON version.version_id = item.current_version_id AND version.knowledge_item_id = item.id WHERE item.id = 1',
        'lesson_knowledge_version_backfill' => "SELECT COUNT(*) FROM lesson_suggestions WHERE source_type = 'knowledge_card' AND knowledge_item_id = 1 AND knowledge_version_id = 1",
        'lesson_library_columns' => "SELECT COUNT(*) = 3 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lesson_submissions' AND COLUMN_NAME IN ('library_status', 'library_published_at', 'library_published_by_staff_id')",
        'points_business_date_backfill' => "SELECT COUNT(*) FROM points_records WHERE id = 1 AND business_date = '2026-09-01'",
        'learning_state_version' => 'SELECT COUNT(*) FROM user_course_progress WHERE id = 1 AND state_version = 1',
    ];
    $results = [];
    foreach ($checks as $name => $sql) {
        $results[$name] = scalar($db, $sql) === 1;
    }
    if (in_array(false, $results, true)) {
        throw new RuntimeException('Key migration data assertion failed: ' . json_encode($results, JSON_THROW_ON_ERROR));
    }
    return ['ok' => true, 'checks' => $results];
}

function assertForeignKeys(PDO $db): array
{
    $expected = [
        'fk_knowledge_items_current_version_pair',
        'fk_lesson_submissions_current_version_pair',
        'fk_lesson_submissions_approved_version_pair',
        'fk_lesson_suggestions_version_pair',
        'fk_lesson_suggestions_knowledge_version_pair',
        'fk_lesson_review_tasks_version_pair',
        'fk_lesson_exports_version_pair',
        'fk_lesson_audit_logs_version_pair',
    ];
    $placeholders = implode(',', array_fill(0, count($expected), '?'));
    $statement = $db->prepare(
        'SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS'
        . ' WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME IN (' . $placeholders . ')'
    );
    $statement->execute($expected);
    $actual = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    $missing = array_values(array_diff($expected, $actual));
    if ($missing !== []) {
        throw new RuntimeException('Expected foreign keys are missing: ' . implode(', ', $missing));
    }
    return ['ok' => true, 'checked' => $expected];
}

function countMigrationStatus(array $result, string $status): int
{
    return count(array_filter(
        $result['migrations'] ?? [],
        static fn(array $migration): bool => ($migration['status'] ?? '') === $status
    ));
}

try {
    $config = migrationTestConfig();
    $db = connectMigrationTestDb($config);
    assertEmptyTargetDatabase($db);

    $catalog = require __DIR__ . '/../database/migration_catalog.php';
    $manifest = [];
    foreach ($catalog as $version => $entry) {
        $manifest[$version] = [
            'sql_checksum' => $entry['sql_checksum'],
            'tables' => $entry['tables'],
            'columns' => $entry['columns'],
            'indexes' => $entry['indexes'],
        ];
    }
    $runner = new MigrationRunner($db, __DIR__ . '/../database/migrations', $manifest);
    $readiness = new MigrationReadiness(new PdoMigrationReadinessDatabase($db), $catalog);

    executeBaseline($db, $runner);
    $beforeDryRun = databaseFingerprint($db);
    $dryRun = $runner->apply(true);
    $afterDryRun = databaseFingerprint($db);
    assertSameFingerprint($beforeDryRun, $afterDryRun);

    $apply = $runner->apply(false);
    $verification = $runner->verify();
    $structureReadiness = $readiness->check();
    $dataReadiness = $readiness->verifyData();
    if (!$verification['ok'] || !$structureReadiness['ready'] || !$dataReadiness['ready']) {
        throw new RuntimeException('Migration verification or readiness failed');
    }

    $keyData = assertKeyData($db);
    $foreignKeys = assertForeignKeys($db);
    $replay = $runner->apply(false);
    $migrationCount = count($catalog);
    $appliedCount = countMigrationStatus($apply, 'applied');
    $replayedCount = countMigrationStatus($replay, 'already_applied');
    if ($appliedCount !== $migrationCount || $replayedCount !== $migrationCount) {
        throw new RuntimeException('Migration apply or replay count mismatch');
    }

    echo json_encode([
        'ok' => true,
        'database_classification' => 'dedicated_migration_test',
        'migration_count' => $migrationCount,
        'dry_run' => [
            'would_apply' => countMigrationStatus($dryRun, 'would_apply'),
            'unchanged' => true,
        ],
        'apply' => ['applied' => $appliedCount],
        'verification' => $verification,
        'readiness' => [
            'structure_ready' => $structureReadiness['ready'],
            'data_ready' => $dataReadiness['ready'],
        ],
        'replay' => ['already_applied' => $replayedCount],
        'key_data' => $keyData,
        'foreign_keys' => $foreignKeys,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
