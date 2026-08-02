import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const root = new URL('../', import.meta.url);
const read = (path) => readFileSync(new URL(path, root), 'utf8');

const common = read('api/admin/common.php');

const staffPermissions = [
  'staff.view_all',
  'staff.create',
  'staff.edit',
  'staff.offboard',
  'staff.restore',
  'staff.reset_password',
  'staff.purge',
  'organization.manage',
  'workload.standard_manage',
  'role.manage_privileged',
  'staff.audit_view',
];

const permissionsForRole = (role) => {
  if (role === 'admin') return new Set([...staffPermissions, 'system.settings']);
  if (role === 'operation') return new Set(staffPermissions);
  return new Set();
};

test('permission registry grants equal staff management scope to operation and admin', () => {
  assert.deepEqual(permissionsForRole('operation'), new Set(staffPermissions));
  for (const permission of staffPermissions) {
    assert.match(common, new RegExp(`'${permission.replace('.', '\\.')}'`));
    assert.equal(permissionsForRole('admin').has(permission), true);
  }
});

test('system settings remain exclusive to system administrators', () => {
  assert.equal(permissionsForRole('admin').has('system.settings'), true);
  assert.equal(permissionsForRole('operation').has('system.settings'), false);
  for (const role of ['ceo', 'finance', 'manager', 'sales', 'coach', 'staff']) {
    assert.equal(permissionsForRole(role).size, 0);
  }
  assert.match(common, /\$role === 'admin'/);
  assert.match(common, /\['system\.settings'\]/);
});

test('staff role is authoritative over the WordPress role fallback', () => {
  assert.match(common, /\$staffRole = trim/);
  assert.match(common, /if \(\$staffRole !== ''\)/);
  assert.match(common, /return appRoleCode\(\$staffRole\)/);
  assert.match(common, /return appRoleCode\(\(string\)\(\$user\['role'\]/);
});

test('staff, organization, purge and audit endpoints use named permissions', () => {
  const endpointPermissions = new Map([
    ['api/admin/staff/list.php', 'staff.view_all'],
    ['api/admin/staff/detail.php', 'staff.view_all'],
    ['api/admin/staff/create.php', 'staff.create'],
    ['api/admin/staff/update.php', 'staff.edit'],
    ['api/admin/staff/offboard.php', 'staff.offboard'],
    ['api/admin/staff/restore.php', 'staff.restore'],
    ['api/admin/staff/reset-password.php', 'staff.reset_password'],
    ['api/admin/staff/purge-check.php', 'staff.purge'],
    ['api/admin/staff/purge.php', 'staff.purge'],
    ['api/admin/organization/positions.php', 'organization.manage'],
    ['api/admin/organization/stores.php', 'organization.manage'],
    ['api/admin/organization/tree.php', 'organization.manage'],
    ['api/admin/system/operation-logs.php', 'staff.audit_view'],
    ['api/admin/workload/_standard_common.php', 'workload.standard_manage'],
  ]);

  for (const [path, permission] of endpointPermissions) {
    assert.match(
      read(path),
      new RegExp(`(?:adminRequirePermission|requirePermission)\\('${permission.replace('.', '\\.')}'\\)`),
      path,
    );
  }
});

test('system diagnostics require the system settings permission', () => {
  assert.match(read('api/admin/system/errors.php'), /adminRequirePermission\('system\.settings'\)/);
});
