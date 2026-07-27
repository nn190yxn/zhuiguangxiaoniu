import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const page = readFileSync(new URL('../admin/staffs.html', import.meta.url), 'utf8');

test('directory filters submit together, reset predictably, and preserve full lifecycle visibility', () => {
  assert.match(page, /filterForm'\)\.addEventListener\('submit',[\s\S]*loadList\(\{ resetPage: true \}\)/);
  assert.match(page, /function collectFilters\(\)[\s\S]*keyword:[\s\S]*store_id:[\s\S]*position_id:[\s\S]*role:[\s\S]*lifecycle_status:[\s\S]*page_size:/);
  assert.match(page, /if \(!filters\.lifecycle_status\) query\.set\('include_offboarded', '1'\)/);
  assert.match(page, /function clearFilters\(\)[\s\S]*filterForm'\)\.reset\(\)[\s\S]*filterSize'\)\.value = '20'[\s\S]*loadList\(\{ resetPage: true \}\)/);
});

test('pagination changes one page at a time and reloads the shared directory', () => {
  assert.match(page, /prevPageBtn'\)\.onclick = \(\) => \{ if \(currentPage > 1\) \{ currentPage -= 1; loadList\(\)/);
  assert.match(page, /nextPageBtn'\)\.onclick = \(\) => \{ if \(currentPage < currentTotalPages\) \{ currentPage \+= 1; loadList\(\)/);
  assert.match(page, /prevPageBtn'\)\.disabled = currentPage <= 1/);
  assert.match(page, /nextPageBtn'\)\.disabled = currentTotalPages === 0 \|\| currentPage >= currentTotalPages/);
});

test('staff creation validates every step before a guarded transactional submission', () => {
  assert.match(page, /createStaffForm'\)\.addEventListener\('submit', submitCreateStaff\)/);
  assert.match(page, /if \(createSubmitting\) return/);
  assert.match(page, /for \(const step of \[1, 2, 3\]\)[\s\S]*validateCreateStep\(step\)/);
  assert.match(page, /postAction\('\/api\/admin\/staff\/create\.php', payload\)/);
  assert.match(page, /setCreateSubmitting\(true\)[\s\S]*setCreateSubmitting\(false\)/);
});

test('staff creation exposes conflicts and opens the created employee after refreshing summaries', () => {
  assert.match(page, /function createConflictMessage\(error\)[\s\S]*conflict_fields[\s\S]*existing_profiles/);
  assert.match(page, /createStatus'\)\.textContent = createConflictMessage\(error\)/);
  assert.match(page, /Promise\.allSettled\(\[loadList\(\{ resetPage: true \}\), loadSummary\(\)\]\)/);
  assert.match(page, /if \(created\.id\) await loadDetail\(created\.id\)/);
});

test('directory records open a loading-aware detail drawer with a visible failure state', () => {
  assert.match(page, /querySelectorAll\('\[data-staff\]'\)[\s\S]*loadDetail\(button\.dataset\.staff\)/);
  assert.match(page, /async function loadDetail\(staffId\)[\s\S]*data-testid="detail-loading"/);
  assert.match(page, /\/api\/admin\/staff\/detail\.php\?staff_id=/);
  assert.match(page, /data-testid="detail-error"/);
});

test('offboarding and restoration require confirmations and refresh authoritative detail state', () => {
  assert.match(page, /offboardForm'\)\?\.addEventListener\('submit',[\s\S]*offboardStaff/);
  assert.match(page, /restoreForm'\)\?\.addEventListener\('submit',[\s\S]*restoreStaff/);
  assert.match(page, /postAction\('\/api\/admin\/staff\/offboard\.php',[\s\S]*offboard_date:[\s\S]*offboard_reason:[\s\S]*confirmed/);
  assert.match(page, /postAction\('\/api\/admin\/staff\/restore\.php',[\s\S]*account_status: 'active'[\s\S]*secondary_assignments: \[\]/);
  assert.match(page, /function refreshAfterDetailAction\(message\)[\s\S]*loadList\(\), loadSummary\(\), loadDetail\(currentStaffId\)/);
});

test('controlled purge follows check, token confirmation, cleanup, and directory refresh', () => {
  assert.match(page, /purgeCheckBtn'\)\?\.addEventListener\('click', \(\) => inspectPurge\(\)/);
  assert.match(page, /postAction\('\/api\/admin\/staff\/purge-check\.php', \{ staff_id: currentStaffId \}\)/);
  assert.match(page, /purgeCheckData\?\.confirmation_token/);
  assert.match(page, /postAction\('\/api\/admin\/staff\/purge\.php',[\s\S]*confirmation_token: token/);
  assert.match(page, /currentStaffId = 0;[\s\S]*Promise\.allSettled\(\[loadList\(\), loadSummary\(\)\]\)/);
});

test('organization workspace loads once, switches view accessibly, and exposes request errors', () => {
  assert.match(page, /if \(name === 'organization'\) loadOrganizationTree\(\)/);
  assert.match(page, /if \(organizationLoading \|\| \(organizationData && !force\)\)/);
  assert.match(page, /authFetch\('\/api\/admin\/organization\/tree\.php'\)/);
  assert.match(page, /data-organization-mode[\s\S]*aria-pressed[\s\S]*renderOrganization\(\)/);
  assert.match(page, /data-testid="organization-error"/);
});

test('import supports file and drop interactions, guarded submission, and original-batch retry', () => {
  assert.match(page, /importFile'\)\.onchange = event => selectImportFile\(event\.target\.files\?\.\[0\]\)/);
  assert.match(page, /addEventListener\('drop', event => selectImportFile\(event\.dataTransfer\?\.files\?\.\[0\]\)/);
  assert.match(page, /submitImportBtn'\)\.onclick = \(\) => submitImport\(false\)/);
  assert.match(page, /retryImportBtn'\)\.onclick = \(\) => submitImport\(true\)/);
  assert.match(page, /if \(importSubmitting\) return/);
  assert.match(page, /retry \? importResult\?\.retryable_batch_key/);
  assert.match(page, /JSON\.stringify\(\{ records, \.\.\.\(batchKey \? \{ batch_key: batchKey \} : \{\}\) \}\)/);
});

test('request failures remain visible and retries stay attached to each workspace', () => {
  for (const testId of ['staff-error', 'staff-card-error', 'detail-error', 'organization-error', 'health-error']) {
    assert.match(page, new RegExp(`data-testid="${testId}"`));
  }
  assert.match(page, /reloadBtn'\)\.onclick = \(\) => Promise\.all\(\[loadList\(\), loadSummary\(\)\]\)/);
  assert.match(page, /reloadHealthBtn'\)\.onclick = \(\) => loadDataHealth\(true\)/);
  assert.match(page, /importStatus'\)\.textContent = error\.message \|\| '员工批量导入失败'/);
});
