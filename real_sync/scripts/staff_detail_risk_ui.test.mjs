import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const page = readFileSync(new URL('../admin/staffs.html', import.meta.url), 'utf8');
const directory = readFileSync(new URL('../api/admin/services/StaffDirectoryService.php', import.meta.url), 'utf8');

test('staff detail renders identity, assignment, business, device, binding, and audit sections', () => {
  assert.match(page, /id="detailDrawer" hidden/);
  assert.match(page, /class="create-drawer detail-drawer" role="dialog" aria-modal="true"/);
  assert.match(page, /detailDrawerReturnFocus/);
  assert.match(page, /event\.key !== 'Escape'/);
  for (const testId of ['account-binding-summary', 'assignment-history', 'business-summary', 'audit-timeline', 'staff-risk-zone']) {
    assert.match(page, new RegExp(testId));
  }
  for (const field of ['assignment_history', 'business_summary', 'recent_login_audits', 'operation_audits']) {
    assert.match(page, new RegExp(field));
  }
});

test('staff detail returns target-scoped operation audits without exposing snapshots', () => {
  assert.match(directory, /FROM admin_operation_logs l/);
  assert.match(directory, /l\.target_type = \? AND l\.target_id = \?/);
  assert.match(directory, /'operation_audits' => \$this->operationAudits\(\$staffId\)/);
  assert.doesNotMatch(directory, /function operationAudits[\s\S]*before_json[\s\S]*private function availableActions/);
});

test('offboarded detail is read-only while active profile edits require dated reasons', () => {
  assert.match(page, /item\.lifecycle_status === 'offboarded'/);
  assert.match(page, /saveProfileBtn'\)\.disabled = true/);
  assert.match(page, /editEffectiveDate/);
  assert.match(page, /editReason/);
  assert.match(page, /postAction\('\/api\/admin\/staff\/update\.php', payload\)/);
});

test('password reset validates complexity and matching confirmation before submitting once', () => {
  assert.match(page, /resetPasswordForm/);
  assert.match(page, /newPassword\.length < 10/);
  assert.match(page, /newPassword !== confirmation/);
  assert.match(page, /postAction\('\/api\/admin\/staff\/reset-password\.php'/);
  assert.match(page, /detailActionSubmitting/);
  assert.match(page, /Promise\.allSettled\(\[loadList\(\), loadSummary\(\), loadDetail\(currentStaffId\)\]\)/);
});

test('offboarding requires a date, reason, and explicit revocation confirmation', () => {
  for (const id of ['offboardDate', 'offboardReason', 'offboardConfirmed']) assert.match(page, new RegExp(id));
  assert.match(page, /offboard_date: offboardDate/);
  assert.match(page, /offboard_reason: reason/);
  assert.match(page, /postAction\('\/api\/admin\/staff\/offboard\.php'/);
});

test('restore reconfirms enabled organization, role, account status, and secondary duties', () => {
  assert.match(page, /storeOptions\.filter\(row => Number\(row\.status\) !== 0\)/);
  assert.match(page, /positionOptions\.filter\(row => Number\(row\.status\) !== 0\)/);
  assert.match(page, /account_status: 'active'/);
  assert.match(page, /secondary_assignments: \[\]/);
  assert.match(page, /postAction\('\/api\/admin\/staff\/restore\.php'/);
});

test('controlled purge checks associations and submits the short-lived confirmation token', () => {
  assert.match(page, /postAction\('\/api\/admin\/staff\/purge-check\.php'/);
  assert.match(page, /eligible_for_purge/);
  assert.match(page, /建议离职归档/);
  assert.match(page, /purgeCheckData\?\.confirmation_token/);
  assert.match(page, /postAction\('\/api\/admin\/staff\/purge\.php'/);
});
