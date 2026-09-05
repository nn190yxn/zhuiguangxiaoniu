import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import test from 'node:test';

const projectRoot = new URL('../', import.meta.url).pathname;
const flowSpec = JSON.parse(readFileSync(join(projectRoot, 'scripts/browser-role-flows.json'), 'utf8'));

test('浏览器角色场景覆盖四类角色和核心放行流程', () => {
  assert.deepEqual(flowSpec.required_roles, ['普通员工', '店长', '教学主管', '总部管理员']);
  assert.deepEqual(flowSpec.flows.map(flow => flow.id), [
    'login-recovery',
    'identity-prefill',
    'permission-entry',
    'knowledge-search-detail',
    'lesson-submit-review',
  ]);
  for (const flow of flowSpec.flows) {
    assert.match(flow.entry, /^\//);
    assert.ok(flow.steps.length >= 2);
  }
});

test('浏览器场景声明真实运行时要求和待接入状态', () => {
  assert.equal(flowSpec.browser_runtime.required, true);
  assert.equal(flowSpec.browser_runtime.driver, 'playwright');
  assert.equal(flowSpec.browser_runtime.status, 'pending-runtime');
});
