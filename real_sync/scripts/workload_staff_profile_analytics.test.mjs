import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const servicePath = new URL('../api/workload/services/WorkloadStaffProfileService.php', import.meta.url);
const rankingServicePath = new URL('../api/workload/services/WorkloadMetricSelectionService.php', import.meta.url);
const comparisonServicePath = new URL('../api/workload/services/WorkloadComparisonService.php', import.meta.url);
const endpointPath = new URL('../api/workload/analytics/staff-profile.php', import.meta.url);
const [service, rankingService, comparisonService, endpoint] = await Promise.all([
  readFile(servicePath, 'utf8'),
  readFile(rankingServicePath, 'utf8'),
  readFile(comparisonServicePath, 'utf8'),
  readFile(endpointPath, 'utf8'),
]);

test('staff profile endpoint requires GET, staff authentication, and audit logging', () => {
  assert.match(endpoint, /REQUEST_METHOD[\s\S]*'GET'/);
  assert.match(endpoint, /appRequireStaffContext\(\)/);
  assert.match(endpoint, /appRequireInt\([\s\S]*'staff_id'/);
  assert.match(endpoint, /new WorkloadStaffProfileService\(\$pdo\)/);
  assert.match(endpoint, /workload\.analytics\.staff_profile/);
  assert.match(endpoint, /catch \(WorkloadAnalyticsQueryException \| WorkloadSourcePolicyException/);
});

test('staff profile reuses unified facts and visible-scope metric rankings', () => {
  assert.match(service, /new WorkloadAnalyticsQueryService\(\$pdo\)/);
  assert.match(service, /new WorkloadMetricSelectionService\(\$pdo\)/);
  assert.match(service, /->facts\(\$profileInput, \$context\)/);
  assert.match(service, /unset\(\$rankingInput\['staff_id'\]/);
  assert.match(service, /->metricSelection\(\$rankingInput, \$context\)/);
  for (const field of [
    'store_effective_value_rank',
    'all_store_role_effective_value_rank',
    'store_role_effective_average',
    'all_store_role_effective_average',
    'top_quartile_effective_reference',
  ]) {
    assert.match(`${service}\n${rankingService}`, new RegExp(field));
  }
});

test('staff profile returns complete obligation, report, metric, evidence, and audit records', () => {
  assert.match(service, /workload_submission_obligations/);
  assert.match(service, /required_status/);
  assert.match(service, /completion_status/);
  assert.match(service, /r\.remarks, r\.submitted_at, r\.updated_at/);
  assert.match(service, /workload_evidences/);
  assert.match(service, /workloadNormalizeEvidenceRows/);
  assert.match(service, /workload_audit_tasks/);
  assert.match(service, /audit_comment/);
  for (const field of ['raw_value', 'pending_value', 'effective_value', 'rejected_value']) {
    assert.match(service, new RegExp(`'${field}'`));
  }
  assert.match(service, /'daily_records'/);
});

test('staff profile supports day, week, and month trend aggregation with zero-filled metrics', () => {
  assert.match(service, /\['day', 'week', 'month'\]/);
  assert.match(service, /private function trend/);
  assert.match(service, /private function periodKey/);
  assert.match(service, /private function metricCatalog/);
  assert.match(service, /private function emptyAggregate/);
  assert.match(service, /'sample_size' => 0/);
  assert.match(service, /'low_sample' => true/);
});

test('staff profile aligns comparison periods by business days and returns four-period baselines', () => {
  assert.match(service, /private function businessDayCount/);
  assert.match(service, /private function businessDayRows/);
  assert.match(service, /private function previousBusinessPeriod/);
  assert.match(service, /format\('N'\) !== 1/);
  assert.match(service, /for \(\$index = 0; \$index < 4; \$index\+\+\)/);
  for (const field of [
    'previous_period',
    'change_value',
    'change_rate',
    'comparison_state',
    'past_four_period_average',
    'previous_sample_size',
  ]) {
    assert.match(`${service}\n${comparisonService}`, new RegExp(`'${field}'`));
  }
});

test('staff profile enforces employee, store-manager, and headquarters permission scopes', () => {
  assert.match(service, /scope_type[\s\S]*=== 'staff'/);
  assert.match(service, /scope_type[\s\S]*=== 'stores'/);
  assert.match(service, /hasHistoricalStoreAccess/);
  assert.match(service, /无权查看该员工工作量画像/);
  assert.match(service, /o\.store_id[\s\S]*scope\['store_ids'\]/);
  assert.match(service, /r\.store_id[\s\S]*scope\['store_ids'\]/);
});
