import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const servicePath = new URL('../api/workload/services/WorkloadAnalyticsQueryService.php', import.meta.url);
const service = fs.readFileSync(servicePath, 'utf8');
const scopeService = fs.readFileSync(
  new URL('../api/workload/services/WorkloadPermissionScopeService.php', import.meta.url),
  'utf8',
);
const sourcePolicy = fs.readFileSync(
  new URL('../api/workload/services/WorkloadSourcePolicyService.php', import.meta.url),
  'utf8',
);

test('[validates 5.1, 9.1] one service owns analytics filters, permission scope, and fact queries', () => {
  assert.match(service, /final class WorkloadAnalyticsQueryService/);
  assert.match(service, /function normalizeFilters\(array \$input\): array/);
  assert.match(service, /function permissionScope\(array \$context\): array/);
  assert.match(service, /function buildFactQuery\(array \$filters, array \$permissionScope\): array/);
  assert.match(service, /function facts\(array \$input, array \$context\): array/);
  assert.match(service, /new WorkloadSourcePolicyService\(\$this->pdo\)/);
  assert.match(service, /WorkloadEffectiveValueService::sqlExpressions\(\)/);
});

test('[validates 5.1, 13.1-13.6] filters cover every fact dimension with bounded values', () => {
  for (const field of [
    'date_from',
    'date_to',
    'store_ids',
    'role_codes',
    'staff_ids',
    'metric_codes',
    'report_statuses',
    'audit_statuses',
    'sources',
  ]) {
    assert.match(service, new RegExp(`'${field}'`));
  }
  assert.match(service, /\['draft', 'submitted'\]/);
  assert.match(service, /\['not_required', 'missing', 'pending', 'approved', 'rejected', 'needs_resubmit'\]/);
  assert.match(service, /日期范围不能超过 366 天/);
  assert.match(service, /normalizeCodeList\([\s\S]*'指标编码'/);
  assert.match(service, /throw new WorkloadAnalyticsQueryException\(\$label \. '格式无效'\)/);
  assert.match(sourcePolicy, /日报来源未登记/);
});

test('[validates 15.1-15.6] permission scope supports own, authorized stores, and all data', () => {
  assert.match(scopeService, /return \$this->scope\('all'/);
  assert.match(scopeService, /return \$this->scope\('stores'/);
  assert.match(scopeService, /return \$this->scope\('staff'/);
  assert.match(scopeService, /!empty\(\$context\['permissions'\]\['can_view_all'\]\)/);
  assert.match(scopeService, /FROM staff_assignments/);
  assert.match(scopeService, /assignment_type IN \('primary', 'secondary'\)/);
  assert.match(scopeService, /start_date <= CURDATE\(\)/);
  assert.match(scopeService, /end_date IS NULL OR end_date >= CURDATE\(\)/);
  assert.match(service, /\$expression \. ' IN \('/);
  assert.match(service, /r\.staff_id = \?/);
});

test('[validates 5.1, 13.4] fact SQL has one row per date, store, staff, role, and metric', () => {
  for (const projection of [
    'r.report_date AS business_date',
    'r.store_id',
    'r.staff_id',
    'r.role_code',
    'm.metric_code',
    'r.submit_status AS report_status',
    'r.source',
    'evidence_count',
    'raw_value',
    'pending_value',
    'effective_value',
    'rejected_value',
  ]) {
    assert.match(service, new RegExp(projection.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
  }
  assert.match(service, /JOIN workload_daily_report_values v ON v\.report_id = r\.id/);
  assert.match(service, /JOIN metric_definitions m ON m\.id = v\.metric_id/);
  assert.match(service, /current_task\.superseded_at IS NULL AND current_task\.audit_status <> 'superseded'/);
  assert.match(service, /GROUP BY evidence\.report_id, evidence\.metric_code/);
  assert.match(service, /ORDER BY r\.report_date, r\.store_id, r\.staff_id, r\.role_code, m\.metric_code/);
});

test('[validates 9.1, 15.1-15.6] every optional predicate is parameterized and permission intersects requested filters', () => {
  assert.match(service, /appendInCondition\(\$where, \$params, 'r\.store_id', \$filters\['store_ids'\]\)/);
  assert.match(service, /appendInCondition\(\$where, \$params, 'r\.role_code', \$filters\['role_codes'\]\)/);
  assert.match(service, /appendInCondition\(\$where, \$params, 'r\.staff_id', \$filters\['staff_ids'\]\)/);
  assert.match(service, /appendInCondition\(\$where, \$params, 'm\.metric_code', \$filters\['metric_codes'\]\)/);
  assert.match(service, /appendInCondition\(\$where, \$params, 'r\.submit_status', \$filters\['report_statuses'\]\)/);
  assert.match(service, /appendInCondition\(\$where, \$params, \$auditStatusExpression, \$filters\['audit_statuses'\]\)/);
  assert.match(service, /appendInCondition\(\$where, \$params, 'r\.source', \$filters\['sources'\]\)/);
  assert.doesNotMatch(service, /\$_GET/);
}
);
