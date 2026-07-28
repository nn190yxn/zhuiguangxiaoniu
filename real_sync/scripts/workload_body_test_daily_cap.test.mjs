import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const root = new URL('../', import.meta.url);
const read = (path) => readFileSync(new URL(path, root), 'utf8');

const common = read('api/workload/_common.php');
const migration = read('database/migrations/202607280002_workload_body_test_daily_cap.sql');
const roleRuleService = read('api/workload/services/WorkloadRoleRuleVersionService.php');
const manifest = read('database/migration_manifest.php');

test('coach body test metric has a daily workload cap of two', () => {
  assert.match(common, /UPDATE metric_definitions SET max_value = 2 WHERE role_code = 'coach' AND metric_code = 'coach_body_test'/);
  assert.match(migration, /metric_code = 'coach_body_test'/);
  assert.match(migration, /SET max_value = 2/);
  assert.match(migration, /workload_role_metric_rules/);
  assert.match(manifest, /'202607280002'/);
});

test('role rule validation rejects submitted values above the configured cap', () => {
  assert.match(roleRuleService, /if \(\$rule\['max_value'\] !== null && \$value > \$rule\['max_value'\]\)/);
  assert.match(roleRuleService, /指标值超过规则最大值/);
});
