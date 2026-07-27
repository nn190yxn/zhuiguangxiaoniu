import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const root = new URL('../', import.meta.url);
const read = (path) => readFileSync(new URL(path, root), 'utf8');
const guard = read('api/admin/services/PrivilegedRoleGuard.php');
const lifecycle = read('api/admin/services/StaffLifecycleService.php');
const confirmationEndpoint = read('api/admin/staff/privileged-role-confirm.php');
const updateEndpoint = read('api/admin/staff/update.php');
const offboardEndpoint = read('api/admin/staff/offboard.php');
const restoreEndpoint = read('api/admin/staff/restore.php');
const toggleEndpoint = read('api/admin/staff-toggle-status.php');

class PrivilegedRoleModel {
  constructor(staff) {
    this.staff = new Map(staff.map((item) => [item.id, structuredClone(item)]));
    this.approvals = new Map();
    this.audits = [];
  }

  issue({ requesterId, approverId, targetId, toRole, now = 1000 }) {
    const requester = this.staff.get(requesterId);
    const approver = this.staff.get(approverId);
    const target = this.staff.get(targetId);
    assert.ok(requester?.active && ['operation', 'admin'].includes(requester.role));
    assert.ok(approver?.active && approver.role === 'admin');
    assert.notEqual(requesterId, approverId, 'approval must come from another administrator');
    assert.ok(target.role === 'admin' || toRole === 'admin');
    const token = `approval-${this.approvals.size + 1}`;
    this.approvals.set(token, {
      requesterId,
      approverId,
      targetId,
      fromRole: target.role,
      toRole,
      sessionVersion: target.sessionVersion,
      expiresAt: now + 300,
    });
    return token;
  }

  change({ requesterId, targetId, toRole, token, active, now = 1000 }) {
    const target = this.staff.get(targetId);
    const nextActive = active ?? target.active;
    const removesActiveAdmin = target.role === 'admin'
      && target.active
      && (toRole !== 'admin' || !nextActive);
    const activeAdmins = [...this.staff.values()].filter((item) => item.active && item.role === 'admin');
    if (removesActiveAdmin) assert.ok(activeAdmins.length > 1, 'last administrator protected');

    if (target.role !== toRole && (target.role === 'admin' || toRole === 'admin')) {
      const approval = this.approvals.get(token);
      assert.deepEqual(approval, {
        requesterId,
        approverId: approval?.approverId,
        targetId,
        fromRole: target.role,
        toRole,
        sessionVersion: target.sessionVersion,
        expiresAt: approval?.expiresAt,
      });
      assert.notEqual(approval.approverId, requesterId);
      assert.ok(approval.expiresAt >= now);
    }

    const beforePermissions = permissions(target.role);
    target.role = toRole;
    target.active = nextActive;
    target.sessionVersion += 1;
    this.audits.push({ beforePermissions, afterPermissions: permissions(toRole), approverId: this.approvals.get(token)?.approverId ?? null });
  }
}

const permissions = (role) => role === 'admin'
  ? ['role.manage_privileged', 'system.settings']
  : role === 'operation' ? ['role.manage_privileged'] : [];

const staffFixture = () => [
  { id: 1, role: 'operation', active: true, sessionVersion: 1 },
  { id: 2, role: 'admin', active: true, sessionVersion: 1 },
  { id: 3, role: 'admin', active: true, sessionVersion: 1 },
];

test('confirmation tokens bind requester, approver, target, role transition, session version, and expiry', () => {
  for (const field of [
    'requester_user_id',
    'requester_staff_id',
    'approver_user_id',
    'approver_staff_id',
    'target_staff_id',
    'target_session_version',
    'from_role',
    'to_role',
    'nbf',
    'exp',
    'jti',
  ]) {
    assert.match(guard, new RegExp(`'${field}'`));
  }
  assert.match(guard, /hash_hmac\('sha256'/);
  assert.match(guard, /hash_equals\(\$expected, \$signature\)/);
  assert.match(guard, /TOKEN_TTL_SECONDS = 300/);
});

test('a different active system administrator must authorize self-promotion', () => {
  const model = new PrivilegedRoleModel(staffFixture());
  assert.throws(() => model.issue({ requesterId: 1, approverId: 1, targetId: 1, toRole: 'admin' }));
  const token = model.issue({ requesterId: 1, approverId: 2, targetId: 1, toRole: 'admin' });
  model.change({ requesterId: 1, targetId: 1, toRole: 'admin', token });
  assert.equal(model.staff.get(1).role, 'admin');
  assert.equal(model.audits[0].approverId, 2);
  assert.match(guard, /a different system administrator must approve this change/);
});

test('confirmation tokens cannot authorize a different transition or stale staff state', () => {
  const model = new PrivilegedRoleModel(staffFixture());
  const token = model.issue({ requesterId: 1, approverId: 2, targetId: 1, toRole: 'admin' });
  assert.throws(() => model.change({ requesterId: 1, targetId: 3, toRole: 'operation', token }));
  model.staff.get(1).sessionVersion += 1;
  assert.throws(() => model.change({ requesterId: 1, targetId: 1, toRole: 'admin', token }));
});

test('the last active administrator is protected from demotion, disablement, and offboarding', () => {
  for (const operation of ['demote', 'disable', 'offboard']) {
    const fixture = staffFixture().filter((item) => item.id !== 3);
    const model = new PrivilegedRoleModel(fixture);
    const token = operation === 'demote'
      ? model.issue({ requesterId: 1, approverId: 2, targetId: 2, toRole: 'operation' })
      : undefined;
    assert.throws(() => model.change({
      requesterId: 1,
      targetId: 2,
      toRole: operation === 'demote' ? 'operation' : 'admin',
      active: operation === 'demote' ? true : false,
      token,
    }), /last administrator protected/);
  }
  assert.match(guard, /ORDER BY id FOR UPDATE/);
  assert.match(guard, /last active system administrator cannot be disabled, offboarded, or demoted/);
});

test('one administrator can be removed when another active administrator remains', () => {
  const model = new PrivilegedRoleModel(staffFixture());
  const token = model.issue({ requesterId: 1, approverId: 3, targetId: 2, toRole: 'operation' });
  model.change({ requesterId: 1, targetId: 2, toRole: 'operation', token });
  assert.equal(model.staff.get(2).role, 'operation');
  assert.equal([...model.staff.values()].filter((item) => item.active && item.role === 'admin').length, 1);
});

test('lifecycle transactions enforce the guard and retain permission and approval snapshots', () => {
  assert.match(lifecycle, /require_once __DIR__ \. '\/PrivilegedRoleGuard\.php'/);
  assert.match(lifecycle, /->protectLastAdministrator\(/);
  assert.match(lifecycle, /->assertRoleChangeAllowed\(/);
  assert.match(lifecycle, /'permissions' => \$permissionChange\['before_permissions'\]/);
  assert.match(lifecycle, /'permissions' => \$permissionChange\['after_permissions'\]/);
  assert.match(lifecycle, /'privileged_role_approval' => \$permissionChange\['approval'\]/);
});

test('confirmation and lifecycle endpoints expose named authorization and protected conflicts', () => {
  assert.match(confirmationEndpoint, /adminRequirePermission\('role\.manage_privileged'\)/);
  assert.match(confirmationEndpoint, /->issueConfirmation\(/);
  for (const endpoint of [updateEndpoint, offboardEndpoint, restoreEndpoint, toggleEndpoint]) {
    assert.match(endpoint, /catch \(PrivilegedRoleConflictException \$error\)/);
    assert.match(endpoint, /jsonResponse\(409, \$error->getMessage\(\), null\)/);
  }
  assert.match(toggleEndpoint, /new StaffLifecycleService\(getDB\(\)\)/);
  assert.doesNotMatch(toggleEndpoint, /UPDATE staffs SET status/);
});
