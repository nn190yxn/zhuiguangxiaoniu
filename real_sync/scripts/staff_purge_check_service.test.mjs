import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const service = readFileSync(new URL('../api/admin/services/StaffAssociationService.php', import.meta.url), 'utf8');
const endpoint = readFileSync(new URL('../api/admin/staff/purge-check.php', import.meta.url), 'utf8');

test('association inspector covers every required business category', () => {
  assert.match(service, /public function inspectForPurge\([\s\S]*bool \$issueToken = true[\s\S]*\): array/);
  for (const category of [
    'identity_baseline', 'login_devices', 'workload', 'learning_pass', 'drill_review',
    'notifications_messages', 'points', 'other_business', 'audit_actor_history',
  ]) {
    assert.match(service, new RegExp(`'category' => '${category}'`));
  }
  for (const table of [
    'device_logins', 'login_audit_logs', 'workload_daily_reports', 'workload_audit_tasks',
    'user_course_progress', 'exam_records', 'user_pass_progress', 'drill_records',
    'policy_notifications', 'mini_user_notifications', 'wecom_message_logs',
  ]) {
    assert.match(service, new RegExp(`'table' => '${table}'`));
  }
});

test('optional missing tables and incompatible schemas remain distinguishable', () => {
  assert.match(service, /adminTableExists\(\$this->db, \$table\)/);
  assert.match(service, /'status' => 'absent'/);
  assert.match(service, /adminColumnExists\(\$this->db, \$table, \$column\)/);
  assert.match(service, /'status' => 'schema_incompatible'/);
  assert.match(service, /\$complete = false/);
});

test('expected primary identity baseline is allowed while extra or secondary assignments block purge', () => {
  assert.match(service, /'condition' => "assignment_type = 'primary'", 'allowance' => 1/);
  assert.match(service, /'condition' => "assignment_type = 'secondary'"/);
  assert.match(service, /max\(0, \$count - \(int\)\(\$spec\['allowance'\] \?\? 0\)\)/);
});

test('operator, reviewer, paired account, and organization references are covered', () => {
  for (const field of [
    "'table' => 'workload_metric_versions', 'column' => 'created_by_staff_id'",
    "'table' => 'workload_alert_events', 'column' => 'handled_by_staff_id'",
    "'table' => 'workload_report_corrections', 'column' => 'operated_by_staff_id'",
    "'table' => 'workload_audit_logs', 'column' => 'operator_staff_id'",
    "'table' => 'staff_import_batches', 'column' => 'requested_by_staff_id'",
    "'table' => 'staff_profile_correction_requests', 'column' => 'handled_by_staff_id'",
    "'table' => 'skill_review_records', 'column' => 'user_id'",
    "'table' => 'mini_reminder_jobs', 'column' => 'target_user_id'",
    "'table' => 'admin_operation_logs', 'column' => 'operator_user_id'",
    "'table' => 'staffs', 'column' => 'offboarded_by'",
  ]) {
    assert.match(service, new RegExp(field));
  }
});

test('confirmation token is only issued after a complete zero-association result', () => {
  assert.match(service, /\$eligible = \$complete && \$blockingTotal === 0/);
  assert.match(service, /if \(\$eligible && \$issueToken\) \{/);
  assert.match(service, /\$this->issueConfirmationToken\(/);
  assert.match(service, /'recommendation' => \$eligible \? 'purge' : 'offboard'/);
});

test('confirmation token is short-lived, action-scoped, identity-bound, and signed', () => {
  assert.match(service, /private const TOKEN_TTL_SECONDS = 300/);
  assert.match(service, /'typ' => 'STAFF_PURGE_CONFIRM'/);
  assert.match(service, /'action' => 'purge_miscreated_staff'/);
  assert.match(service, /'operator_user_id' => \$operatorUserId/);
  assert.match(service, /'operator_staff_id' => \$operatorStaffId/);
  assert.match(service, /'staff_session_version' => \(int\)\$staff\['session_version'\]/);
  assert.match(service, /'association_digest' => \$associationDigest/);
  assert.match(service, /bin2hex\(random_bytes\(16\)\)/);
  assert.match(service, /hash_hmac\('sha256', 'staff-purge-confirm-v1', JWT_SECRET, true\)/);
});

test('purge check endpoint is POST-only and restricted to purge-authorized roles', () => {
  assert.match(endpoint, /\$_SERVER\['REQUEST_METHOD'\] !== 'POST'/);
  assert.match(endpoint, /adminRequirePermission\('staff\.purge'\)/);
  assert.match(endpoint, /\$service->inspectForPurge\(\$staffId, \$operatorUser, \$operatorStaff \?: \[\]\)/);
});

test('association state model never issues a token for blockers or incomplete checks', () => {
  const eligibility = (complete, counts) => complete && Object.values(counts).every((count) => count === 0);
  assert.equal(eligibility(true, { login: 0, workload: 0, messages: 0 }), true);
  assert.equal(eligibility(true, { login: 1, workload: 0, messages: 0 }), false);
  assert.equal(eligibility(false, { login: 0, workload: 0, messages: 0 }), false);
});
