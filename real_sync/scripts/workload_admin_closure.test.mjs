import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8');
const auditList = read('../api/workload/audit-list.php');
const auditAction = read('../api/workload/audit-action.php');
const alertsEndpoint = read('../api/workload/alerts.php');
const alertsService = read('../api/workload/services/WorkloadAlertManagementService.php');
const funnelService = read('../api/workload/services/WorkloadOperatingFunnelService.php');

test('audit queue applies unified scope, pagination, evidence, and history logs', () => {
  assert.match(auditList, /WorkloadPermissionScopeService/);
  assert.match(auditList, /scope_type[^\n]*===?[^\n]*['"]staff['"]/);
  assert.match(auditList, /t\.store_id IN \(/);
  assert.match(auditList, /SELECT COUNT\(DISTINCT t\.id\)/);
  assert.match(auditList, /workload_audit_logs/);
  assert.match(auditList, /audit_logs/);
  assert.match(auditList, /pagination/);
  assert.match(auditList, /evidence_urls/);
  assert.match(auditList, /LEFT JOIN workload_daily_settlements settlement/);
  assert.match(auditList, /LEFT JOIN workload_penalty_records penalty/);
  for (const field of ['daily_target_points', 'daily_effective_points', 'daily_gap_points', 'settlement_status', 'penalty_status']) {
    assert.match(auditList, new RegExp(field));
  }
});

test('audit action checks task scope before the transactional transition', () => {
  assert.match(auditAction, /WorkloadPermissionScopeService/);
  assert.match(auditAction, /SELECT store_id FROM workload_audit_tasks/);
  assert.match(auditAction, /scope_type[^\n]*===?[^\n]*['"]stores['"]/);
  assert.match(auditAction, /WorkloadAuditTaskService\(\$pdo\)\)->transition/);
  assert.match(auditAction, /WorkloadAnalyticsCacheService/);
});

test('alert management supports scoped querying and audited idempotent resolution', () => {
  assert.match(alertsEndpoint, /\['GET', 'POST'\]/);
  assert.match(alertsEndpoint, /action[^\n]*resolve/);
  assert.match(alertsService, /WorkloadPermissionScopeService/);
  assert.match(alertsService, /event\.business_date BETWEEN \? AND \?/);
  assert.match(alertsService, /FOR UPDATE/);
  assert.match(alertsService, /alert\.resolve/);
  assert.match(alertsService, /cache_invalidation_scope/);
  assert.match(alertsService, /idempotent/);
});

test('funnel stages and relation sides expose consistent metric drilldowns', () => {
  assert.match(funnelService, /unset\([\s\S]*metric_code[\s\S]*metric_codes/);
  assert.match(funnelService, /endpoint' => '\/api\/workload\/analytics\/metric-detail\.php'/);
  assert.match(funnelService, /'params' => \['metric_code' => \$metricCode\]/);
  assert.match(funnelService, /'drilldown' => \$this->drilldown/);
});
