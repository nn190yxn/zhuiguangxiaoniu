import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const root = new URL('../', import.meta.url);
const read = (path) => readFileSync(new URL(path, root), 'utf8');
const commonSource = read('api/admin/common.php');

const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;
const permissionByAction = new Map([
  ['view', 'staff.view_all'],
  ['create', 'staff.create'],
  ['edit', 'staff.edit'],
  ['offboard', 'staff.offboard'],
  ['restore', 'staff.restore'],
  ['reset_password', 'staff.reset_password'],
  ['purge', 'staff.purge'],
  ['manage_organization', 'organization.manage'],
  ['manage_privileged_role', 'role.manage_privileged'],
  ['view_audit', 'staff.audit_view'],
]);
const staffPermissions = new Set(permissionByAction.values());

function seededRandom(seed) {
  let state = seed >>> 0;
  return () => {
    state = (Math.imul(state, 1103515245) + 12345) >>> 0;
    return state / 0x100000000;
  };
}

function permissionsFor(role) {
  if (role === 'admin') return new Set([...staffPermissions, 'system.settings']);
  if (role === 'operation') return new Set(staffPermissions);
  return new Set();
}

function manageableStaffIds(role, action, staffDirectory) {
  const permission = permissionByAction.get(action);
  if (!permission || !permissionsFor(role).has(permission)) return [];
  return staffDirectory.map((staff) => staff.id);
}

function randomDirectory(random) {
  const size = 1 + Math.floor(random() * 128);
  return Array.from({ length: size }, (_, index) => ({
    id: index + 1,
    storeId: 1 + Math.floor(random() * 24),
    active: random() >= 0.2,
  }));
}

test(`${validatesCriteria(['20.1', '20.2', 'Property 26'])} operations and administrators manage the same arbitrary employee population`, () => {
  const actions = [...permissionByAction.keys()];
  for (let run = 1; run <= 128; run += 1) {
    const random = seededRandom(0x26000000 + run);
    const staffDirectory = randomDirectory(random);

    for (let step = 0; step < 256; step += 1) {
      const action = actions[Math.floor(random() * actions.length)];
      const operationScope = manageableStaffIds('operation', action, staffDirectory);
      const administratorScope = manageableStaffIds('admin', action, staffDirectory);

      assert.deepEqual(operationScope, administratorScope);
      assert.deepEqual(operationScope, staffDirectory.map((staff) => staff.id));
      const target = staffDirectory[Math.floor(random() * staffDirectory.length)];
      assert.equal(operationScope.includes(target.id), true);
      assert.equal(administratorScope.includes(target.id), true);
    }
  }
});

test(`${validatesCriteria(['20.1', '20.2', 'Property 26'])} employee management scope stays independent of store and lifecycle attributes`, () => {
  const staffDirectory = [
    { id: 1, storeId: 1, active: true },
    { id: 2, storeId: 99, active: false },
    { id: 3, storeId: 7, active: true },
  ];

  for (const action of permissionByAction.keys()) {
    assert.deepEqual(manageableStaffIds('operation', action, staffDirectory), [1, 2, 3]);
    assert.deepEqual(manageableStaffIds('admin', action, staffDirectory), [1, 2, 3]);
  }
});

test(`${validatesCriteria(['20.1', '20.2', 'Property 26'])} system settings extend administrator capability without changing employee scope`, () => {
  assert.deepEqual(permissionsFor('operation'), staffPermissions);
  assert.equal(permissionsFor('operation').has('system.settings'), false);
  assert.equal(permissionsFor('admin').has('system.settings'), true);

  const staffDirectory = [{ id: 1 }, { id: 2 }];
  for (const action of permissionByAction.keys()) {
    assert.deepEqual(
      manageableStaffIds('operation', action, staffDirectory),
      manageableStaffIds('admin', action, staffDirectory),
    );
  }
});

test(`${validatesCriteria(['20.1', '20.2', 'Property 26'])} production permissions and employee endpoints use the shared matrix`, () => {
  for (const permission of staffPermissions) {
    assert.match(commonSource, new RegExp(`'${permission.replace('.', '\\.')}'`));
  }
  assert.match(commonSource, /if \(\$role === 'admin'\)[\s\S]*?array_merge\(\$staffManagement, \['system\.settings'\]\)/);
  assert.match(commonSource, /return \$role === 'operation' \? \$staffManagement : \[\]/);

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
    ['api/admin/staff/privileged-role-confirm.php', 'role.manage_privileged'],
    ['api/admin/system/operation-logs.php', 'staff.audit_view'],
  ]);

  for (const [path, permission] of endpointPermissions) {
    assert.match(read(path), new RegExp(`adminRequirePermission\\('${permission.replace('.', '\\.')}'\\)`), path);
  }
});
