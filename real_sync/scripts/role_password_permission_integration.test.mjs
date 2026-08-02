import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const root = new URL('../', import.meta.url);
const read = (path) => readFileSync(new URL(path, root), 'utf8');

const lifecycle = read('api/admin/services/StaffLifecycleService.php');
const identity = read('api/admin/services/IdentityConsistencyService.php');
const guard = read('api/admin/services/PrivilegedRoleGuard.php');
const common = read('api/admin/common.php');
const config = read('api/config.php');
const create = read('api/admin/staff/create.php');
const reset = read('api/admin/staff/reset-password.php');

const staffPermissions = [
  'staff.view_all',
  'staff.create',
  'staff.edit',
  'staff.offboard',
  'staff.restore',
  'staff.reset_password',
  'staff.purge',
  'organization.manage',
  'role.manage_privileged',
  'staff.audit_view',
];

const permissionsFor = (role) => new Set(
  role === 'admin'
    ? [...staffPermissions, 'system.settings']
    : role === 'operation' ? staffPermissions : [],
);

const wordpressRoleFor = (role) => {
  if (role === 'admin') return 'administrator';
  return role === 'manager' ? 'zgxn_store_manager' : 'zgxn_staff';
};

const acceptsPassword = (password, minimumLength = 10) => password.length >= minimumLength
  && /[a-z]/.test(password)
  && /[A-Z]/.test(password)
  && /\d/.test(password)
  && /[^A-Za-z0-9]/.test(password);

const canUseSession = (token, staff) => staff.status === 1
  && staff.lifecycle === 'active'
  && token.sessionVersion === staff.sessionVersion;

const synchronizeRole = (staff, role) => ({
  ...staff,
  role,
  wordpressRole: wordpressRoleFor(role),
  sessionVersion: staff.sessionVersion + 1,
});

test('headquarters operations and administrators share the complete employee management matrix', () => {
  assert.deepEqual(permissionsFor('operation'), new Set(staffPermissions));
  assert.deepEqual(
    [...permissionsFor('admin')].filter((permission) => permission !== 'system.settings'),
    staffPermissions,
  );
  assert.equal(permissionsFor('admin').has('system.settings'), true);
  assert.equal(permissionsFor('operation').has('system.settings'), false);
  assert.match(common, /if \(\$role === 'admin'\)[\s\S]*?return array_merge\(\$staffManagement, \$recruitmentManagement, \$policyManagement, \$operationalManagement, \$legacyEndpointGovernance, \['system\.settings'\]\)/);
  assert.match(common, /\$policyManagement = \['policy\.notify_send'\]/);
  assert.match(common, /if \(\$role === 'operation'\)[\s\S]*?return array_merge\(\$staffManagement, \$recruitmentOperation, \$operationalManagement\)/);
});

test('role changes synchronize both identity stores and revoke the previous session', () => {
  const before = {
    role: 'sales',
    wordpressRole: 'zgxn_staff',
    sessionVersion: 7,
    status: 1,
    lifecycle: 'active',
  };
  const oldToken = { sessionVersion: before.sessionVersion };
  const after = synchronizeRole(before, 'manager');

  assert.deepEqual(after, {
    role: 'manager',
    wordpressRole: 'zgxn_store_manager',
    sessionVersion: 8,
    status: 1,
    lifecycle: 'active',
  });
  assert.equal(canUseSession(oldToken, before), true);
  assert.equal(canUseSession(oldToken, after), false);
  assert.match(lifecycle, /if \(\$roleChanged\)[\s\S]*?synchronizeRole\([\s\S]*?true/);
  assert.match(identity, /UPDATE staffs SET role = \?, updated_at = NOW\(\)/);
  assert.match(identity, /session_version = session_version \+ 1/);
  assert.match(config, /array_key_exists\('session_version', \$payload\)/);
});

test('new accounts use the same role mapper and password policy as later changes', () => {
  assert.equal(wordpressRoleFor('admin'), 'administrator');
  assert.equal(wordpressRoleFor('manager'), 'zgxn_store_manager');
  assert.equal(wordpressRoleFor('sales'), 'zgxn_staff');
  assert.equal(acceptsPassword('Strong#1234'), true);
  for (const password of ['Short#1', 'lowercase#123', 'UPPERCASE#123', 'NoNumber###', 'NoSpecial123']) {
    assert.equal(acceptsPassword(password), false);
  }

  assert.match(lifecycle, /PasswordPolicy::validate\(\$data\['initial_password'\]\)/);
  assert.match(lifecycle, /createStaff\([\s\S]*?synchronizeRole\([\s\S]*?false/);
  assert.match(identity, /\$role === 'admin'[\s\S]*?return 'administrator'/);
  assert.match(create, /PasswordPolicyValidationException/);
  assert.match(create, /jsonResponse\(400/);
});

test('administrator password reset rejects weak passwords and revokes old sessions', () => {
  const staff = { status: 1, lifecycle: 'active', sessionVersion: 3 };
  const oldToken = { sessionVersion: 3 };
  assert.equal(acceptsPassword('reset123'), false);
  assert.equal(canUseSession(oldToken, staff), true);

  const afterReset = { ...staff, sessionVersion: staff.sessionVersion + 1 };
  assert.equal(canUseSession(oldToken, afterReset), false);
  assert.match(reset, /PasswordPolicy::validate\(\$newPassword\)/);
  assert.match(reset, /session_version = session_version \+ 1/);
});

test('self-promotion requires approval from a different active administrator', () => {
  const requester = { userId: 20, staffId: 2, role: 'operation' };
  const approver = { userId: 10, staffId: 1, role: 'admin' };
  const target = { id: requester.staffId, role: requester.role, sessionVersion: 4 };
  const approval = {
    requesterUserId: requester.userId,
    requesterStaffId: requester.staffId,
    approverUserId: approver.userId,
    targetStaffId: target.id,
    targetSessionVersion: target.sessionVersion,
    fromRole: target.role,
    toRole: 'admin',
  };

  assert.notEqual(approval.approverUserId, approval.requesterUserId);
  assert.equal(approver.role, 'admin');
  assert.match(guard, /\$requesterUserId === \$approverUserId/);
  assert.match(guard, /a different system administrator must approve this change/);
  assert.match(guard, /target_session_version/);
  assert.match(guard, /from_role/);
  assert.match(guard, /to_role/);
});

test('the final active administrator remains protected across every removal path', () => {
  const activeAdministrators = [{ id: 1, role: 'admin', status: 1, lifecycle: 'active' }];
  const removesFinalAdministrator = (targetRole, status, lifecycle) => {
    const target = activeAdministrators[0];
    return target.role === 'admin'
      && target.status === 1
      && target.lifecycle === 'active'
      && (targetRole !== 'admin' || status !== 1 || lifecycle !== 'active')
      && activeAdministrators.length === 1;
  };

  assert.equal(removesFinalAdministrator('sales', 1, 'active'), true);
  assert.equal(removesFinalAdministrator('admin', 0, 'inactive'), true);
  assert.equal(removesFinalAdministrator('admin', 0, 'offboarded'), true);
  assert.match(guard, /count\(\$activeAdministratorIds\) <= 1/);
  assert.match(guard, /last active system administrator cannot be disabled, offboarded, or demoted/);
  assert.match(lifecycle, /public function offboard[\s\S]*?protectLastAdministrator/);
});
