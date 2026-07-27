import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const service = readFileSync(new URL('../api/admin/services/StaffProfileService.php', import.meta.url), 'utf8');
const profile = readFileSync(new URL('../api/staff/profile.php', import.meta.url), 'utf8');
const employeeCorrections = readFileSync(new URL('../api/staff/profile-corrections.php', import.meta.url), 'utf8');
const adminCorrections = readFileSync(new URL('../api/admin/staff/profile-corrections.php', import.meta.url), 'utf8');

test('self profile exposes required identity, organization, secondary duty, and account fields', () => {
  for (const field of ['employee_no', 'name', 'entry_date', 'store', 'primary_position', 'secondary_assignments', 'account']) {
    assert.match(service, new RegExp(`'${field}'`));
  }
  assert.match(profile, /getCurrentUserId\(\)/);
  assert.match(profile, /getStaffByUserId\(\$userId\)/);
  assert.match(profile, /->profile\(\(int\)\$staff\['id'\]\)/);
});

test('employees can only submit supported changed profile fields for themselves', () => {
  assert.match(service, /CORRECTABLE_FIELDS = \['name', 'phone', 'store_id', 'primary_position_id', 'entry_date'\]/);
  assert.match(service, /'current_value' => \$current/);
  assert.match(service, /'requested_value' => \$normalized/);
  assert.match(employeeCorrections, /\(int\)\$staff\['id'\]/);
  assert.doesNotMatch(employeeCorrections, /staff_id.*\$input/);
});

test('matching pending corrections are idempotent and writes are audited transactionally', () => {
  assert.match(service, /status = 'pending'.*change_summary_json = \?/s);
  assert.match(service, /'idempotent' => true/);
  assert.match(service, /beginTransaction\(\)/);
  assert.match(service, /adminRecordOperation\(/);
  assert.match(service, /rollBack\(\)/);
});

test('employees can track visible correction status and management comments', () => {
  assert.match(employeeCorrections, /correctionsForStaff\(\(int\)\$staff\['id'\]\)/);
  for (const field of ['status', 'handler_comment', 'handled_at', 'created_at', 'updated_at']) {
    assert.match(service, new RegExp(`'${field}'`));
  }
});

test('management lists and handles pending corrections through named permissions', () => {
  assert.match(adminCorrections, /adminRequirePermission\('staff\.edit'\)/);
  assert.match(adminCorrections, /listCorrections\(\$_GET\)/);
  assert.match(adminCorrections, /->handle\(/);
  assert.match(service, /\['approved', 'rejected'\]/);
  assert.match(service, /WHERE id = \? FOR UPDATE/);
  assert.match(service, /更正申请已处理/);
});

test('approval records a decision while profile edits remain in the lifecycle service', () => {
  assert.match(service, /UPDATE staff_profile_correction_requests SET status = \?/);
  assert.doesNotMatch(service, /UPDATE staffs SET/);
  assert.match(service, /'action' => 'handle_correction'/);
});
