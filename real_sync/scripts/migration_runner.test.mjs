import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const root = new URL('..', import.meta.url);
const hasPhpSqlite = spawnSync('php', ['-r', 'exit(extension_loaded("pdo_sqlite") ? 0 : 1);']).status === 0;
const runner = readFileSync(new URL('../database/MigrationRunner.php', import.meta.url), 'utf8');
const cli = readFileSync(new URL('./migrate.php', import.meta.url), 'utf8');
const manifest = readFileSync(new URL('../database/migration_manifest.php', import.meta.url), 'utf8');
const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;

function runPhp(source) {
  const result = spawnSync('php', ['-d', 'display_errors=1', '-r', source], {
    cwd: root,
    encoding: 'utf8',
    timeout: 10_000,
  });
  assert.equal(result.status, 0, result.stderr);
  return JSON.parse(result.stdout);
}

test('runner records version, checksum, status, and execution timestamps', () => {
  assert.match(runner, /CREATE TABLE IF NOT EXISTS schema_migrations/);
  for (const field of ['version', 'migration_name', 'checksum', 'status', 'started_at', 'applied_at']) {
    assert.match(runner, new RegExp(`\\b${field}\\b`));
  }
  assert.match(runner, /Applied migration checksum changed/);
});

test('runner consumes statement result sets before executing the next migration statement', () => {
  assert.match(runner, /executeStatement\(\$statement\)/);
  assert.match(runner, /columnCount\(\) > 0/);
  assert.match(runner, /fetchAll\(PDO::FETCH_NUM\)/);
  assert.match(runner, /closeCursor\(\)/);
});

test('runner applies version-scoped compatibility fixes to historical migrations', { skip: !hasPhpSqlite }, () => {
  const output = runPhp(String.raw`
    require 'database/MigrationRunner.php';
    $db = new PDO('sqlite::memory:');
    $runner = new MigrationRunner($db, '.', []);
    $method = new ReflectionMethod(MigrationRunner::class, 'executableSql');
    echo json_encode([
      'organization_import' => $method->invoke($runner, [
        'version' => '202607240001',
        'sql' => 'CREATE TABLE rows (row_number INT, KEY idx_row (row_number));',
      ]),
      'workload_import' => $method->invoke($runner, [
        'version' => '202607240009',
        'sql' => 'CREATE TABLE rows (row_number INT, KEY idx_row (row_number));',
      ]),
      'current' => $method->invoke($runner, [
        'version' => '202609040001',
        'sql' => 'SELECT row_number FROM rows;',
      ]),
      'hire_conversion' => $method->invoke($runner, [
        'version' => '202608020002',
        'sql' => 'CREATE TABLE conversions (employee_staff_id INT UNSIGNED NULL);',
      ]),
      'unrelated_staff_column' => $method->invoke($runner, [
        'version' => '202609040001',
        'sql' => 'CREATE TABLE conversions (employee_staff_id INT UNSIGNED NULL);',
      ]),
      'classification_review' => $method->invoke($runner, [
        'version' => '202608100001',
        'sql' => 'CREATE TABLE reviews (reviewer_staff_id INT UNSIGNED NOT NULL);',
      ]),
      'unrelated_reviewer_column' => $method->invoke($runner, [
        'version' => '202609040001',
        'sql' => 'CREATE TABLE reviews (reviewer_staff_id INT UNSIGNED NOT NULL);',
      ]),
    ]);
  `);

  assert.equal(output.organization_import, 'CREATE TABLE rows (`row_number` INT, KEY idx_row (`row_number`));');
  assert.equal(output.workload_import, 'CREATE TABLE rows (`row_number` INT, KEY idx_row (`row_number`));');
  assert.equal(output.current, 'SELECT row_number FROM rows;');
  assert.equal(output.hire_conversion, 'CREATE TABLE conversions (employee_staff_id BIGINT UNSIGNED NULL);');
  assert.equal(output.unrelated_staff_column, 'CREATE TABLE conversions (employee_staff_id INT UNSIGNED NULL);');
  assert.equal(output.classification_review, 'CREATE TABLE reviews (reviewer_staff_id BIGINT UNSIGNED NOT NULL);');
  assert.equal(output.unrelated_reviewer_column, 'CREATE TABLE reviews (reviewer_staff_id INT UNSIGNED NOT NULL);');
});

test('runner reports structure and row count differences', () => {
  assert.match(runner, /'structure_diff' =>/);
  assert.match(runner, /'count_diff' =>/);
  assert.match(runner, /information_schema\.COLUMNS/);
  assert.match(runner, /information_schema\.STATISTICS/);
  assert.match(runner, /SELECT COUNT\(\*\) FROM/);
});

test('dry-run reads migration history without changing the database', { skip: !hasPhpSqlite }, () => {
  const output = runPhp(String.raw`
    require 'database/MigrationRunner.php';

    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->sqliteCreateFunction('DATABASE', static fn(): string => 'main');
    $db->exec("ATTACH DATABASE ':memory:' AS information_schema");
    $db->exec('CREATE TABLE information_schema.TABLES (TABLE_SCHEMA TEXT, TABLE_NAME TEXT)');
    $db->exec('CREATE TABLE information_schema.COLUMNS (TABLE_SCHEMA TEXT, TABLE_NAME TEXT, COLUMN_NAME TEXT, COLUMN_TYPE TEXT, IS_NULLABLE TEXT, COLUMN_DEFAULT TEXT, ORDINAL_POSITION INTEGER)');
    $db->exec('CREATE TABLE information_schema.STATISTICS (TABLE_SCHEMA TEXT, TABLE_NAME TEXT, INDEX_NAME TEXT, NON_UNIQUE INTEGER, SEQ_IN_INDEX INTEGER, COLUMN_NAME TEXT)');
    $db->exec('CREATE TABLE baseline_rows (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
    $db->exec("INSERT INTO baseline_rows (id, value) VALUES (1, 'kept')");
    $db->exec("INSERT INTO information_schema.TABLES VALUES ('main', 'baseline_rows')");
    $db->exec("INSERT INTO information_schema.COLUMNS VALUES ('main', 'baseline_rows', 'id', 'INTEGER', 'NO', NULL, 1)");
    $db->exec("INSERT INTO information_schema.COLUMNS VALUES ('main', 'baseline_rows', 'value', 'TEXT', 'NO', NULL, 2)");
    $db->exec("INSERT INTO information_schema.STATISTICS VALUES ('main', 'baseline_rows', 'PRIMARY', 0, 1, 'id')");

    $migrationPath = 'scripts/fixtures/migration-runner/209901010001_create_dry_run_probe.sql';
    $manifest = ['209901010001' => ['sql_checksum' => hash_file('sha256', $migrationPath)]];
    $beforeRows = $db->query('SELECT id, value FROM baseline_rows ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $result = (new MigrationRunner($db, dirname($migrationPath), $manifest))->apply(true);
    $afterRows = $db->query('SELECT id, value FROM baseline_rows ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $historyTableCount = (int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'schema_migrations'")->fetchColumn();

    $db->exec('CREATE TABLE schema_migrations (version TEXT PRIMARY KEY, migration_name TEXT, checksum TEXT, status TEXT, error_message TEXT, started_at TEXT, applied_at TEXT)');
    $db->exec("INSERT INTO information_schema.TABLES VALUES ('main', 'schema_migrations')");
    $historyStatement = $db->prepare("INSERT INTO schema_migrations VALUES (?, ?, ?, 'applied', NULL, '2099-01-01 00:00:00', '2099-01-01 00:00:01')");
    $historyStatement->execute(['209901010001', basename($migrationPath), $manifest['209901010001']['sql_checksum']]);
    $presentResult = (new MigrationRunner($db, dirname($migrationPath), $manifest))->apply(true);

    echo json_encode([
      'result' => $result,
      'present_result' => $presentResult,
      'before_rows' => $beforeRows,
      'after_rows' => $afterRows,
      'history_table_count' => $historyTableCount,
    ]);
  `);

  assert.equal(output.result.history_table_state, 'history_table_absent');
  assert.deepEqual(output.result.migrations, [{ version: '209901010001', status: 'would_apply' }]);
  assert.deepEqual(output.result.snapshots.before, output.result.snapshots.after);
  assert.deepEqual(output.result.structure_diff, { added_tables: [], added_columns: [], added_indexes: [] });
  assert.deepEqual(output.result.count_diff, []);
  assert.deepEqual(output.before_rows, output.after_rows);
  assert.equal(output.history_table_count, 0);
  assert.equal(output.present_result.history_table_state, 'history_table_present');
  assert.deepEqual(output.present_result.migrations, [{ version: '209901010001', status: 'already_applied' }]);
  assert.deepEqual(output.present_result.snapshots.before, output.present_result.snapshots.after);
});

test(`${validatesCriteria(['5.1', '5.2', 'Property 5'])} arbitrary dry-run baselines preserve complete schema and row values`, { skip: !hasPhpSqlite }, () => {
  const output = runPhp(String.raw`
    require 'database/MigrationRunner.php';

    $results = [];
    for ($seed = 1; $seed <= 18; $seed++) {
      $db = new PDO('sqlite::memory:');
      $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
      $db->sqliteCreateFunction('DATABASE', static fn(): string => 'main');
      $db->exec("ATTACH DATABASE ':memory:' AS information_schema");
      $db->exec('CREATE TABLE information_schema.TABLES (TABLE_SCHEMA TEXT, TABLE_NAME TEXT)');
      $db->exec('CREATE TABLE information_schema.COLUMNS (TABLE_SCHEMA TEXT, TABLE_NAME TEXT, COLUMN_NAME TEXT, COLUMN_TYPE TEXT, IS_NULLABLE TEXT, COLUMN_DEFAULT TEXT, ORDINAL_POSITION INTEGER)');
      $db->exec('CREATE TABLE information_schema.STATISTICS (TABLE_SCHEMA TEXT, TABLE_NAME TEXT, INDEX_NAME TEXT, NON_UNIQUE INTEGER, SEQ_IN_INDEX INTEGER, COLUMN_NAME TEXT)');

      $tableCount = 1 + ($seed % 4);
      for ($tableIndex = 1; $tableIndex <= $tableCount; $tableIndex++) {
        $table = 'baseline_' . $tableIndex;
        $db->exec('CREATE TABLE ' . $table . ' (id INTEGER PRIMARY KEY, value TEXT NOT NULL, state TEXT NULL)');
        $db->exec('CREATE INDEX idx_' . $table . '_state ON ' . $table . ' (state)');
        $db->exec("INSERT INTO information_schema.TABLES VALUES ('main', '" . $table . "')");
        $db->exec("INSERT INTO information_schema.COLUMNS VALUES ('main', '" . $table . "', 'id', 'INTEGER', 'NO', NULL, 1)");
        $db->exec("INSERT INTO information_schema.COLUMNS VALUES ('main', '" . $table . "', 'value', 'TEXT', 'NO', NULL, 2)");
        $db->exec("INSERT INTO information_schema.COLUMNS VALUES ('main', '" . $table . "', 'state', 'TEXT', 'YES', NULL, 3)");
        $db->exec("INSERT INTO information_schema.STATISTICS VALUES ('main', '" . $table . "', 'PRIMARY', 0, 1, 'id')");
        $db->exec("INSERT INTO information_schema.STATISTICS VALUES ('main', '" . $table . "', 'idx_" . $table . "_state', 1, 1, 'state')");
        $rowCount = ($seed * $tableIndex) % 6;
        $insert = $db->prepare('INSERT INTO ' . $table . ' (id, value, state) VALUES (?, ?, ?)');
        for ($row = 1; $row <= $rowCount; $row++) {
          $insert->execute([$row, 'seed-' . $seed . '-row-' . $row, $row % 2 === 0 ? 'active' : null]);
        }
      }

      $migrationPath = 'scripts/fixtures/migration-runner/209901010001_create_dry_run_probe.sql';
      $checksum = hash_file('sha256', $migrationPath);
      $manifest = ['209901010001' => ['sql_checksum' => $checksum]];
      $historyPresent = $seed % 3 !== 0;
      if ($historyPresent) {
        $db->exec('CREATE TABLE schema_migrations (version TEXT PRIMARY KEY, migration_name TEXT, checksum TEXT, status TEXT, error_message TEXT, started_at TEXT, applied_at TEXT)');
        $db->exec("INSERT INTO information_schema.TABLES VALUES ('main', 'schema_migrations')");
        $db->exec("INSERT INTO information_schema.COLUMNS VALUES ('main', 'schema_migrations', 'version', 'TEXT', 'NO', NULL, 1)");
        if ($seed % 2 === 0) {
          $history = $db->prepare("INSERT INTO schema_migrations VALUES (?, ?, ?, 'applied', NULL, '2099-01-01 00:00:00', '2099-01-01 00:00:01')");
          $history->execute(['209901010001', basename($migrationPath), $checksum]);
        }
      }

      $capture = static function (PDO $db): array {
        $schema = $db->query("SELECT name, type, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name")->fetchAll(PDO::FETCH_ASSOC);
        $rows = [];
        $tables = $db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
          $quoted = '"' . str_replace('"', '""', (string)$table) . '"';
          $rows[$table] = $db->query('SELECT * FROM ' . $quoted . ' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
        }
        return ['schema' => $schema, 'rows' => $rows];
      };

      $before = $capture($db);
      $result = (new MigrationRunner($db, dirname($migrationPath), $manifest))->apply(true);
      $after = $capture($db);
      $results[] = [
        'seed' => $seed,
        'before' => $before,
        'after' => $after,
        'runner_snapshots_equal' => $result['snapshots']['before'] === $result['snapshots']['after'],
        'structure_diff' => $result['structure_diff'],
        'count_diff' => $result['count_diff'],
        'history_state' => $result['history_table_state'],
        'expected_history_state' => $historyPresent ? 'history_table_present' : 'history_table_absent',
      ];
    }
    echo json_encode($results);
  `);

  assert.equal(output.length, 18);
  for (const sample of output) {
    assert.deepEqual(sample.after.schema, sample.before.schema, `seed ${sample.seed} changed schema`);
    assert.deepEqual(sample.after.rows, sample.before.rows, `seed ${sample.seed} changed row values`);
    assert.equal(sample.runner_snapshots_equal, true, `seed ${sample.seed} changed runner snapshots`);
    assert.deepEqual(sample.structure_diff, { added_tables: [], added_columns: [], added_indexes: [] }, `seed ${sample.seed}`);
    assert.deepEqual(sample.count_diff, [], `seed ${sample.seed}`);
    assert.equal(sample.history_state, sample.expected_history_state, `seed ${sample.seed}`);
  }
});

test('verifier checks manifest tables, columns, indexes, and checksums', () => {
  assert.match(runner, /missing_table/);
  assert.match(runner, /missing_column/);
  assert.match(runner, /missing_index/);
  assert.match(runner, /checksum_mismatch/);
  assert.match(manifest, /'202607240001'/);
  assert.match(manifest, /'202607240002'/);
  assert.match(manifest, /'202607240003'/);
  assert.match(manifest, /\['uq_staffs_employee_no', 'uk_employee_no'\]/);
  assert.match(runner, /is_array\(\$index\) \? \$index : \[\$index\]/);
  assert.match(runner, /implode\('\|', \$alternatives\)/);
});

test('CLI supports apply, dry-run, status, compatibility, readiness, verify, and preserving rollback plan', () => {
  for (const command of ['apply', 'status', 'compatibility', 'readiness', 'verify', 'rollback-plan', '--dry-run']) {
    assert.match(cli, new RegExp(command.replace('-', '\\-')));
  }
  assert.match(runner, /'strategy' => 'preserving'/);
  assert.match(cli, /verifyData\(\)/);
  assert.match(cli, /ExpandMigrateContractValidator/);
  assert.doesNotMatch(runner, /\bDROP\s+(?:TABLE|COLUMN|INDEX)\b/i);
  assert.doesNotMatch(runner, /\bDELETE\s+FROM\b/i);
});
