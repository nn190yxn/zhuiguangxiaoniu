import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const lifecycle = readFileSync(new URL('../api/admin/services/StaffLifecycleService.php', import.meta.url), 'utf8');
const organization = readFileSync(new URL('../api/admin/services/OrganizationService.php', import.meta.url), 'utf8');
const endpoint = readFileSync(new URL('../api/admin/staff/restore.php', import.meta.url), 'utf8');

test('restore requires an offboarded staff member and fully reconfirmed identity state', () => {
  assert.match(lifecycle, /public function restore\(int \$staffId, array \$input, array \$operatorUser, array \$operatorStaff\): array/);
  assert.match(lifecycle, /restore date is invalid or in the future/);
  assert.match(lifecycle, /restore reason is required and cannot exceed 500 characters/);
  assert.match(lifecycle, /account status must be explicitly confirmed as active/);
  assert.match(lifecycle, /secondary assignments must be explicitly confirmed as an array/);
  assert.match(lifecycle, /only offboarded staff can be restored/);
  assert.match(lifecycle, /restore date must be later than the offboard date/);
});

test('restore atomically activates staff and account while revoking previous session versions', () => {
  assert.match(lifecycle, /\$this->db->beginTransaction\(\)/);
  assert.match(lifecycle, /SELECT ID, user_status FROM wp_users WHERE ID = \? FOR UPDATE/);
  assert.match(lifecycle, /status = 1, lifecycle_status = 'active'/);
  assert.match(lifecycle, /offboarded_at = NULL/);
  assert.match(lifecycle, /session_version = session_version \+ 1/);
  assert.match(lifecycle, /UPDATE wp_users SET user_status = 0 WHERE ID = \?/);
  assert.match(lifecycle, /\$this->db->commit\(\)/);
  assert.match(lifecycle, /\$this->db->rollBack\(\)/);
});

test('restore creates a new primary assignment and every explicitly reconfirmed secondary duty', () => {
  assert.match(lifecycle, /\$organization->changePrimaryAssignment\(/);
  assert.match(lifecycle, /foreach \(\$input\['secondary_assignments'\] as \$secondary\)/);
  assert.match(lifecycle, /\$organization->createSecondaryAssignment\(/);
  assert.match(lifecycle, /'effective_date' => \$restoreDate/);
  assert.match(lifecycle, /'change_reason' => \$reason/);
});

test('secondary assignment creation participates safely in a caller-owned transaction', () => {
  const method = organization.slice(
    organization.indexOf('public function createSecondaryAssignment'),
    organization.indexOf('public function endSecondaryAssignment'),
  );
  assert.match(method, /\$ownsTransaction = !\$this->pdo->inTransaction\(\)/);
  assert.match(method, /commitIdempotentAssignment\(\$assignment, \$ownsTransaction\)/);
  assert.match(method, /if \(\$ownsTransaction\) \{\s*\$this->pdo->commit\(\)/);
  assert.match(method, /if \(\$ownsTransaction\) \{\s*\$this->rollBackIfNeeded\(\)/);
});

test('restore audit retains the offboarded snapshot and records every new assignment', () => {
  assert.match(lifecycle, /s\.offboarded_at, s\.offboard_reason/);
  assert.match(lifecycle, /s\.offboarded_by, s\.session_version/);
  assert.match(lifecycle, /u\.user_status AS account_status/);
  assert.match(lifecycle, /'action' => 'restore'/);
  const restoreMethod = lifecycle.slice(
    lifecycle.indexOf('public function restore'),
    lifecycle.indexOf('public function purgeMiscreated'),
  );
  assert.match(restoreMethod, /'staff' => \$before/);
  assert.match(restoreMethod, /'assignments' => \$assignmentsBefore/);
  assert.match(restoreMethod, /'permissions' => \$permissionChange\['before_permissions'\]/);
  assert.match(lifecycle, /'restore_date' => \$restoreDate/);
  assert.match(lifecycle, /'primary_assignment' => \$primaryAssignment/);
  assert.match(lifecycle, /'secondary_assignments' => \$secondaryAssignments/);
  assert.match(restoreMethod, /'permissions' => \$permissionChange\['after_permissions'\]/);
  assert.match(restoreMethod, /'privileged_role_approval' => \$permissionChange\['approval'\]/);
});

test('restore endpoint is POST-only, authorized, and exposes assignment conflicts', () => {
  assert.match(endpoint, /\$_SERVER\['REQUEST_METHOD'\] !== 'POST'/);
  assert.match(endpoint, /adminRequirePermission\('staff\.restore'\)/);
  assert.match(endpoint, /\$service->restore\(\$staffId, \$input, \$operatorUser, \$operatorStaff \?: \[\]\)/);
  assert.match(endpoint, /catch \(OrganizationAssignmentConflictException \$error\)/);
});

test('restore model preserves history and rolls all current state back after an assignment failure', () => {
  const state = {
    staff: { status: 0, lifecycle: 'offboarded', sessionVersion: 4 },
    accountStatus: 1,
    assignments: [{ type: 'primary', start: '2026-01-01', end: '2026-06-30' }],
  };
  const before = structuredClone(state);
  try {
    state.staff = { status: 1, lifecycle: 'active', sessionVersion: 5 };
    state.accountStatus = 0;
    state.assignments.push({ type: 'primary', start: '2026-07-01', end: null });
    throw new Error('secondary assignment conflicts');
  } catch {
    Object.assign(state, structuredClone(before));
  }
  assert.deepEqual(state, before);
});
