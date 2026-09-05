import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const page = readFileSync(new URL('../internal.html', import.meta.url), 'utf8');

test('企业运营首页包含命令栏、问候、摘要和工作区', () => {
  for (const marker of [
    'data-ops-dashboard',
    'class="ops-commandbar"',
    'id="dashboardDate"',
    'id="dashboardGreetingLabel"',
    'class="stats-bar"',
    'class="ops-workspace"',
    '>今日工作<',
    '>六大中心<',
  ]) {
    assert.match(page, new RegExp(marker));
  }
  assert.equal((page.match(/class="stat-card"/g) || []).length, 4);
});

test('六大中心使用规范入口且业务工具保留锚点', () => {
  const centers = new Map([
    ['制度中心', '/制度标准/'],
    ['知识中心', '/knowledge/'],
    ['演练中心', '/mobile/drill.html'],
    ['学习中心', '/learning/'],
    ['业务工具', '/internal.html#tools'],
    ['我的', '/mobile/mine.html'],
  ]);
  for (const [label, href] of centers) {
    assert.match(page, new RegExp(`href="${href.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}"[\\s\\S]{0,180}<h3>${label}</h3>`));
  }
  assert.match(page, /id="tools"/);
});

test('登录、权限与搜索合同保持稳定', () => {
  for (const id of [
    'staffPhone',
    'staffPassword',
    'loginPanelTitle',
    'loginForm',
    'accountCard',
    'accountName',
    'accountMeta',
    'adminLink',
    'globalSearchInput',
  ]) {
    assert.match(page, new RegExp(`id="${id}"`));
  }
  assert.match(page, /function goStaffLogin\(\)/);
  assert.match(page, /function logoutInternal\(\)/);
  assert.match(page, /function doGlobalSearch\(event\)/);
  assert.match(page, /window\.location\.href = '\/search\.html\?q=' \+ encodeURIComponent\(q\)/);
  assert.match(page, /window\.InternalAuth\.canShowAdminDashboardEntry\(user\)/);
  assert.match(page, /adminLink\.style\.display=canShowAdmin\?'flex':'none'/);
});

test('首页布局覆盖桌面、平板和手机', () => {
  assert.match(page, /grid-template-columns:repeat\(4,minmax\(0,1fr\)\)/);
  assert.match(page, /@media\(max-width:1100px\)/);
  assert.match(page, /@media\(max-width:760px\)/);
  assert.match(page, /@media\(max-width:420px\)/);
});
