import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const runner = readFileSync(new URL('../database/MigrationRunner.php', import.meta.url), 'utf8');
const cli = readFileSync(new URL('./migrate.php', import.meta.url), 'utf8');
const manifest = readFileSync(new URL('../database/migration_manifest.php', import.meta.url), 'utf8');

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

test('runner reports structure and row count differences', () => {
  assert.match(runner, /'structure_diff' =>/);
  assert.match(runner, /'count_diff' =>/);
  assert.match(runner, /information_schema\.COLUMNS/);
  assert.match(runner, /information_schema\.STATISTICS/);
  assert.match(runner, /SELECT COUNT\(\*\) FROM/);
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
