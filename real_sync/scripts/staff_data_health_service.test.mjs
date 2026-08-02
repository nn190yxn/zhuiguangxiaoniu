import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const service = readFileSync(
  new URL('../api/admin/services/StaffDataHealthService.php', import.meta.url),
  'utf8',
);
const endpoint = readFileSync(new URL('../api/admin/staff/data-health.php', import.meta.url), 'utf8');
const identity = readFileSync(
  new URL('../api/admin/services/IdentityConsistencyService.php', import.meta.url),
  'utf8',
);

test('data health covers duplicate employee, phone, and account identifiers', () => {
  assert.match(service, /duplicateStaffField\('employee_no'\)/);
  assert.match(service, /duplicateStaffField\('phone'\)/);
  assert.match(service, /duplicateAccounts\(\)/);
  assert.match(service, /GROUP BY \{\$field\} HAVING COUNT\(\*\) > 1/);
  assert.match(service, /GROUP BY s\.user_id, u\.user_login/);
});

test('data health detects invalid active organization references', () => {
  assert.match(service, /invalidOrganizationReference\('store'\)/);
  assert.match(service, /invalidOrganizationReference\('position'\)/);
  assert.match(service, /LEFT JOIN stores ref ON ref\.id = s\.store_id/);
  assert.match(service, /LEFT JOIN organization_positions ref ON ref\.id = s\.primary_position_id/);
  assert.match(service, /ref\.id IS NULL OR ref\.status <> 1/);
  assert.match(service, /s\.lifecycle_status <> 'offboarded'/);
});

test('role health uses the same application to WordPress role mapping', () => {
  for (const role of ['administrator', 'zgxn_store_manager', 'zgxn_staff']) {
    assert.match(service, new RegExp(`'${role}'`));
    assert.match(identity, new RegExp(`'${role}'`));
  }
  assert.match(service, /unserialize\(\$serialized, \['allowed_classes' => false\]\)/);
  assert.match(service, /expected_wordpress_role/);
  assert.match(service, /actual_wordpress_roles/);
});

test('data health reports both sides of orphan staff identity links', () => {
  assert.match(service, /'staff_without_account'/);
  assert.match(service, /'account_without_staff'/);
  assert.match(service, /LEFT JOIN wp_users u ON u\.ID = s\.user_id/);
  assert.match(service, /LEFT JOIN staffs s ON s\.user_id = u\.ID WHERE s\.id IS NULL/);
});

test('data health response is recomputed, summarized, and sensitive-aware', () => {
  assert.match(service, /'checked_at' => date\('c'\)/);
  assert.match(service, /'healthy' => array_sum\(\$counts\) === 0/);
  assert.match(service, /'total_issues' => array_sum\(\$counts\)/);
  assert.match(service, /adminMaskSensitiveValue\(\$value\)/);
  assert.doesNotMatch(service, /INSERT INTO|UPDATE\s+staffs|DELETE FROM/i);
});

test('data health endpoint requires audit permission and returns the service result', () => {
  assert.match(endpoint, /platformApiAuthContext\(\)/);
  assert.match(endpoint, /\$auth->requirePermission\('staff\.audit_view'\)/);
  assert.match(endpoint, /new StaffDataHealthService\(getDB\(\), \$canViewSensitive\)/);
  assert.match(endpoint, /->inspect\(\)/);
  assert.match(endpoint, /PlatformApiCompatibility::withMetadata/);
  assert.match(endpoint, /platformApiResponse\(\$context, \$result\)->send\(\)/);
});
