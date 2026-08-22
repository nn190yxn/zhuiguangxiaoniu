import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { test } from 'node:test';

const root = new URL('..', import.meta.url);
const hasPhp = spawnSync('php', ['-v'], { encoding: 'utf8' }).status === 0;

function runPhp(source) {
  const result = spawnSync('php', ['-d', 'display_errors=1', '-r', source], {
    cwd: root,
    encoding: 'utf8',
    timeout: 10_000,
  });
  assert.equal(result.status, 0, result.stderr);
  return JSON.parse(result.stdout);
}

test('current migration catalog passes expand-migrate-contract validation', { skip: !hasPhp }, () => {
  const result = runPhp(String.raw`
    require 'database/ExpandMigrateContractValidator.php';
    $catalog = require 'database/migration_catalog.php';
    echo json_encode((new ExpandMigrateContractValidator($catalog, 'database/migrations'))->validate());
  `);

  assert.equal(result.compatible, true);
  assert.equal(result.checked_versions.length, 56);
  assert.deepEqual(result.issues, []);
  assert.equal(result.policy, 'expand-migrate-contract');
});

test('compatibility CLI runs without opening a database connection', { skip: !hasPhp }, () => {
  const result = spawnSync('php', ['scripts/migrate.php', 'compatibility'], {
    cwd: root,
    encoding: 'utf8',
    timeout: 10_000,
    env: {},
  });

  assert.equal(result.status, 0, result.stderr);
  const output = JSON.parse(result.stdout);
  assert.equal(output.compatible, true);
  assert.equal(output.checked_versions.length, 56);
});

test('validator blocks destructive schema operations and unsafe additions', { skip: !hasPhp }, () => {
  const result = runPhp(String.raw`
    require 'database/ExpandMigrateContractValidator.php';
    $sql = 'ALTER TABLE accounts ADD COLUMN tenant_code VARCHAR(64) NOT NULL; ALTER TABLE accounts DROP legacy_code; ALTER TABLE accounts RENAME COLUMN name TO display_name;';
    $catalog = ['1' => [
      'sql_file' => '1.sql',
      'sql_checksum' => hash('sha256', $sql),
      'compatibility' => [
        'required_readers' => ['N', 'N-1'],
        'required_writers' => ['N', 'N-1'],
        'phase' => 'expand',
        'write_adapters' => [],
        'state_changes' => [],
        'validation_status' => 'validated_task_5_2',
        'rollback_strategy' => 'preserving',
      ],
    ]];
    $loader = static fn(string $path): string => $sql;
    echo json_encode((new ExpandMigrateContractValidator($catalog, '.', $loader))->validate());
  `);

  assert.equal(result.compatible, false);
  assert.deepEqual(result.issues, [
    { version: '1', type: 'drop_column' },
    { version: '1', type: 'rename_column' },
    { version: '1', type: 'unsafe_added_column', target: 'accounts.tenant_code' },
  ]);
});

test('nullable columns, defaults, and N/N-1 write adapters preserve additions', { skip: !hasPhp }, () => {
  const result = runPhp(String.raw`
    require 'database/ExpandMigrateContractValidator.php';
    $sql = 'ALTER TABLE accounts ADD COLUMN nickname VARCHAR(64) NULL; ALTER TABLE accounts ADD COLUMN revision INT NOT NULL DEFAULT 1; ALTER TABLE accounts ADD COLUMN tenant_code VARCHAR(64) NOT NULL;';
    $catalog = ['1' => [
      'sql_file' => '1.sql',
      'sql_checksum' => hash('sha256', $sql),
      'compatibility' => [
        'required_readers' => ['N', 'N-1'],
        'required_writers' => ['N', 'N-1'],
        'phase' => 'expand',
        'write_adapters' => ['accounts.tenant_code' => ['writers' => ['N', 'N-1'], 'preserves_data' => true]],
        'state_changes' => [],
        'validation_status' => 'validated_task_5_2',
        'rollback_strategy' => 'preserving',
      ],
    ]];
    $loader = static fn(string $path): string => $sql;
    echo json_encode((new ExpandMigrateContractValidator($catalog, '.', $loader))->validate());
  `);

  assert.equal(result.compatible, true);
  assert.deepEqual(result.issues, []);
});

test('new state semantics require downgrade mappings and a disabled feature flag', { skip: !hasPhp }, () => {
  const result = runPhp(String.raw`
    require 'database/ExpandMigrateContractValidator.php';
    $sql = 'SELECT 1;';
    $base = [
      'required_readers' => ['N', 'N-1'],
      'required_writers' => ['N', 'N-1'],
      'phase' => 'expand',
      'write_adapters' => [],
      'validation_status' => 'validated_task_5_2',
      'rollback_strategy' => 'preserving',
    ];
    $invalid = $base + ['state_changes' => [[
      'target' => 'orders.status',
      'introduced_values' => ['reviewing', 'approved'],
      'downgrade_map' => ['reviewing' => 'pending'],
      'feature_flag' => '',
      'enabled_during_compatibility' => true,
    ]]];
    $valid = $base + ['state_changes' => [[
      'target' => 'orders.status',
      'introduced_values' => ['reviewing', 'approved'],
      'downgrade_map' => ['reviewing' => 'pending', 'approved' => 'completed'],
      'feature_flag' => 'order_review_states',
      'enabled_during_compatibility' => false,
    ]]];
    $makeCatalog = static fn(array $contract): array => ['1' => ['sql_file' => '1.sql', 'sql_checksum' => hash('sha256', $sql), 'compatibility' => $contract]];
    $loader = static fn(string $path): string => $sql;
    echo json_encode([
      'invalid' => (new ExpandMigrateContractValidator($makeCatalog($invalid), '.', $loader))->validate(),
      'valid' => (new ExpandMigrateContractValidator($makeCatalog($valid), '.', $loader))->validate(),
    ]);
  `);

  assert.equal(result.invalid.compatible, false);
  assert.deepEqual(result.invalid.issues, [
    { version: '1', type: 'missing_state_downgrade', target: 'orders.status:approved' },
    { version: '1', type: 'missing_feature_flag_gate', target: 'orders.status' },
  ]);
  assert.equal(result.valid.compatible, true);
});
