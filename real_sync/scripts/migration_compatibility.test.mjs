import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { readdirSync, readFileSync } from 'node:fs';
import { test } from 'node:test';

const root = new URL('..', import.meta.url);
const migrationFileCount = readdirSync(new URL('../database/migrations/', import.meta.url))
  .filter((name) => name.endsWith('.sql')).length;
const hasPhp = spawnSync('php', ['-v'], { encoding: 'utf8' }).status === 0;
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

function classifyRisks(sql) {
  const encodedSql = Buffer.from(sql).toString('base64');
  return runPhp(String.raw`
    require 'database/ExpandMigrateContractValidator.php';
    $sql = base64_decode('${encodedSql}', true);
    $contract = [
      'required_readers' => ['N', 'N-1'],
      'required_writers' => ['N', 'N-1'],
      'phase' => 'expand',
      'write_adapters' => [],
      'state_changes' => [],
      'validation_status' => 'validated_task_5_2',
      'rollback_strategy' => 'preserving',
      'risk_declaration' => [
        'compatibility_window' => 'N and N-1',
        'write_adapter' => 'Both writer versions remain supported',
        'estimated_affected_rows' => 0,
        'lock_risk' => 'Measured before apply',
        'execution_strategy' => 'Apply in an approved window',
        'forward_fix' => 'Use a new additive migration',
      ],
    ];
    $catalog = ['1' => [
      'sql_file' => '1.sql',
      'sql_checksum' => hash('sha256', $sql),
      'compatibility' => $contract,
    ]];
    $loader = static fn(string $path): string => $sql;
    echo json_encode((new ExpandMigrateContractValidator($catalog, '.', $loader))->validate());
  `);
}

function riskIdentity(risk) {
  return [risk.type, risk.target, risk.source ?? ''].join(':');
}

test('current migration catalog passes expand-migrate-contract validation', { skip: !hasPhp }, () => {
  const result = runPhp(String.raw`
    require 'database/ExpandMigrateContractValidator.php';
    $catalog = require 'database/migration_catalog.php';
    echo json_encode((new ExpandMigrateContractValidator($catalog, 'database/migrations'))->validate());
  `);

  assert.equal(result.compatible, true);
  assert.equal(result.checked_versions.length, migrationFileCount);
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
  assert.equal(output.checked_versions.length, migrationFileCount);
});

test('lesson version relation migration declares its exact backfill and column compatibility plan', { skip: !hasPhp }, () => {
  const result = runPhp(String.raw`
    $catalog = require 'database/migration_catalog.php';
    echo json_encode($catalog['202609040002']['compatibility']['risk_declaration']);
  `);

  assert.deepEqual(result, {
    compatibility_window: 'N and N-1 readers ignore knowledge_version_id; N and N-1 writers may leave it NULL through the next release window.',
    write_adapter: 'Keep knowledge_item_id nullable and accept NULL knowledge_version_id from N-1 writers; N writers pin the selected knowledge item current version.',
    estimated_affected_rows: "Preflight with SELECT COUNT(*) FROM lesson_suggestions WHERE source_type = 'knowledge_card' AND knowledge_version_id IS NULL; the result is the exact backfill row estimate.",
    lock_risk: 'MODIFY COLUMN may rebuild lesson_suggestions under a metadata lock; the bounded backfill locks only matching knowledge_card rows.',
    execution_strategy: 'Count matching rows and validate all lesson and knowledge version ownership checks, then apply during the approved window before enabling pinned-version reads.',
    forward_fix: 'Apply a new additive migration to backfill remaining NULL knowledge_version_id values from knowledge_items.current_version_id and repair invalid ownership before retrying constraints.',
  });

  const migration = readFileSync(new URL('../database/migrations/202609040002_lesson_version_relations.sql', import.meta.url), 'utf8');
  assert.match(migration, /MODIFY COLUMN knowledge_item_id INT UNSIGNED NULL/);
  assert.match(migration, /WHERE suggestion\.source_type = 'knowledge_card' AND suggestion\.knowledge_version_id IS NULL/);
  assert.match(migration, /FOREIGN KEY \(knowledge_item_id, knowledge_version_id\) REFERENCES knowledge_item_versions \(knowledge_item_id, version_id\)/);
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

test('validator classifies column, data, state backfill, and table rewrite risks', { skip: !hasPhp }, () => {
  const result = runPhp(String.raw`
    require 'database/ExpandMigrateContractValidator.php';
    $sql = <<<'SQL'
      ALTER TABLE accounts MODIFY COLUMN status VARCHAR(32) NOT NULL;
      UPDATE accounts SET status = 'active' WHERE status IS NULL;
      INSERT INTO account_events (account_id, event_type) SELECT id, 'backfilled' FROM accounts;
      ALTER TABLE account_events ENGINE=InnoDB;
    SQL;
    $catalog = ['1' => [
      'sql_file' => '1.sql',
      'sql_checksum' => hash('sha256', $sql),
      'compatibility' => [
        'required_readers' => ['N', 'N-1'],
        'required_writers' => ['N', 'N-1'],
        'phase' => 'expand',
        'write_adapters' => [],
        'state_changes' => [[
          'target' => 'accounts.status',
          'introduced_values' => [],
          'backfill' => true,
        ]],
        'validation_status' => 'validated_task_5_2',
        'rollback_strategy' => 'preserving',
      ],
    ]];
    $loader = static fn(string $path): string => $sql;
    echo json_encode((new ExpandMigrateContractValidator($catalog, '.', $loader))->validate());
  `);

  assert.deepEqual(result.risks, [
    { version: '1', type: 'modify_column', target: 'accounts.status', statement: 1 },
    { version: '1', type: 'table_rewrite', target: 'accounts', statement: 1, source: 'modify_column' },
    { version: '1', type: 'data_update', target: 'accounts', statement: 2 },
    { version: '1', type: 'state_backfill', target: 'accounts.status', statement: 2 },
    { version: '1', type: 'data_insert', target: 'account_events', statement: 3 },
    { version: '1', type: 'table_rewrite', target: 'account_events', statement: 4, source: 'engine_change' },
  ]);
});

test(`${validatesCriteria(['5.3', '5.4'])} SQL risk classification is stable across statement order, case, comments, and identifier quoting`, { skip: !hasPhp }, () => {
  const statements = [
    'ALTER TABLE accounts MODIFY COLUMN status VARCHAR(32) NOT NULL',
    "UPDATE accounts SET status = 'active' WHERE status IS NULL",
    "INSERT INTO account_events (account_id, event_type) SELECT id, 'backfilled' FROM accounts",
    'ALTER TABLE account_events ENGINE=InnoDB',
    'ALTER TABLE customer_profiles CONVERT TO CHARACTER SET utf8mb4',
  ];
  const expected = [
    'data_insert:account_events:',
    'data_update:accounts:',
    'modify_column:accounts.status:',
    'state_backfill:accounts.status:',
    'table_rewrite:account_events:engine_change',
    'table_rewrite:accounts:modify_column',
    'table_rewrite:customer_profiles:charset_conversion',
  ];

  for (let seed = 1; seed <= 24; seed += 1) {
    const offset = seed % statements.length;
    const ordered = [...statements.slice(offset), ...statements.slice(0, offset)];
    if (seed % 2 === 0) ordered.reverse();
    const sql = ordered
      .map((statement, index) => {
        let variant = seed % 3 === 0 ? statement.toLowerCase() : statement;
        if (seed % 4 === 0) {
          variant = variant.replace(/\b(accounts|account_events|customer_profiles|status)\b/gi, '`$1`');
        }
        return `/* seed ${seed}, statement ${index + 1} */\n${variant}`;
      })
      .join(';\n') + `;\n-- UPDATE ignored_table SET status = 'ignored'`;
    const result = classifyRisks(sql);
    const actual = result.risks.map(riskIdentity).sort();

    assert.equal(result.compatible, true, `seed ${seed}`);
    assert.deepEqual(actual, expected, `seed ${seed}`);
    assert.equal(new Set(actual).size, actual.length, `seed ${seed} emitted duplicate risks`);
  }
});

test('risk classifier ignores comments and quoted SQL keywords', { skip: !hasPhp }, () => {
  const result = runPhp(String.raw`
    require 'database/ExpandMigrateContractValidator.php';
    $sql = "-- UPDATE accounts SET status = 'active';\nSELECT 'INSERT INTO audit_log', 'MODIFY COLUMN status';";
    $contract = [
      'required_readers' => ['N', 'N-1'],
      'required_writers' => ['N', 'N-1'],
      'phase' => 'expand',
      'write_adapters' => [],
      'state_changes' => [],
      'validation_status' => 'validated_task_5_2',
      'rollback_strategy' => 'preserving',
    ];
    $catalog = ['1' => ['sql_file' => '1.sql', 'sql_checksum' => hash('sha256', $sql), 'compatibility' => $contract]];
    $loader = static fn(string $path): string => $sql;
    echo json_encode((new ExpandMigrateContractValidator($catalog, '.', $loader))->validate());
  `);

  assert.deepEqual(result.risks, []);
});

test('validator reports each missing migration risk declaration with SQL context', { skip: !hasPhp }, () => {
  const result = runPhp(String.raw`
    require 'database/ExpandMigrateContractValidator.php';
    $sql = "UPDATE accounts SET display_name = 'Unknown' WHERE display_name IS NULL;";
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
    { version: '1', type: 'missing_risk_declaration', sql_type: 'data_update', target: 'accounts', statement: 1, missing: 'compatibility_window' },
    { version: '1', type: 'missing_risk_declaration', sql_type: 'data_update', target: 'accounts', statement: 1, missing: 'write_adapter' },
    { version: '1', type: 'missing_risk_declaration', sql_type: 'data_update', target: 'accounts', statement: 1, missing: 'estimated_affected_rows' },
    { version: '1', type: 'missing_risk_declaration', sql_type: 'data_update', target: 'accounts', statement: 1, missing: 'lock_risk' },
    { version: '1', type: 'missing_risk_declaration', sql_type: 'data_update', target: 'accounts', statement: 1, missing: 'execution_strategy' },
    { version: '1', type: 'missing_risk_declaration', sql_type: 'data_update', target: 'accounts', statement: 1, missing: 'rollback_or_forward_fix' },
  ]);
});

test('validator accepts complete migration risk declarations with a forward fix', { skip: !hasPhp }, () => {
  const result = runPhp(String.raw`
    require 'database/ExpandMigrateContractValidator.php';
    $sql = "INSERT INTO account_events (event_type) VALUES ('backfill');";
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
        'risk_declaration' => [
          'compatibility_window' => 'N and N-1 remain supported through the next release',
          'write_adapter' => 'Existing writers remain valid; the seed is additive',
          'estimated_affected_rows' => 1,
          'lock_risk' => 'Short row lock on account_events',
          'execution_strategy' => 'Apply once during the approved migration window',
          'forward_fix' => 'Correct seed data with a new additive migration',
        ],
      ],
    ]];
    $loader = static fn(string $path): string => $sql;
    echo json_encode((new ExpandMigrateContractValidator($catalog, '.', $loader))->validate());
  `);

  assert.equal(result.compatible, true);
  assert.deepEqual(result.issues, []);
  assert.deepEqual(result.risks, [
    { version: '1', type: 'data_insert', target: 'account_events', statement: 1 },
  ]);
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
