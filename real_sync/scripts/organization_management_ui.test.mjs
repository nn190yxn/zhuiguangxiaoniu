import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const page = readFileSync(new URL('../admin/staffs.html', import.meta.url), 'utf8');

test('organization workspaces are operable tabs with isolated panels', () => {
  for (const workspace of ['staff', 'organization', 'stores', 'positions']) {
    assert.match(page, new RegExp(`data-workspace-tab="${workspace}"`));
    assert.match(page, new RegExp(`data-workspace-panel="${workspace}"`));
  }
  assert.match(page, /function setWorkspace\(name\)/);
  assert.match(page, /panel\.hidden = panel\.dataset\.workspacePanel !== name/);
  assert.match(page, /tab\.setAttribute\('aria-current', 'page'\)/);
});

test('organization view loads the current tree and switches between tree and list records', () => {
  assert.match(page, /\/api\/admin\/organization\/tree\.php/);
  assert.match(page, /data-organization-mode="tree"/);
  assert.match(page, /data-organization-mode="list"/);
  assert.match(page, /renderOrganizationTree\(organizationData\.tree\)/);
  assert.match(page, /renderOrganizationList\(organizationData\.list\)/);
  assert.match(page, /业务日期.*仅展示当前有效组织关系/);
});

test('organization hierarchy and summary preserve stores, positions, employees, and assignments', () => {
  for (const field of ['store_count', 'position_count', 'staff_count', 'assignment_count', 'primary_assignment_count', 'secondary_assignment_count']) {
    assert.match(page, new RegExp(`summary\.${field}`));
  }
  assert.match(page, /class="tree-store"/);
  assert.match(page, /class="tree-position"/);
  assert.match(page, /class="tree-staff"/);
  assert.match(page, /staff\.assignment_type === 'primary' \? '主岗' : '兼岗'/);
  assert.match(page, /当前没有有效任职/);
});

test('store settings expose codes, managers, references, sorting, status, and actions', () => {
  for (const field of ['store_code', 'manager_staff_id', 'manager_name', 'sort_order', 'status', 'reference_summary']) {
    assert.match(page, new RegExp(field));
  }
  assert.match(page, /\/api\/admin\/organization\/stores\.php/);
  assert.match(page, /data-setting-edit="store"/);
  assert.match(page, /data-setting-toggle="store"/);
  assert.match(page, /data-testid="store-settings-card"/);
});

test('position settings expose role applicability, references, sorting, status, and actions', () => {
  for (const field of ['position_code', 'position_name', 'applicable_roles', 'sort_order', 'status', 'reference_summary']) {
    assert.match(page, new RegExp(field));
  }
  assert.match(page, /\/api\/admin\/organization\/positions\.php/);
  assert.match(page, /data-setting-edit="position"/);
  assert.match(page, /data-setting-toggle="position"/);
  assert.match(page, /岗位至少选择一个适用系统角色/);
});

test('deactivation shows references and directs managers to resolve active assignments', () => {
  assert.match(page, /current_staff_count/);
  assert.match(page, /current_assignment_count/);
  assert.match(page, /historical_assignment_count/);
  assert.match(page, /请先完成员工归属调整/);
  assert.match(page, /历史任职.*持续保留/);
  assert.match(page, /error\?\.data\?\.reference_summary/);
});

test('organization writes use guarded JSON posts and refresh dictionaries after success', () => {
  assert.match(page, /settingSubmitting/);
  assert.match(page, /action: id \? 'update' : 'create'/);
  assert.match(page, /action: 'set_status'/);
  assert.match(page, /postAction\(settingEndpoint\(settingType\), payload\)/);
  assert.match(page, /await loadDictionaries\(\)/);
  assert.match(page, /organizationData = null/);
});

test('organization settings provide testable request states and narrow-screen cards', () => {
  for (const state of ['organization-loading', 'organization-error', 'organization-empty', 'organization-list']) {
    assert.match(page, new RegExp(`data-testid="${state}`));
  }
  assert.match(page, /data-testid="organization-list-card"/);
  assert.match(page, /@media \(max-width: 760px\)[\s\S]*?\.settings-panel \.table-wrap \{ display: none; \}[\s\S]*?\.settings-cards \{ display: grid; \}/);
  assert.match(page, /\.workspace-tabs \{[^}]*overflow-x: auto/);
});
