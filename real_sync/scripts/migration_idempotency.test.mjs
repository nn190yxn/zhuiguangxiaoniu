import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const migrationPaths = [
  '../database/migrations/202607240001_staff_organization.sql',
  '../database/migrations/202607240002_workload_governance.sql',
  '../database/migrations/202607240003_admin_operation_audit.sql',
  '../database/migrations/202607240004_staff_employee_number_sequence.sql',
  '../database/migrations/202607240005_workload_audit_task_history.sql',
  '../database/migrations/202607240006_workload_audit_resubmission.sql',
  '../database/migrations/202607240007_workload_metric_relations.sql',
  '../database/migrations/202607240008_workload_standard_management.sql',
];
const migrations = migrationPaths.map((path) => {
  const sql = readFileSync(new URL(path, import.meta.url), 'utf8');
  const name = path.split('/').at(-1);
  return {
    version: name.slice(0, 12),
    checksum: createHash('sha256').update(sql).digest('hex'),
    sql,
  };
});

function baseline({ staffs = 0, reports = 0, roles = ['sales', 'coach'] } = {}) {
  const table = (columns, rows = 0) => ({ columns: new Set(columns), indexes: new Set(), rows });
  return {
    tables: new Map([
      ['staffs', table(['id', 'status', 'employee_no', 'user_id', 'role', 'job_title', 'store_id', 'entry_date', 'created_at'], staffs)],
      ['stores', table(['id', 'name', 'status'], 2)],
      ['workload_daily_reports', table(['id', 'report_date', 'store_id', 'staff_id', 'role_code', 'template_id', 'submit_status', 'source', 'submitted_at', 'updated_at'], reports)],
      ['workload_daily_report_values', table(['id', 'report_id', 'metric_id', 'numeric_value'], reports * 4)],
      ['workload_audit_tasks', table(['id', 'report_id', 'staff_id', 'store_id', 'role_code', 'metric_code', 'audit_status', 'created_at'], reports)],
      ['workload_templates', table(['id', 'role_code', 'version_no'], roles.length)],
      ['metric_definitions', table(['id', 'metric_code', 'role_code', 'is_required', 'min_value', 'max_value', 'sort_order'], roles.length * 4)],
      ['workload_metric_rules', table(['id', 'metric_code', 'role_code', 'need_evidence', 'min_evidence_count', 'max_evidence_count', 'audit_mode', 'enabled'], roles.length)],
    ]),
    history: new Map(),
    roles,
  };
}

function cloneState(state) {
  return {
    tables: new Map([...state.tables].map(([name, value]) => [name, {
      columns: new Set(value.columns),
      indexes: new Set(value.indexes),
      rows: value.rows,
    }])),
    history: new Map([...state.history].map(([key, value]) => [key, { ...value }])),
    roles: [...state.roles],
  };
}

function schemaSignature(state) {
  return JSON.stringify([...state.tables].sort(([a], [b]) => a.localeCompare(b)).map(([name, table]) => [
    name,
    [...table.columns].sort(),
    [...table.indexes].sort(),
    table.rows,
  ]));
}

function parseOperations(sql) {
  const operations = [];
  for (const match of sql.matchAll(/CREATE TABLE IF NOT EXISTS\s+([a-z0-9_]+)\s*\(([\s\S]*?)\) ENGINE=InnoDB/g)) {
    const [, tableName, body] = match;
    const columns = [...body.matchAll(/^\s{4}([a-z0-9_]+)\s+[A-Z]/gm)].map((item) => item[1]);
    const indexes = [...body.matchAll(/(?:UNIQUE\s+)?KEY\s+([a-z0-9_]+)\s*\(/g)].map((item) => item[1]);
    operations.push({ type: 'create_table', tableName, columns, indexes });
  }
  for (const match of sql.matchAll(/ALTER TABLE\s+([a-z0-9_]+)\s+ADD COLUMN\s+([a-z0-9_]+)/g)) {
    operations.push({ type: 'add_column', tableName: match[1], name: match[2] });
  }
  for (const match of sql.matchAll(/ALTER TABLE\s+([a-z0-9_]+)\s+ADD\s+(?:UNIQUE\s+)?KEY\s+([a-z0-9_]+)/g)) {
    operations.push({ type: 'add_index', tableName: match[1], name: match[2] });
  }
  return operations;
}

function applyMigration(state, migration, { failAfter = Number.POSITIVE_INFINITY } = {}) {
  const existing = state.history.get(migration.version);
  if (existing?.status === 'applied') {
    assert.equal(existing.checksum, migration.checksum);
    return 'already_applied';
  }
  state.history.set(migration.version, { status: 'running', checksum: migration.checksum });
  let completed = 0;
  try {
    for (const operation of parseOperations(migration.sql)) {
      if (completed === failAfter) throw new Error('injected migration failure');
      if (operation.type === 'create_table') {
        if (!state.tables.has(operation.tableName)) {
          state.tables.set(operation.tableName, {
            columns: new Set(operation.columns),
            indexes: new Set(operation.indexes),
            rows: 0,
          });
        }
      } else {
        const table = state.tables.get(operation.tableName);
        if (!table) throw new Error(`missing baseline table ${operation.tableName}`);
        if (operation.type === 'add_column') table.columns.add(operation.name);
        if (operation.type === 'add_index') table.indexes.add(operation.name);
      }
      completed++;
    }
    applyBackfillModel(state, migration.version);
    state.history.set(migration.version, { status: 'applied', checksum: migration.checksum });
    return 'applied';
  } catch (error) {
    state.history.set(migration.version, { status: 'failed', checksum: migration.checksum });
    throw error;
  }
}

function applyBackfillModel(state, version) {
  if (version === '202607240001') {
    state.tables.get('organization_positions').rows = Math.max(7, state.roles.length);
    state.tables.get('staff_assignments').rows = state.tables.get('staffs').rows;
  }
  if (version === '202607240002') {
    state.tables.get('workload_source_policies').rows = 7;
    state.tables.get('workload_metric_versions').rows = 1;
    state.tables.get('workload_role_rule_versions').rows = state.roles.length;
    state.tables.get('workload_role_metric_rules').rows = state.roles.length * 4;
    state.tables.get('workload_submission_obligations').rows = state.tables.get('workload_daily_reports').rows;
    state.tables.get('workload_alert_rules').rows = 6;
  }
}

function applyAll(state, options = {}) {
  return migrations.map((migration, index) => applyMigration(
    state,
    migration,
    index === options.failMigrationIndex ? { failAfter: options.failAfter } : {},
  ));
}

test('empty application baseline receives the complete additive schema', () => {
  const state = baseline();
  assert.deepEqual(applyAll(state), ['applied', 'applied', 'applied', 'applied', 'applied', 'applied', 'applied', 'applied']);
  assert.equal(state.tables.get('staff_assignments').rows, 0);
  assert.equal(state.tables.get('workload_submission_obligations').rows, 0);
  assert.ok(state.tables.has('admin_operation_logs'));
  assert.ok(state.tables.has('staff_employee_number_sequences'));
  assert.ok(state.tables.get('workload_audit_tasks').columns.has('evidence_count_at_review'));
  assert.ok(state.tables.has('workload_metric_relation_versions'));
  assert.ok(state.tables.has('workload_metric_relations'));
  assert.ok(state.tables.has('workload_standard_idempotency_keys'));
  assert.ok(state.tables.get('workload_role_rule_versions').columns.has('requires_daily_report'));
  assert.ok(state.tables.get('workload_role_metric_rules').columns.has('metric_name_snapshot'));
  for (const table of ['organization_positions', 'staff_assignments', 'workload_submission_obligations', 'workload_alert_events']) {
    assert.ok(state.tables.has(table), `expected ${table}`);
  }
});

test('historical baseline preserves facts and backfills only known records', () => {
  const state = baseline({ staffs: 12, reports: 25 });
  const before = new Map([...state.tables].map(([name, table]) => [name, table.rows]));
  applyAll(state);
  for (const [name, rows] of before) assert.equal(state.tables.get(name).rows, rows, name);
  assert.equal(state.tables.get('staff_assignments').rows, 12);
  assert.equal(state.tables.get('workload_submission_obligations').rows, 25);
});

test('repeated execution produces no additional schema or row changes', () => {
  const state = baseline({ staffs: 3, reports: 8 });
  applyAll(state);
  const firstSignature = schemaSignature(state);
  assert.deepEqual(applyAll(state), ['already_applied', 'already_applied', 'already_applied', 'already_applied', 'already_applied', 'already_applied', 'already_applied', 'already_applied']);
  assert.equal(schemaSignature(state), firstSignature);
});

test('partially migrated schema keeps existing fields and fills the remainder', () => {
  const state = baseline({ staffs: 2, reports: 4 });
  state.tables.get('staffs').columns.add('lifecycle_status');
  state.tables.get('workload_daily_reports').columns.add('metric_version_id');
  applyAll(state);
  assert.ok(state.tables.get('staffs').columns.has('session_version'));
  assert.ok(state.tables.get('workload_daily_reports').columns.has('rule_version_id'));
  assert.equal(state.tables.get('staffs').rows, 2);
  assert.equal(state.tables.get('workload_daily_reports').rows, 4);
});

test('injected failure records failure, preserves facts, and permits idempotent retry', () => {
  const state = baseline({ staffs: 5, reports: 9 });
  const before = cloneState(state);
  assert.throws(() => applyAll(state, { failMigrationIndex: 0, failAfter: 2 }), /injected migration failure/);
  assert.equal(state.history.get('202607240001').status, 'failed');
  for (const table of ['staffs', 'stores', 'workload_daily_reports']) {
    assert.equal(state.tables.get(table).rows, before.tables.get(table).rows);
  }
  assert.equal(applyMigration(state, migrations[0]), 'applied');
  assert.equal(applyMigration(state, migrations[1]), 'applied');
  assert.equal(applyMigration(state, migrations[2]), 'applied');
  assert.equal(applyMigration(state, migrations[3]), 'applied');
  assert.equal(applyMigration(state, migrations[4]), 'applied');
  assert.equal(applyMigration(state, migrations[5]), 'applied');
  assert.equal(applyMigration(state, migrations[6]), 'applied');
  assert.equal(applyMigration(state, migrations[7]), 'applied');
  assert.equal(state.tables.get('staff_assignments').rows, 5);
  assert.equal(state.tables.get('workload_submission_obligations').rows, 9);
  assert.ok(state.tables.has('admin_operation_logs'));
});

test('migration and rollback contracts remain preserving', () => {
  for (const migration of migrations) {
    assert.doesNotMatch(migration.sql, /\bDROP\s+(?:TABLE|COLUMN|INDEX)\b/i);
    assert.doesNotMatch(migration.sql, /\bTRUNCATE\b/i);
    assert.doesNotMatch(migration.sql, /\bDELETE\s+FROM\b/i);
  }
});
