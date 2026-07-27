import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const page = readFileSync(new URL('../admin/staffs.html', import.meta.url), 'utf8');

test('staff import and data health are operable isolated workspaces', () => {
  for (const workspace of ['import', 'health']) {
    assert.match(page, new RegExp(`data-workspace-tab="${workspace}"`));
    assert.match(page, new RegExp(`data-workspace-panel="${workspace}"`));
  }
  assert.match(page, /批量入职的预检查与逐行结果/);
  assert.match(page, /员工身份与组织数据实时体检/);
});

test('staff import accepts CSV and JSON through file selection or drag and drop', () => {
  assert.match(page, /id="importFile"[^>]*accept="\.csv,\.json,application\/json,text\/csv"/);
  assert.match(page, /id="importDropZone"/);
  assert.match(page, /function parseCsv\(text\)/);
  assert.match(page, /JSON\.parse\(text\)/);
  assert.match(page, /addEventListener\('drop'/);
});

test('staff import provides a UTF-8 CSV template and bilingual automatic field mapping', () => {
  assert.match(page, /id="downloadImportTemplateBtn"/);
  assert.match(page, /staff-import-template\.csv/);
  assert.match(page, /\\uFEFF工号,姓名,手机号,门店ID,岗位ID,角色,初始密码/);
  assert.match(page, /function autoImportMapping\(headers\)/);
  assert.match(page, /data-import-map=/);
  for (const field of ['employee_no', 'name', 'phone', 'store_id', 'position_id', 'role', 'initial_password', 'username', 'email', 'entry_date', 'stage']) {
    assert.match(page, new RegExp(`key: '${field}'`));
  }
});

test('staff import performs row-level prechecks before the server remains authoritative', () => {
  assert.match(page, /function precheckImportRecord\(record\)/);
  assert.match(page, /手机号格式无效/);
  assert.match(page, /门店 ID 需为整数/);
  assert.match(page, /岗位 ID 需为整数/);
  assert.match(page, /角色编码无效/);
  assert.match(page, /初始密码强度不足/);
  assert.match(page, /records\.length <= 1000/);
  assert.match(page, /服务端继续执行最终校验/);
  assert.match(page, /data-testid="import-precheck"/);
});

test('staff import guards submission and retries only failed rows in the original batch', () => {
  assert.match(page, /if \(importSubmitting\) return/);
  assert.match(page, /setImportSubmitting\(true\)/);
  assert.match(page, /authFetch\('\/api\/admin\/staff-import\.php'/);
  assert.match(page, /retry \? importResult\?\.retryable_batch_key/);
  assert.match(page, /batch_key: batchKey/);
  assert.match(page, /按原批次重试失败行/);
  assert.match(page, /data-testid="import-result-summary"/);
  assert.match(page, /data-testid="import-result-row"/);
});

test('data health renders all seven live issue categories', () => {
  assert.match(page, /authFetch\('\/api\/admin\/staff\/data-health\.php'\)/);
  for (const category of ['duplicate_employee_numbers', 'duplicate_phones', 'duplicate_accounts', 'invalid_stores', 'invalid_positions', 'role_mismatches', 'orphan_identities']) {
    assert.match(page, new RegExp(`${category}:`));
  }
  assert.match(page, /data-testid="health-summary"/);
  assert.match(page, /data-testid="health-issue"/);
  assert.match(page, /checked_at/);
});

test('data health exposes repair context and explicit post-repair recheck', () => {
  assert.match(page, /data-health-staff=/);
  assert.match(page, /data-health-context=/);
  assert.match(page, /setWorkspace\('staff'\)/);
  assert.match(page, /loadDetail\(button\.dataset\.healthStaff\)/);
  assert.match(page, /id="reloadHealthBtn"/);
  assert.match(page, /loadDataHealth\(true\)/);
  assert.match(page, /完成后点击重新检查确认关闭/);
});

test('import results and health categories adapt to narrow screens', () => {
  assert.match(page, /\.import-result-cards \{ display: none;[^}]*\}/);
  assert.match(page, /@media \(max-width: 760px\)[\s\S]*?\.import-result-cards \{ display: grid; \}/);
  assert.match(page, /@media \(max-width: 760px\)[\s\S]*?\.health-counts \{ grid-template-columns: repeat\(2, minmax\(0, 1fr\)\); \}/);
  assert.match(page, /\.import-layout \{ display: grid; grid-template-columns: minmax\(280px, 0\.75fr\) minmax\(0, 1\.25fr\);/);
});
