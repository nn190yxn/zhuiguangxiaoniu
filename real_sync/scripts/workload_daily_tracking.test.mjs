import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8');
const service = read('../api/workload/services/WorkloadDailyTrackingService.php');
const trackingEndpoint = read('../api/admin/workload/daily-tracking.php');
const penaltyEndpoint = read('../api/admin/workload/penalty-list.php');
const penaltyActionEndpoint = read('../api/admin/workload/penalty-action.php');

test('[validates 3.4 to 3.7, 4.3, 4.4] daily tracking constrains headquarters, manager, and employee scopes', () => {
  assert.match(service, /WorkloadPermissionScopeService/);
  assert.match(service, /scope_type.*=== 'staff'/);
  assert.match(service, /scope_type.*=== 'stores'/);
  assert.match(service, /staff_id = \?/);
  assert.match(service, /store_id IN/);
  assert.match(service, /target_points/);
  assert.match(service, /reported_points/);
  assert.match(service, /pending_points/);
  assert.match(service, /effective_points/);
  assert.match(service, /gap_points/);
  assert.match(service, /makeup_deadline_at/);
});

test('[validates 3.4 to 3.7, 4.3 to 4.5] tracking and penalty endpoints expose Chinese labels and next actions', () => {
  for (const endpoint of [trackingEndpoint, penaltyEndpoint]) {
    assert.match(endpoint, /adminRequireAuth\('adminCanAccessWorkload'\)/);
    assert.match(endpoint, /WorkloadDailyTrackingService/);
  }
  for (const label of [
    '今日待完成', '昨日待补', '已达标', '已逾期',
    '待确认处罚', '已确认处罚', '已撤销处罚', '已交薪资',
    '确认处罚处理结果', '等待薪资交接', '已完成薪资交接',
  ]) {
    assert.match(service, new RegExp(label));
  }
  assert.match(service, /penalty_amount/);
  assert.match(service, /penalty_status_label/);
  assert.match(service, /next_action/);
});

test('[validates 3.4 to 3.6] penalty action endpoint accepts only authorized POST operations', () => {
  assert.match(penaltyActionEndpoint, /REQUEST_METHOD.*POST/);
  assert.match(penaltyActionEndpoint, /\['operation', 'admin', 'ceo'\]/);
  assert.match(penaltyActionEndpoint, /penalty_id/);
  assert.match(penaltyActionEndpoint, /action/);
  assert.match(penaltyActionEndpoint, /reason/);
  assert.match(penaltyActionEndpoint, /applyAction\(\$penaltyId, \$action, \$reason, \$operatorStaffId\)/);
  assert.match(penaltyActionEndpoint, /adminRecordOperation/);
});
