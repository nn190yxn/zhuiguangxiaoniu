import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const page = readFileSync(new URL('../admin/drill.html', import.meta.url), 'utf8');
const script = readFileSync(new URL('../js/drill-admin.js', import.meta.url), 'utf8');

test('演练管理脚本通过 Node 语法检查', () => {
  const result = spawnSync(process.execPath, ['--check', new URL('../js/drill-admin.js', import.meta.url).pathname], { encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
});

test('演练管理页提供内容、评分、计划、复核、统计和迁移工作区', () => {
  for (const label of ['内容与版本', '评分与学习', '计划与任务', '复核与辅导', '训练分析', '迁移预检']) {
    assert.match(page, new RegExp(label));
  }
  assert.match(script, /workspaceResources = \{ content:/);
  assert.match(script, /speaker_confirmation_required|说话人与评分对象确认/);
});

test('页面统一使用 ApiClient 和幂等键访问管理端资源', () => {
  assert.match(page, /js\/api-client\.js/);
  assert.match(page, /js\/app-auth\.js/);
  assert.match(script, /ApiClient\.get\(/);
  assert.match(script, /ApiClient\.post\(/);
  assert.match(script, /'Idempotency-Key': idempotencyKey/);
  assert.doesNotMatch(script, /\bfetch\s*\(/);
});

test('表单校验覆盖 JSON、发布窗口、复核改分和辅导记录', () => {
  assert.match(script, /function parseJson\(/);
  assert.match(script, /截止时间需要晚于开始时间/);
  assert.match(script, /人工改分需要填写调整原因/);
  assert.match(script, /name="notes" required minlength="5"/);
  assert.match(script, /form\.reportValidity\(\)/);
});

test('权限拒绝隐藏工作区并保留可见的运营提示', () => {
  assert.match(script, /Number\(result\.reason\.status\) === 403/);
  assert.match(script, /renderAccessDenied\(\)/);
  assert.match(script, /当前账号仅展示已授权的演练数据/);
});

test('统计界面覆盖完整运营筛选与低样本提示', () => {
  for (const label of ['开始日期', '结束日期', '门店', '岗位', '员工', '板块', '计划或状态']) {
    assert.match(script, new RegExp(label));
  }
  assert.match(script, /样本量较低/);
  assert.match(script, /少于 3 名员工或 10 次有效演练/);
});
