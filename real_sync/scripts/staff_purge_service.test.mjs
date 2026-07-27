import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const association = readFileSync(new URL('../api/admin/services/StaffAssociationService.php', import.meta.url), 'utf8');
const lifecycle = readFileSync(new URL('../api/admin/services/StaffLifecycleService.php', import.meta.url), 'utf8');
const endpoint = readFileSync(new URL('../api/admin/staff/purge.php', import.meta.url), 'utf8');
const checkEndpoint = readFileSync(new URL('../api/admin/staff/purge-check.php', import.meta.url), 'utf8');

test('purge requires an explicit reason, confirmation, token, and separate operator identity', () => {
  assert.match(lifecycle, /public function purgeMiscreated\(int \$staffId, array \$input, array \$operatorUser, array \$operatorStaff\): array/);
  assert.match(lifecycle, /purge reason is required and cannot exceed 500 characters/);
  assert.match(lifecycle, /purge confirmation is required/);
  assert.match(lifecycle, /purge confirmation token is required/);
  assert.match(lifecycle, /operators cannot purge their own staff identity/);
  assert.match(lifecycle, /\$userId === \$operatorUserId/);
});

test('purge locks identity records and repeats the association check inside one transaction', () => {
  assert.match(lifecycle, /\$this->db->beginTransaction\(\)/);
  assert.match(lifecycle, /\$this->lockStaffForUpdate\(\$staffId\)/);
  assert.match(lifecycle, /FROM wp_users WHERE ID = \? FOR UPDATE/);
  assert.match(lifecycle, /\$this->lockAllStaffAssignments\(\$staffId\)/);
  assert.match(lifecycle, /->inspectForPurge\([\s\S]*false[\s\S]*\)/);
  assert.match(lifecycle, /if \(!\$associationSummary\['eligible_for_purge'\]\)/);
  assert.match(lifecycle, /StaffPurgeBlockedException/);
});

test('confirmation token validation rejects tampering, expiry, actor changes, and state changes', () => {
  assert.match(association, /public function validateConfirmationToken\(/);
  assert.match(association, /hash_equals\(\$expectedSignature, \$providedSignature\)/);
  assert.match(association, /'operator_user_id'/);
  assert.match(association, /'operator_staff_id'/);
  assert.match(association, /'linked_user_id'/);
  assert.match(association, /'staff_session_version'/);
  assert.match(association, /'association_digest'/);
  assert.match(association, /\(int\)\(\$payload\['exp'\] \?\? 0\) > \$now/);
});

test('purge removes only the identity chain and validates deletion counts', () => {
  const expectedDeletes = [
    'DELETE FROM staff_assignments WHERE staff_id = ?',
    'DELETE FROM staffs WHERE id = ?',
    'DELETE FROM wp_usermeta WHERE user_id = ?',
    'DELETE FROM wp_users WHERE ID = ?',
  ];
  for (const statement of expectedDeletes) assert.match(lifecycle, new RegExp(statement.replaceAll('?', '\\?')));
  assert.match(lifecycle, /assignment purge count changed during the transaction/);
  assert.match(lifecycle, /staff purge failed/);
  assert.match(lifecycle, /account purge failed/);
  assert.doesNotMatch(lifecycle, /DELETE FROM (workload|exam|policy|login|device)/);
});

test('purge preserves a complete audit snapshot without storing the confirmation token', () => {
  assert.match(lifecycle, /'action' => 'purge_miscreated'/);
  assert.match(lifecycle, /'staff' => \$staffBefore/);
  assert.match(lifecycle, /'account' => \$account/);
  assert.match(lifecycle, /'assignments' => \$assignmentsBefore/);
  assert.match(lifecycle, /'association_summary' => \$associationSummary/);
  assert.match(lifecycle, /'purge_reason' => \$reason/);
  assert.match(lifecycle, /'confirmation_jti' => \$tokenPayload\['jti'\]/);
  assert.doesNotMatch(lifecycle, /'confirmation_token' => \$token/);
  assert.match(lifecycle, /\$this->db->commit\(\)/);
  assert.match(lifecycle, /\$this->db->rollBack\(\)/);
});

test('check and purge endpoints restrict access to operation and admin roles', () => {
  for (const source of [checkEndpoint, endpoint]) {
    assert.match(source, /adminRequirePermission\('staff\.purge'\)/);
  }
  assert.match(endpoint, /\$service->purgeMiscreated\(\$staffId, \$input, \$operatorUser, \$operatorStaff \?: \[\]\)/);
  assert.match(endpoint, /catch \(StaffPurgeBlockedException \$error\)/);
  assert.match(endpoint, /'recommendation' => 'offboard'/);
});

test('purge state model rolls back every partial identity deletion', () => {
  const initial = { staff: 1, assignments: 1, account: 1, meta: 2, audits: 1 };
  const run = (failureStep) => {
    const state = structuredClone(initial);
    const before = structuredClone(state);
    try {
      for (const [index, key] of ['assignments', 'staff', 'meta', 'account'].entries()) {
        state[key] = 0;
        if (index === failureStep) throw new Error('injected');
      }
      state.audits += 1;
      return state;
    } catch {
      return before;
    }
  };
  assert.deepEqual(run(-1), { staff: 0, assignments: 0, account: 0, meta: 0, audits: 2 });
  for (let step = 0; step < 4; step += 1) assert.deepEqual(run(step), initial);
});
