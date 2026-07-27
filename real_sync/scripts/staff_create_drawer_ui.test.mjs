import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const page = readFileSync(new URL('../admin/staffs.html', import.meta.url), 'utf8');

test('staff creation uses an accessible three-step drawer', () => {
  assert.match(page, /id="openCreateDrawerBtn"[^>]*>新增员工</);
  assert.match(page, /class="create-drawer" role="dialog" aria-modal="true"/);
  for (const step of ['1 基础资料', '2 组织与权限', '3 账号确认']) {
    assert.match(page, new RegExp(step));
  }
  for (const field of ['createName', 'createPhone', 'createEmployeeNo', 'createEntryDate', 'createStoreId', 'createPositionId', 'createRole', 'createUsername', 'createInitialPassword']) {
    assert.match(page, new RegExp(`id="${field}"`));
  }
});

test('staff creation presents enabled organization dictionaries and explicit secondary assignment guidance', () => {
  assert.match(page, /storeOptions\.filter\(item => Number\(item\.status\) !== 0\)/);
  assert.match(page, /positionOptions\.filter\(item => Number\(item\.status\) !== 0\)/);
  assert.match(page, /员工创建后从详情中的任职管理补充兼岗/);
});

test('staff creation validates identity, organization, account, and password inputs before submission', () => {
  assert.match(page, /\^1\[3-9\]\\d\{9\}\$/);
  assert.match(page, /\^\[A-Za-z0-9_-\]\{2,64\}\$/);
  assert.match(page, /请选择启用门店/);
  assert.match(page, /请选择启用岗位/);
  assert.match(page, /values\.initial_password\.length < 10/);
  assert.match(page, /\!\/\[A-Z\]\//);
  assert.match(page, /\!\/\[a-z\]\//);
  assert.match(page, /\!\/\\d\//);
});

test('staff creation renders a masked confirmation summary without exposing the password', () => {
  assert.match(page, /data-testid="create-confirm-summary"/);
  assert.match(page, /phone\.slice\(0, 3\).*\*\*\*\*.*phone\.slice\(-4\)/);
  assert.match(page, /\['初始密码', '已按安全规则设置'\]/);
  assert.doesNotMatch(page, /createConfirmSummary[^;]*initial_password/);
});

test('staff creation posts once to the transactional endpoint and surfaces conflict details', () => {
  assert.match(page, /if \(createSubmitting\) return/);
  assert.match(page, /setCreateSubmitting\(true\)/);
  assert.match(page, /postAction\('\/api\/admin\/staff\/create\.php', payload\)/);
  assert.match(page, /error\.data = result\.data \|\| \{\}/);
  assert.match(page, /conflict_fields/);
  assert.match(page, /existing_profiles/);
});

test('successful staff creation refreshes the directory and opens the new employee detail', () => {
  assert.match(page, /Promise\.allSettled\(\[loadList\(\{ resetPage: true \}\), loadSummary\(\)\]\)/);
  assert.match(page, /if \(created\.id\) await loadDetail\(created\.id\)\.catch\(showActionError\)/);
  assert.match(page, /员工 \$\{created\.name \|\| values\.name\} 创建成功/);
});
