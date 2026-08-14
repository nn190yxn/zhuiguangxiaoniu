import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { test } from 'node:test';

const root = new URL('..', import.meta.url);
const hasPhp = spawnSync('php', ['-v'], { encoding: 'utf8' }).status === 0;
const readinessSource = readFileSync(new URL('../database/MigrationReadiness.php', import.meta.url), 'utf8');

function runPhp(source) {
  const result = spawnSync('php', ['-d', 'display_errors=1', '-r', source], {
    cwd: root,
    encoding: 'utf8',
    timeout: 10_000,
  });
  assert.equal(result.status, 0, result.stderr);
  return JSON.parse(result.stdout);
}

test('migration catalog declares every SQL checksum and N/N-1 compatibility', { skip: !hasPhp }, () => {
  const entries = runPhp(String.raw`
    $catalog = require 'database/migration_catalog.php';
    echo json_encode(array_values(array_map(static fn(array $entry): array => [
      'version' => $entry['version'],
      'file' => $entry['sql_file'],
      'checksum' => $entry['sql_checksum'],
      'data_check_mode' => $entry['data_check_mode'],
      'data_checks' => $entry['data_checks'],
      'tables' => $entry['tables'],
      'columns' => $entry['columns'],
      'compatibility' => $entry['compatibility'],
    ], $catalog)));
  `);

  assert.equal(entries.length, 51);
  for (const entry of entries) {
    const sql = readFileSync(new URL(`../database/${entry.file}`, import.meta.url));
    assert.equal(createHash('sha256').update(sql).digest('hex'), entry.checksum);
    assert.ok(['queries', 'structural_only'].includes(entry.data_check_mode));
    assert.equal(entry.data_check_mode === 'queries', entry.data_checks.length > 0);
    for (const table of entry.tables) {
      assert.ok(entry.columns[table]?.length > 0, `${entry.version} has no column declaration for ${table}`);
    }
    const checkIds = new Set();
    for (const check of entry.data_checks) {
      assert.match(check.id, /^[a-z][a-z0-9_]+$/);
      assert.equal(checkIds.has(check.id), false);
      checkIds.add(check.id);
      assert.equal(check.type, 'expected_zero');
      assert.match(check.sql.trim(), /^(SELECT|WITH)\b/i);
      assert.doesNotMatch(check.sql, /\b(?:CREATE|ALTER|DROP|INSERT|UPDATE|DELETE|TRUNCATE)\b/i);
    }
    assert.deepEqual(entry.compatibility.required_readers, ['N', 'N-1']);
    assert.deepEqual(entry.compatibility.required_writers, ['N', 'N-1']);
    assert.equal(entry.compatibility.phase, 'expand');
    assert.deepEqual(entry.compatibility.write_adapters, []);
    assert.deepEqual(entry.compatibility.state_changes, []);
    assert.equal(entry.compatibility.validation_status, 'validated_task_5_2');
    assert.equal(entry.compatibility.rollback_strategy, 'preserving');
  }
});

test('API readiness is pure SELECT and reports structure differences', { skip: !hasPhp }, () => {
  const output = runPhp(String.raw`
    require 'database/MigrationReadiness.php';
    $catalog = require 'database/migration_catalog.php';

    final class FakeReadinessDatabase implements MigrationReadinessDatabase {
      public array $queries = [];
      public ?string $omittedIndex = null;
      public ?string $omittedColumn = null;
      public ?string $badChecksumVersion = null;
      public function __construct(private array $catalog) {}
      public function value(string $sql, array $params = []): mixed {
        $this->queries[] = $sql;
        return 1;
      }
      public function rows(string $sql, array $params = []): array {
        $this->queries[] = $sql;
        if (str_contains($sql, 'FROM schema_migrations')) {
          return array_map(fn(string $version): array => [
            'version' => $version,
            'checksum' => $version === $this->badChecksumVersion ? str_repeat('0', 64) : $this->catalog[$version]['sql_checksum'],
            'status' => 'applied',
          ], $params);
        }
        if (str_contains($sql, 'information_schema.TABLES')) {
          return array_map(static fn(string $table): array => ['TABLE_NAME' => $table], $params);
        }
        if (str_contains($sql, 'information_schema.COLUMNS')) {
          $rows = [];
          foreach ($this->catalog as $entry) {
            foreach ($entry['columns'] as $table => $columns) {
              if (in_array($table, $params, true)) {
                foreach ($columns as $column) {
                  if ($column !== $this->omittedColumn) $rows[] = ['TABLE_NAME' => $table, 'COLUMN_NAME' => $column];
                }
              }
            }
          }
          return $rows;
        }
        if (str_contains($sql, 'information_schema.STATISTICS')) {
          $rows = [];
          foreach ($this->catalog as $entry) {
            foreach ($entry['indexes'] as $table => $indexes) {
              if (!in_array($table, $params, true)) continue;
              foreach ($indexes as $index) {
                $name = is_array($index) ? $index[0] : $index;
                if ($name !== $this->omittedIndex) $rows[] = ['TABLE_NAME' => $table, 'INDEX_NAME' => $name];
              }
            }
          }
          return $rows;
        }
        return [];
      }
    }

    $db = new FakeReadinessDatabase($catalog);
    $readiness = new MigrationReadiness($db, $catalog);
    $ready = $readiness->check(['202607310002', '202607310003', '202607310004']);
    $db->omittedIndex = 'idx_platform_sync_changes_cursor';
    $drift = $readiness->check(['202607310004']);
    $db->omittedIndex = null;
    $db->omittedColumn = 'payload_json';
    $columnDrift = $readiness->check(['202607310004']);
    $db->omittedColumn = null;
    $db->badChecksumVersion = '202607310004';
    $checksumDrift = $readiness->check(['202607310004']);
    $db->badChecksumVersion = null;
    $defaultVersionType = gettype($readiness->check()['checked_versions'][0]);
    echo json_encode([
      'ready' => $ready,
      'drift' => $drift,
      'column_drift' => $columnDrift,
      'checksum_drift' => $checksumDrift,
      'default_version_type' => $defaultVersionType,
      'queries' => $db->queries,
    ]);
  `);

  assert.equal(output.ready.ready, true);
  assert.deepEqual(output.ready.checked_versions, ['202607310002', '202607310003', '202607310004']);
  assert.equal(output.drift.ready, false);
  assert.deepEqual(output.drift.issues, [{
    version: '202607310004',
    type: 'missing_index',
    target: 'platform_sync_changes.idx_platform_sync_changes_cursor',
  }]);
  assert.deepEqual(output.column_drift.issues, [{
    version: '202607310004',
    type: 'missing_column',
    target: 'platform_sync_drafts.payload_json',
  }]);
  assert.deepEqual(output.checksum_drift.issues, [{
    version: '202607310004',
    type: 'checksum_mismatch',
  }]);
  assert.equal(output.default_version_type, 'string');
  for (const query of output.queries) {
    assert.match(query.trim(), /^(SELECT|WITH)\b/i);
    assert.doesNotMatch(query, /\b(?:CREATE|ALTER|DROP|INSERT|UPDATE|DELETE|TRUNCATE)\b/i);
    if (/SELECT\s+COUNT\(\*\)/i.test(query)) assert.match(query, /information_schema\.TABLES/i);
  }
  assert.doesNotMatch(readinessSource, /CREATE TABLE|ALTER TABLE|DROP TABLE/i);
});

test('data verification blocks a batch with a bounded difference report', { skip: !hasPhp }, () => {
  const output = runPhp(String.raw`
    require 'database/MigrationReadiness.php';
    $catalog = require 'database/migration_catalog.php';
    final class DataCheckDatabase implements MigrationReadinessDatabase {
      public function value(string $sql, array $params = []): mixed { return str_contains($sql, 'platform_refresh_tokens') ? 2 : 0; }
      public function rows(string $sql, array $params = []): array { return []; }
    }
    echo json_encode((new MigrationReadiness(new DataCheckDatabase(), $catalog))->verifyData(['202607310002']));
  `);

  assert.equal(output.ready, false);
  assert.deepEqual(output.issues, [{
    version: '202607310002',
    type: 'data_check_failed',
    check_id: 'platform_refresh_tokens_have_session',
    difference_count: 2,
  }]);
  assert.equal(JSON.stringify(output).includes('SELECT COUNT'), false);
});
