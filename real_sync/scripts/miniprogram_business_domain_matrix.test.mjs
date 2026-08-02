import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import test from 'node:test';

const projectRoot = new URL('../', import.meta.url).pathname;
const miniProgramRoot = join(projectRoot, 'mini-program');
const matrix = JSON.parse(readFileSync(join(miniProgramRoot, 'business-domain-matrix.json'), 'utf8'));
const appConfig = JSON.parse(readFileSync(join(miniProgramRoot, 'app.json'), 'utf8'));

const expectedDomains = [
  'home', 'auth', 'profile', 'points', 'ranking',
  'mall', 'checkin', 'knowledge', 'certificate', 'feedback',
];

function pageSource(route) {
  return ['js', 'wxml'].map((extension) => {
    const path = join(miniProgramRoot, `${route}.${extension}`);
    return existsSync(path) ? readFileSync(path, 'utf8') : '';
  }).join('\n');
}

test('小程序十个业务域具有机器可读页面与状态契约', () => {
  assert.deepEqual(matrix.domains.map(({ id }) => id), expectedDomains);
  assert.deepEqual(matrix.required_read_states, ['loading', 'empty', 'error']);
  assert.deepEqual(matrix.required_write_states, ['submitting', 'success']);
  assert.deepEqual(matrix.required_offline_states, ['offline', 'conflict']);

  for (const domain of matrix.domains) {
    assert.ok(appConfig.pages.includes(domain.route), `${domain.label}页面未注册: ${domain.route}`);
    assert.ok(existsSync(join(miniProgramRoot, `${domain.route}.js`)), `${domain.label}缺少页面脚本`);
    assert.ok(existsSync(join(miniProgramRoot, `${domain.route}.wxml`)), `${domain.label}缺少页面模板`);
    const entrySource = readFileSync(join(miniProgramRoot, domain.entry_file), 'utf8');
    assert.match(entrySource, new RegExp(domain.entry_action.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')), `${domain.label}缺少明确入口`);
    const source = pageSource(domain.route);
    assert.ok(source.includes(domain.endpoint), `${domain.label}未接入稳定 API ${domain.endpoint}`);
    for (const state of matrix.required_read_states) {
      assert.ok(domain.states.includes(state), `${domain.label}缺少 ${state} 状态契约`);
      assert.ok(source.includes(state), `${domain.label}页面缺少 ${state} 状态证据`);
    }
  }
});

test('小程序写业务域声明提交、成功、离线、冲突及恢复动作', () => {
  const writeDomains = matrix.domains.filter(({ write_action }) => Boolean(write_action));
  assert.deepEqual(writeDomains.map(({ id }) => id), ['mall', 'checkin']);

  for (const domain of writeDomains) {
    const source = pageSource(domain.route);
    for (const state of [...matrix.required_write_states, ...matrix.required_offline_states]) {
      assert.ok(domain.states.includes(state), `${domain.label}缺少 ${state} 状态契约`);
      assert.ok(source.includes(state), `${domain.label}页面缺少 ${state} 状态证据`);
    }
    for (const action of [domain.write_action, domain.retry_action, domain.conflict_action]) {
      assert.ok(source.includes(action), `${domain.label}缺少恢复动作 ${action}`);
    }
    assert.equal(domain.idempotency, true, `${domain.label}写操作必须声明幂等`);
    assert.match(source, /idempotencyKey/, `${domain.label}写操作未向统一请求层传递幂等键`);
  }
});
