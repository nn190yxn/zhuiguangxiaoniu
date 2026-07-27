import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const lifecycleService = readFileSync(new URL('../api/admin/services/StaffLifecycleService.php', import.meta.url), 'utf8');
const organizationMigration = readFileSync(new URL('../database/migrations/202607240001_staff_organization.sql', import.meta.url), 'utf8');
const migrationManifest = readFileSync(new URL('../database/migration_manifest.php', import.meta.url), 'utf8');

const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;

function seededRandom(seed) {
  let state = seed >>> 0;
  return () => {
    state = (Math.imul(state, 1103515245) + 12345) >>> 0;
    return state / 0x100000000;
  };
}

class StaffAccountIdentityModel {
  constructor() {
    this.accountByStaff = new Map();
    this.staffByAccount = new Map();
  }

  link(staffId, accountId, commit = true) {
    if (this.accountByStaff.has(staffId)) {
      return { status: 'staff_conflict', staffId, accountId };
    }
    if (this.staffByAccount.has(accountId)) {
      return { status: 'account_conflict', staffId, accountId };
    }
    if (!commit) {
      return { status: 'rolled_back', staffId, accountId };
    }
    this.accountByStaff.set(staffId, accountId);
    this.staffByAccount.set(accountId, staffId);
    return { status: 'linked', staffId, accountId };
  }
}

function assertProperty20(model) {
  assert.equal(model.accountByStaff.size, model.staffByAccount.size);
  for (const [staffId, accountId] of model.accountByStaff) {
    assert.equal(model.staffByAccount.get(accountId), staffId);
  }
  for (const [accountId, staffId] of model.staffByAccount) {
    assert.equal(model.accountByStaff.get(staffId), accountId);
  }
  assert.equal(new Set(model.accountByStaff.values()).size, model.accountByStaff.size);
  assert.equal(new Set(model.staffByAccount.values()).size, model.staffByAccount.size);
}

test(`${validatesCriteria(["17.6", "21.3", "Property 20"])} arbitrary identity operations preserve one-to-one links`, () => {
  for (let seed = 1; seed <= 128; seed++) {
    const random = seededRandom(seed);
    const model = new StaffAccountIdentityModel();

    for (let step = 0; step < 256; step++) {
      const staffId = 1 + Math.floor(random() * 96);
      const accountId = 1001 + Math.floor(random() * 96);
      const shouldCommit = random() >= 0.15;
      const beforeStaffLinks = model.accountByStaff.size;
      const beforeAccountLinks = model.staffByAccount.size;
      const result = model.link(staffId, accountId, shouldCommit);

      if (result.status !== 'linked') {
        assert.equal(model.accountByStaff.size, beforeStaffLinks);
        assert.equal(model.staffByAccount.size, beforeAccountLinks);
      }
      assertProperty20(model);
    }
  }
});

test(`${validatesCriteria(["17.6", "Property 20"])} one account cannot be linked to multiple staff profiles`, () => {
  const model = new StaffAccountIdentityModel();
  assert.equal(model.link(1, 1001).status, 'linked');
  for (let staffId = 2; staffId <= 100; staffId++) {
    assert.equal(model.link(staffId, 1001).status, 'account_conflict');
    assertProperty20(model);
  }
  assert.equal(model.staffByAccount.get(1001), 1);
});

test(`${validatesCriteria(["17.6", "Property 20"])} one staff profile cannot be linked to multiple accounts`, () => {
  const model = new StaffAccountIdentityModel();
  assert.equal(model.link(1, 1001).status, 'linked');
  for (let accountId = 1002; accountId <= 1100; accountId++) {
    assert.equal(model.link(1, accountId).status, 'staff_conflict');
    assertProperty20(model);
  }
  assert.equal(model.accountByStaff.get(1), 1001);
});

test(`${validatesCriteria(["17.6", "21.3", "Property 20"])} rolled-back links leave both identity sides reusable`, () => {
  const model = new StaffAccountIdentityModel();
  assert.deepEqual(model.link(7, 1007, false), { status: 'rolled_back', staffId: 7, accountId: 1007 });
  assertProperty20(model);
  assert.equal(model.link(7, 1008).status, 'linked');
  assert.equal(model.link(8, 1007).status, 'linked');
  assertProperty20(model);
});

test(`${validatesCriteria(["17.6", "21.3", "Property 20"])} production contracts enforce account identity cardinality`, () => {
  assert.match(organizationMigration, /UNIQUE KEY uq_staffs_user_id \(user_id\)/);
  assert.match(migrationManifest, /'staffs' => \['uq_staffs_employee_no', 'uq_staffs_user_id'\]/);
  assert.match(lifecycleService, /\$userId = \$this->createWordPressUser\(\$data\)/);
  assert.match(lifecycleService, /createStaff\(\$data, \$position, \$userId\)/);
  assert.match(lifecycleService, /\(store_id, user_id, employee_no/);
  assert.match(lifecycleService, /if \(\$this->db->inTransaction\(\)\)/);
  assert.match(lifecycleService, /\$this->db->rollBack\(\)/);
});
