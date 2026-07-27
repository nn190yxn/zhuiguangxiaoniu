import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const servicePath = new URL('../api/workload/services/WorkloadEffectiveValueService.php', import.meta.url);
const service = fs.readFileSync(servicePath, 'utf8');

test('effective value service exposes the traceable audit value contract', () => {
  assert.match(service, /class WorkloadEffectiveValueService/);
  assert.match(service, /raw_value/);
  assert.match(service, /pending_value/);
  assert.match(service, /effective_value/);
  assert.match(service, /rejected_value/);
  assert.match(service, /function calculate\(/);
  assert.match(service, /function sqlExpressions\(/);
  assert.match(service, /function aggregateSqlExpressions\(/);
  assert.match(service, /function aggregate\(/);
});

test('full audit semantics separate pending, approved, rejected and missing tasks', () => {
  assert.match(service, /\$isPending = \$isFullAudit && \$taskExists && \$auditStatus === 'pending'/);
  assert.match(service, /\$isApproved = \$isFullAudit && \$taskExists && \$auditStatus === 'approved'/);
  assert.match(service, /\$isRejected = \$isFullAudit && \$taskExists && \$auditStatus === 'rejected'/);
  assert.match(service, /\$isFullAudit \? \(\$isApproved \? \$rawValue : 0\.0\) : \$rawValue/);
  assert.match(service, /'rejected_value' => \$isRejected \? \$rawValue : 0\.0/);
  assert.match(service, /\$taskExists = .*\$taskIdExpression IS NOT NULL/);
});

test('sql projection prefers the bound role rule version and current audit task', () => {
  assert.match(service, /COALESCE\(version_rules\.audit_mode, rules\.audit_mode, 'none'\)/);
  assert.match(service, /string \$taskIdExpression = 't\.id'/);
  assert.match(service, /\$taskExists = "\$taskIdExpression IS NOT NULL"/);
  assert.match(service, /audit_status/);
});

for (const file of [
  '../api/workload/dashboard.php',
  '../api/workload/hq-summary.php',
  '../api/workload/store-summary.php',
  '../api/workload/staff-detail.php',
  '../api/workload/staff-activity.php',
  '../api/workload/audit-list.php',
  '../api/admin/workload/summary.php',
]) {
  test(`${file} consumes the shared effective value service`, () => {
    const source = fs.readFileSync(new URL(file, import.meta.url), 'utf8');
    assert.match(source, /WorkloadEffectiveValueService\.php/);
    assert.match(source, /raw_value/);
    assert.match(source, /pending_value/);
    assert.match(source, /effective_value/);
    assert.match(source, /workload_role_metric_rules version_rules/);
    assert.match(source, /superseded_at IS NULL/);
  });
}
