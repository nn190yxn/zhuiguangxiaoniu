import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const projectRoot = new URL('../', import.meta.url).pathname;
const harnessPath = new URL('./migration_mysql.integration.php', import.meta.url).pathname;
const baselinePath = new URL('./fixtures/migration-mysql/baseline.sql', import.meta.url).pathname;
const harness = readFileSync(harnessPath, 'utf8');
const baseline = readFileSync(baselinePath, 'utf8');
const requiredEnvironment = [
  'TEST_DB_HOST',
  'TEST_DB_NAME',
  'TEST_DB_USER',
  'TEST_DB_PASSWORD',
  'TEST_DB_CONFIRM',
];
const hasTestDatabase = requiredEnvironment.every((key) => Boolean(process.env[key]));

function runHarness(environment) {
  return spawnSync('php', [harnessPath], {
    cwd: projectRoot,
    encoding: 'utf8',
    env: { ...process.env, ...environment },
    timeout: 180_000,
  });
}

test('migration harness 固定专用空库和完整六阶段流程', () => {
  assert.match(harness, /\^mc_migration_test_\[a-z0-9_\]\+\$/);
  assert.match(harness, /ALLOW_MIGRATION_HARNESS/);
  assert.match(harness, /target database must be empty/);
  assert.match(harness, /baseline\.sql/);
  assert.match(harness, /->apply\(true\)/);
  assert.match(harness, /->apply\(false\)/);
  assert.match(harness, /->verify\(\)/);
  assert.match(harness, /->check\(\)/);
  assert.match(harness, /->verifyData\(\)/);
  assert.match(harness, /assertKeyData/);
  assert.match(harness, /assertForeignKeys/);
  assert.match(harness, /'sql_checksum'\s*=>\s*\$entry\['sql_checksum'\]/);
  assert.doesNotMatch(harness, /\b(?:DROP|TRUNCATE|DELETE)\b/i);
});

test('baseline 描述旧版依赖并包含可验证种子数据', () => {
  for (const table of [
    'staffs',
    'stores',
    'metric_definitions',
    'workload_templates',
    'workload_template_items',
    'workload_daily_reports',
    'workload_daily_report_values',
    'workload_metric_rules',
    'workload_audit_tasks',
    'knowledge_categories',
    'knowledge_items',
    'policies',
    'points_rules',
    'points_records',
    'courses',
    'user_course_progress',
  ]) {
    assert.match(baseline, new RegExp(`CREATE TABLE ${table}\\b`));
  }
  assert.match(baseline, /migration-baseline-staff/);
  assert.match(baseline, /migration-baseline-report/);
  assert.match(baseline, /daily_checkin/);
  assert.match(baseline, /Migration Course/);
});

test('migration harness 拒绝普通数据库名', () => {
  const result = runHarness({
    TEST_DB_HOST: '127.0.0.1',
    TEST_DB_NAME: 'production',
    TEST_DB_USER: 'placeholder',
    TEST_DB_PASSWORD: 'placeholder',
    TEST_DB_CONFIRM: 'ALLOW_MIGRATION_HARNESS',
  });
  assert.notEqual(result.status, 0);
  assert.match(result.stderr, /mc_migration_test_/);
});

test('migration harness 要求显式确认口令', () => {
  const result = runHarness({
    TEST_DB_HOST: '127.0.0.1',
    TEST_DB_NAME: 'mc_migration_test_contract',
    TEST_DB_USER: 'placeholder',
    TEST_DB_PASSWORD: 'placeholder',
    TEST_DB_CONFIRM: 'missing-confirmation',
  });
  assert.notEqual(result.status, 0);
  assert.match(result.stderr, /TEST_DB_CONFIRM/);
});

test('68 个 migration 通过真实 MySQL 完整重放', { skip: !hasTestDatabase }, () => {
  const result = runHarness({});
  assert.equal(result.status, 0, result.stderr);
  const output = JSON.parse(result.stdout);
  assert.equal(output.ok, true);
  assert.equal(output.database_classification, 'dedicated_migration_test');
  assert.equal(output.migration_count, 68);
  assert.equal(output.dry_run.unchanged, true);
  assert.equal(output.apply.applied, 68);
  assert.equal(output.verification.ok, true);
  assert.equal(output.readiness.structure_ready, true);
  assert.equal(output.readiness.data_ready, true);
  assert.equal(output.replay.already_applied, 68);
  assert.equal(output.key_data.ok, true);
  assert.equal(output.foreign_keys.ok, true);
});
