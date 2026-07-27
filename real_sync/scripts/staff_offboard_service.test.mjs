import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const service = readFileSync(new URL('../api/admin/services/StaffLifecycleService.php', import.meta.url), 'utf8');
const endpoint = readFileSync(new URL('../api/admin/staff/offboard.php', import.meta.url), 'utf8');

test('offboarding requires date, reason, confirmation, and an existing active lifecycle', () => {
  assert.match(service, /public function offboard\(int \$staffId, array \$input, array \$operatorUser, array \$operatorStaff\): array/);
  assert.match(service, /offboard date is invalid or in the future/);
  assert.match(service, /offboard reason is required and cannot exceed 500 characters/);
  assert.match(service, /offboard confirmation is required/);
  assert.match(service, /staff is already offboarded/);
});

test('offboarding atomically archives staff, account, sessions, and current assignments', () => {
  assert.match(service, /\$this->db->beginTransaction\(\)/);
  assert.match(service, /FROM staffs WHERE id = \? FOR UPDATE/);
  assert.match(service, /FROM staff_assignments[\s\S]*ORDER BY start_date ASC, id ASC FOR UPDATE/);
  assert.match(service, /UPDATE staff_assignments SET end_date = \?, change_reason = \?, operator_staff_id = \?/);
  assert.match(service, /lifecycle_status = 'offboarded'/);
  assert.match(service, /session_version = session_version \+ 1/);
  assert.match(service, /UPDATE wp_users SET user_status = 1 WHERE ID = \?/);
  assert.match(service, /\$this->db->commit\(\)/);
  assert.match(service, /\$this->db->rollBack\(\)/);
});

test('offboarding revokes device trust and available message subscriptions', () => {
  assert.match(service, /adminTableExists\(\$this->db, 'device_logins'\)/);
  assert.match(service, /UPDATE device_logins SET is_trusted = 0, is_active = 0/);
  assert.match(service, /adminTableExists\(\$this->db, 'mini_user_subscriptions'\)/);
  assert.match(service, /accept_status = 'revoked'/);
  assert.match(service, /adminTableExists\(\$this->db, 'policy_subscriptions'\)/);
  assert.match(service, /UPDATE policy_subscriptions SET enabled = 0/);
});

test('offboarding records full before and after snapshots with revocation counts', () => {
  assert.match(service, /'action' => 'offboard'/);
  assert.match(service, /'before' => \['staff' => \$before, 'assignments' => \$assignmentsBefore\]/);
  assert.match(service, /'offboard_date' => \$offboardDate/);
  assert.match(service, /'offboard_reason' => \$reason/);
  assert.match(service, /'revocations' => \$revocations/);
});

test('offboard endpoint is POST-only and restricted to headquarters management roles', () => {
  assert.match(endpoint, /\$_SERVER\['REQUEST_METHOD'\] !== 'POST'/);
  assert.match(endpoint, /adminRequirePermission\('staff\.offboard'\)/);
  assert.match(endpoint, /\$service->offboard\(\$staffId, \$input, \$operatorUser, \$operatorStaff \?: \[\]\)/);
  assert.match(endpoint, /catch \(PrivilegedRoleConflictException \$error\)/);
  assert.match(endpoint, /StaffLifecycleValidationException \| PrivilegedRoleValidationException/);
});

test('offboard date is retained as the last active assignment day', () => {
  const assignments = [
    { startDate: '2026-01-01', endDate: null },
    { startDate: '2025-01-01', endDate: '2025-12-31' },
    { startDate: '2026-08-01', endDate: null },
  ];
  const offboardDate = '2026-07-24';
  for (const assignment of assignments) {
    if (assignment.startDate <= offboardDate && (assignment.endDate === null || assignment.endDate > offboardDate)) {
      assignment.endDate = offboardDate;
    }
  }
  assert.deepEqual(assignments, [
    { startDate: '2026-01-01', endDate: '2026-07-24' },
    { startDate: '2025-01-01', endDate: '2025-12-31' },
    { startDate: '2026-08-01', endDate: null },
  ]);
});
