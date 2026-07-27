import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const directory = readFileSync(new URL('../api/admin/services/StaffDirectoryService.php', import.meta.url), 'utf8');
const listEndpoint = readFileSync(new URL('../api/admin/staff/list.php', import.meta.url), 'utf8');
const legacyListEndpoint = readFileSync(new URL('../api/admin/staff-list.php', import.meta.url), 'utf8');
const detailEndpoint = readFileSync(new URL('../api/admin/staff/detail.php', import.meta.url), 'utf8');

test('staff list supports every required directory filter and bounded pagination', () => {
  for (const field of ['keyword', 'store_id', 'position_id', 'role', 'lifecycle_status', 'include_offboarded', 'page', 'page_size']) {
    assert.match(directory, new RegExp(`['"]${field}['"]`));
  }
  assert.match(directory, /min\(100, max\(1/);
  assert.match(directory, /s\.lifecycle_status <> 'offboarded'/);
  assert.match(directory, /LIMIT \? OFFSET \?/);
});

test('staff directory uses explicit field allowlists', () => {
  assert.doesNotMatch(directory, /SELECT\s+(?:s\.)?\*/i);
  assert.doesNotMatch(directory, /SELECT\s+\*/i);
  for (const field of ['s.id', 's.employee_no', 's.name', 's.phone', 's.lifecycle_status', 's.primary_position_id']) {
    assert.match(directory, new RegExp(field.replace('.', '\\.')));
  }
});

test('staff detail includes assignments, account, business, action, device, and audit summaries', () => {
  for (const key of ['current_assignments', 'assignment_history', 'account_status', 'business_summary', 'available_actions', 'devices', 'recent_login_audits', 'operation_audits']) {
    assert.match(directory, new RegExp(`['"]${key}['"]`));
  }
  assert.match(directory, /FROM staff_assignments a/);
  assert.match(directory, /workload_daily_reports/);
  assert.match(directory, /monthly_statistics/);
  assert.match(directory, /FROM admin_operation_logs l/);
  assert.match(directory, /l\.target_type = \? AND l\.target_id = \?/);
});

test('sensitive directory fields are permission-aware and masked', () => {
  assert.match(directory, /private bool \$canViewSensitive/);
  assert.match(directory, /adminMaskSensitiveValue\(\$value\)/);
  for (const field of ['phone', 'username', 'email', 'device_id', 'device_fingerprint', 'ip_address']) {
    assert.match(directory, new RegExp(field));
  }
  assert.match(listEndpoint, /array_intersect\(\['operation', 'admin'\]/);
});

test('new and legacy list routes share one implementation', () => {
  assert.match(listEndpoint, /new StaffDirectoryService/);
  assert.match(listEndpoint, /\$service->list\(\$_GET\)/);
  assert.match(legacyListEndpoint, /staff\/list\.php/);
});

test('detail route preserves existing response keys through the directory service', () => {
  assert.match(detailEndpoint, /new StaffDirectoryService/);
  assert.match(detailEndpoint, /\$service->detail\(\$staffId\)/);
  assert.match(detailEndpoint, /device_stats/);
  assert.doesNotMatch(detailEndpoint, /ensureLoginAuditTable/);
});
