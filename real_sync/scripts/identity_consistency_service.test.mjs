import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const identity = readFileSync(new URL('../api/admin/services/IdentityConsistencyService.php', import.meta.url), 'utf8');
const lifecycle = readFileSync(new URL('../api/admin/services/StaffLifecycleService.php', import.meta.url), 'utf8');

test('identity consistency service locks the complete staff account role chain', () => {
  assert.match(identity, /SELECT id, user_id, role, session_version FROM staffs WHERE id = \? FOR UPDATE/);
  assert.match(identity, /SELECT ID FROM wp_users WHERE ID = \? FOR UPDATE/);
  assert.match(identity, /meta_key IN \('wp_capabilities', 'wp_user_level'\) FOR UPDATE/);
});

test('role synchronization updates staff, WordPress capabilities, and session version atomically', () => {
  assert.match(identity, /\$ownsTransaction = !\$this->db->inTransaction\(\)/);
  assert.match(identity, /UPDATE staffs SET role = \?, updated_at = NOW\(\)/);
  assert.match(identity, /session_version = session_version \+ 1/);
  assert.match(identity, /'wp_capabilities'/);
  assert.match(identity, /'wp_user_level'/);
  assert.match(identity, /\$this->db->commit\(\)/);
  assert.match(identity, /\$this->db->rollBack\(\)/);
});

test('application roles map to the established WordPress roles', () => {
  assert.match(identity, /\$role === 'admin'[\s\S]*return 'administrator'/);
  assert.match(identity, /\$role === 'manager' \? 'zgxn_store_manager' : 'zgxn_staff'/);
  assert.match(identity, /serialize\(\[\$targetWordPressRole => true\]\)/);
});

test('staff edits force session rotation while restore reuses its existing rotation', () => {
  assert.match(lifecycle, /\$roleChanged = \$data\['role'\] !== \(string\)\$beforeRow\['role'\]/);
  assert.match(lifecycle, /if \(\$roleChanged\)[\s\S]*?synchronizeRole\([\s\S]*?true/);
  assert.match(lifecycle, /public function restore\([\s\S]*?synchronizeRole\([\s\S]*?false/);
  assert.match(lifecycle, /'identity_consistency' => \$identityConsistency/);
});

test('role synchronization model commits all identity values or rolls all values back', () => {
  const initial = { staffRole: 'sales', wordpressRole: 'zgxn_staff', sessionVersion: 4 };
  const synchronize = (targetRole, failureStep = -1) => {
    const state = structuredClone(initial);
    const before = structuredClone(state);
    const wordpressRole = targetRole === 'admin'
      ? 'administrator'
      : targetRole === 'manager' ? 'zgxn_store_manager' : 'zgxn_staff';
    try {
      state.staffRole = targetRole;
      if (failureStep === 0) throw new Error('injected');
      state.wordpressRole = wordpressRole;
      if (failureStep === 1) throw new Error('injected');
      state.sessionVersion += 1;
      if (failureStep === 2) throw new Error('injected');
      return state;
    } catch {
      return before;
    }
  };

  assert.deepEqual(synchronize('manager'), {
    staffRole: 'manager',
    wordpressRole: 'zgxn_store_manager',
    sessionVersion: 5,
  });
  for (let step = 0; step < 3; step += 1) assert.deepEqual(synchronize('admin', step), initial);
});
