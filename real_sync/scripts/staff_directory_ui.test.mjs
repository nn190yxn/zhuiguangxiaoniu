import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const page = readFileSync(new URL('../admin/staffs.html', import.meta.url), 'utf8');

test('staff directory page preserves the established visual language and workspace tabs', () => {
  for (const token of ['#f5f1ea', '#fff', '#ff6b35', '#1f1a17']) {
    assert.match(page, new RegExp(token.replace('#', '\\#')));
  }
  assert.match(page, /linear-gradient\(135deg, #1f1a17, #4a3124/);
  for (const label of ['员工一览', '组织架构', '门店设置', '岗位设置', '批量导入', '数据健康']) {
    assert.match(page, new RegExp(label));
  }
  assert.match(page, /workspace-tab current/);
});

test('staff directory page uses the unified directory and organization APIs', () => {
  assert.match(page, /\/api\/admin\/staff\/list\.php\?/);
  assert.match(page, /\/api\/admin\/staff\/detail\.php\?staff_id=/);
  assert.match(page, /\/api\/admin\/organization\/stores\.php\?status=all/);
  assert.match(page, /\/api\/admin\/organization\/positions\.php\?status=all/);
  assert.match(page, /\/api\/admin\/staff\/data-health\.php/);
  assert.doesNotMatch(page, /\/api\/statistics\/staff\.php/);
  assert.doesNotMatch(page, /\/api\/statistics\/store\.php/);
});

test('staff directory combines every required filter and includes offboarded staff by default', () => {
  for (const field of ['filterKeyword', 'filterStore', 'filterPosition', 'filterRole', 'filterLifecycle', 'filterSize']) {
    assert.match(page, new RegExp(`id="${field}"`));
  }
  for (const parameter of ['keyword', 'store_id', 'position_id', 'role', 'lifecycle_status', 'page_size']) {
    assert.match(page, new RegExp(`${parameter}:`));
  }
  assert.match(page, /query\.set\('include_offboarded', '1'\)/);
  assert.match(page, /已生效条件/);
  assert.match(page, /clearFiltersBtn/);
});

test('desktop table and narrow-screen cards use the same employee directory records', () => {
  for (const heading of ['姓名与工号', '门店', '主岗位', '系统角色', '手机号', '账号状态', '操作']) {
    assert.match(page, new RegExp(heading));
  }
  assert.match(page, /class="employee-cards"/);
  assert.match(page, /data-testid="staff-card"/);
  assert.match(page, /@media \(max-width: 760px\)[\s\S]*?\.table-wrap \{ display: none; \}[\s\S]*?\.employee-cards \{ display: grid; \}/);
  assert.match(page, /tableBody\.innerHTML = list\.map/);
  assert.match(page, /cards\.innerHTML = list\.map/);
});

test('staff directory exposes pagination, summaries, and testable request states', () => {
  for (const id of ['summaryResult', 'summaryActive', 'summaryInactive', 'summaryOffboarded', 'summaryIssues']) {
    assert.match(page, new RegExp(`id="${id}"`));
  }
  for (const id of ['prevPageBtn', 'nextPageBtn', 'paginationInfo']) {
    assert.match(page, new RegExp(`id="${id}"`));
  }
  assert.match(page, /currentPage < currentTotalPages/);
  assert.match(page, /currentPage > 1/);
  for (const state of ['staff-loading', 'staff-empty', 'staff-error', 'detail-loading', 'detail-error']) {
    assert.match(page, new RegExp(`data-testid="${state}`));
  }
  assert.match(page, /role="status" aria-live="polite"/);
});

test('staff directory admits headquarters operation and administrator roles', () => {
  assert.match(page, /user\?\.is_hq \|\| user\?\.is_admin/);
  assert.match(page, /\['admin', 'ceo', 'operation', 'ops'\]\.includes\(role\)/);
});
