import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8');
const migration = read('../database/migrations/202608180001_workload_standard_v4.sql');
const policy = read('../docs/v4/02_人员管理体系/02G_工作量管理标准_v4.0.md');
const h5 = read('../mobile/workload-v2.html');
const penalty = read('../api/workload/services/WorkloadPenaltyService.php');

test('工作量 v4.0 迁移覆盖五个岗位并保留历史区间', () => {
  for (const version of [
    'workload-v4-20260818-coach',
    'workload-v4-20260818-sales',
    'workload-v4-20260818-manager',
    'workload-v4-20260818-teaching_supervisor',
    'workload-v4-20260818-supervisor',
  ]) assert.match(migration, new RegExp(version));
  assert.match(migration, /effective_to = '2026-08-17'/);
  assert.match(migration, /'2026-08-18'/);
  assert.doesNotMatch(migration, /DELETE\s+FROM/i);
  assert.doesNotMatch(migration, /DROP\s+(?:TABLE|COLUMN|INDEX)/i);
});

test('工作量 v4.0 项目和销售电话门槛与制度一致', () => {
  for (const value of ['coach_trial_delivery', 'coach_body_test', 'coach_motion_plan', 'sales_calls', 'sales_deal_amount', 'manager_workload_check', 'teaching_supervisor_lesson_plan_final_review', 'supervisor_work_log']) {
    assert.match(migration, new RegExp(value));
  }
  assert.match(migration, /'sales-calls'.*30/s);
  assert.match(policy, /有效电话邀约.*不少于 30 通/);
  assert.match(policy, /4000 元以下计 1 个，4000 元以上计 2 个/);
});

test('H5 支持全部五类岗位且连续第二日才产生新口径乐捐', () => {
  for (const role of ['teaching_supervisor', 'supervisor']) assert.match(h5, new RegExp(role));
  assert.match(penalty, /PENALTY_POLICY_EFFECTIVE_DATE/);
  assert.match(penalty, /hasPreviousOverdueGap/);
  assert.match(penalty, /settlement_status = 'overdue'/);
});
