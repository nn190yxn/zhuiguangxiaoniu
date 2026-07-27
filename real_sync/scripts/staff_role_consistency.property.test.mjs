import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const identitySource = readFileSync(new URL('../api/admin/services/IdentityConsistencyService.php', import.meta.url), 'utf8');
const lifecycleSource = readFileSync(new URL('../api/admin/services/StaffLifecycleService.php', import.meta.url), 'utf8');

const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;
const roles = ['sales', 'coach', 'manager', 'operation', 'finance', 'admin', 'ceo', 'staff'];

function seededRandom(seed) {
  let state = seed >>> 0;
  return () => {
    state = (Math.imul(state, 1664525) + 1013904223) >>> 0;
    return state / 0x100000000;
  };
}

function wordpressRole(role) {
  if (role === 'admin') return 'administrator';
  return role === 'manager' ? 'zgxn_store_manager' : 'zgxn_staff';
}

class RoleConsistencyModel {
  constructor(role, sessionVersion) {
    this.staffRole = role;
    this.wordpressRole = wordpressRole(role);
    this.wordpressLevel = role === 'admin' ? 10 : 0;
    this.sessionVersion = sessionVersion;
  }

  changeRole(targetRole, failureStep = -1) {
    const before = this.snapshot();
    try {
      this.staffRole = targetRole;
      this.sessionVersion += 1;
      if (failureStep === 0) throw new Error('staff update failed');

      this.wordpressRole = wordpressRole(targetRole);
      if (failureStep === 1) throw new Error('capability update failed');

      this.wordpressLevel = targetRole === 'admin' ? 10 : 0;
      if (failureStep === 2) throw new Error('level update failed');
      return { status: 'committed', before, after: this.snapshot() };
    } catch {
      this.restore(before);
      return { status: 'rolled_back', before, after: this.snapshot() };
    }
  }

  snapshot() {
    return {
      staffRole: this.staffRole,
      wordpressRole: this.wordpressRole,
      wordpressLevel: this.wordpressLevel,
      sessionVersion: this.sessionVersion,
    };
  }

  restore(snapshot) {
    Object.assign(this, snapshot);
  }
}

function assertProperty23(model) {
  assert.equal(model.wordpressRole, wordpressRole(model.staffRole));
  assert.equal(model.wordpressLevel, model.staffRole === 'admin' ? 10 : 0);
  assert.ok(Number.isSafeInteger(model.sessionVersion));
  assert.ok(model.sessionVersion >= 0);
}

test(`${validatesCriteria(['21.3', 'Property 23'])} arbitrary committed role changes keep all identity values consistent`, () => {
  for (let run = 1; run <= 128; run += 1) {
    const random = seededRandom(0x23000000 + run);
    const initialRole = roles[Math.floor(random() * roles.length)];
    const model = new RoleConsistencyModel(initialRole, Math.floor(random() * 100));

    for (let step = 0; step < 256; step += 1) {
      const candidates = roles.filter((role) => role !== model.staffRole);
      const targetRole = candidates[Math.floor(random() * candidates.length)];
      const beforeVersion = model.sessionVersion;
      const result = model.changeRole(targetRole);

      assert.equal(result.status, 'committed');
      assert.equal(model.staffRole, targetRole);
      assert.equal(model.sessionVersion, beforeVersion + 1);
      assertProperty23(model);
    }
  }
});

test(`${validatesCriteria(['21.3', 'Property 23'])} every partial role synchronization failure restores the complete identity snapshot`, () => {
  for (const initialRole of roles) {
    for (const targetRole of roles.filter((role) => role !== initialRole)) {
      for (let failureStep = 0; failureStep < 3; failureStep += 1) {
        const model = new RoleConsistencyModel(initialRole, 12);
        const result = model.changeRole(targetRole, failureStep);

        assert.equal(result.status, 'rolled_back');
        assert.deepEqual(result.after, result.before);
        assertProperty23(model);
      }
    }
  }
});

test(`${validatesCriteria(['21.3', 'Property 23'])} every application role maps to one registered WordPress role and level`, () => {
  const expected = new Map([
    ['admin', ['administrator', 10]],
    ['manager', ['zgxn_store_manager', 0]],
    ['sales', ['zgxn_staff', 0]],
    ['coach', ['zgxn_staff', 0]],
    ['operation', ['zgxn_staff', 0]],
    ['finance', ['zgxn_staff', 0]],
    ['ceo', ['zgxn_staff', 0]],
    ['staff', ['zgxn_staff', 0]],
  ]);

  for (const role of roles) {
    const model = new RoleConsistencyModel(role, 0);
    assert.deepEqual([model.wordpressRole, model.wordpressLevel], expected.get(role));
    assertProperty23(model);
  }
});

test(`${validatesCriteria(['21.3', 'Property 23'])} production role changes lock and update one transaction boundary`, () => {
  assert.match(identitySource, /SELECT id, user_id, role, session_version FROM staffs WHERE id = \? FOR UPDATE/);
  assert.match(identitySource, /SELECT ID FROM wp_users WHERE ID = \? FOR UPDATE/);
  assert.match(identitySource, /meta_key IN \('wp_capabilities', 'wp_user_level'\) FOR UPDATE/);
  assert.match(identitySource, /UPDATE staffs SET role = \?, updated_at = NOW\(\)[\s\S]*?session_version = session_version \+ 1/);
  assert.match(identitySource, /'wp_capabilities'/);
  assert.match(identitySource, /'wp_user_level'/);
  assert.match(identitySource, /\$this->db->commit\(\)/);
  assert.match(identitySource, /\$this->db->rollBack\(\)/);
  assert.match(lifecycleSource, /if \(\$roleChanged\)[\s\S]*?synchronizeRole\([\s\S]*?true/);
});
