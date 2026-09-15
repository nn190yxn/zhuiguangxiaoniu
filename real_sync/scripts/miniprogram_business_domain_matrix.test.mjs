import assert from 'node:assert/strict';
import { cpSync, existsSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';

import { checkMiniProgramCloudbaseConfig } from './check_miniprogram_cloudbase_config.mjs';

const projectRoot = new URL('../', import.meta.url).pathname;
const miniProgramRoot = join(projectRoot, 'mini-program');
const matrix = JSON.parse(readFileSync(join(miniProgramRoot, 'business-domain-matrix.json'), 'utf8'));
const appConfig = JSON.parse(readFileSync(join(miniProgramRoot, 'app.json'), 'utf8'));

const expectedDomains = [
  'home', 'auth', 'profile', 'points', 'ranking',
  'mall', 'checkin', 'knowledge', 'certificate', 'feedback',
];

const expectedMigrationDomains = [
  'auth_session',
  'runtime_capability_device',
  'reminder_subscription',
  'home_todo',
  'policy_notification',
  'learning',
  'knowledge',
  'exam',
  'drill_core',
  'drill_audio',
  'workload_report',
  'workload_evidence',
  'workload_management',
  'points_profile',
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

test('云开发迁移清单覆盖 32 个页面与 14 个迁移域', () => {
  assert.equal(matrix.version, 2);
  assert.equal(matrix.migration.page_count, 32);
  assert.equal(matrix.migration.domain_count, 14);
  assert.deepEqual(matrix.migration_domains.map(({ id }) => id), expectedMigrationDomains);

  const contractRoutes = matrix.route_contracts.map(({ route }) => route);
  assert.equal(contractRoutes.length, 32);
  assert.deepEqual(new Set(contractRoutes), new Set(appConfig.pages));
  assert.equal(new Set(contractRoutes).size, contractRoutes.length, '路由契约不能重复登记同一页面');

  const domainIds = new Set(matrix.migration_domains.map(({ id }) => id));
  for (const contract of matrix.route_contracts) {
    assert.ok(domainIds.has(contract.domain), `${contract.route} 迁移域未登记: ${contract.domain}`);
    assert.ok(Array.isArray(contract.methods), `${contract.route} 缺少 HTTP 方法登记`);
    assert.ok(Array.isArray(contract.endpoints), `${contract.route} 缺少 endpoint 登记`);
    assert.equal(typeof contract.auth, 'boolean', `${contract.route} 缺少认证声明`);
  }
});

test('云开发迁移清单登记方法、副作用、媒体字段和微信能力', () => {
  const contracts = new Map(matrix.route_contracts.map((contract) => [contract.route, contract]));

  const exam = contracts.get('pages/exam/exam');
  assert.ok(exam.endpoints.includes('/exam/index.php?action=assign'), '考试分配 endpoint 必须登记');
  assert.ok(exam.side_effects.includes('exam_assign'), '考试分配必须标记为副作用');
  assert.equal(exam.idempotency, true, '考试写入链路必须声明幂等');

  const drillFeedback = contracts.get('pages/drill/feedback/feedback');
  assert.ok(drillFeedback.endpoints.includes('/drill/v2/results.php'), 'Drill v2 反馈必须使用 v2 结果 endpoint');

  const mediaRoutes = matrix.route_contracts.filter(({ media_fields }) => Array.isArray(media_fields) && media_fields.length > 0);
  assert.ok(mediaRoutes.length >= 7, '媒体字段覆盖学习、知识、演练和工作量页面');
  assert.ok(contracts.get('pages/workload/index').media_fields.includes('image_file'));
  assert.ok(contracts.get('pages/drill/doing/doing').media_fields.includes('checksum'));

  const capabilities = new Set(matrix.route_contracts.flatMap(({ wechat_capabilities }) => wechat_capabilities || []));
  for (const capability of ['wx.login', 'wx.requestSubscribeMessage', 'wx.chooseMedia', 'wx.uploadFile', 'wx.getRecorderManager', 'WechatSI']) {
    assert.ok(capabilities.has(capability), `缺少微信能力登记: ${capability}`);
  }
});

test('云开发迁移配置保持占位和固定上游边界', () => {
  assert.deepEqual(matrix.migration.cloud_functions, ['api-proxy', 'auth-proxy', 'media-ticket']);
  assert.equal(matrix.migration.environment.cloud_env_id, '__CLOUD_ENV_ID__');
  assert.equal(matrix.migration.environment.upstream_origin, 'https://supercalf.com/api');
  assert.equal(matrix.migration.environment.gateway_signature_version, 'v1');
  assert.equal(matrix.migration.environment.transport, 'cloud');
  assert.equal(matrix.migration.environment.shadow_sample_rate, 0);
});

test('云开发配置校验器通过当前工程配置', () => {
  const report = checkMiniProgramCloudbaseConfig(projectRoot);

  assert.equal(report.status, 'passed');
  assert.deepEqual(report.issues, []);
});

test('云开发配置校验器阻断缺失环境占位和真实密钥', () => {
  const fixtureRoot = mkdtempSync(join(tmpdir(), 'mini-cloudbase-check-'));
  cpSync(join(projectRoot, 'mini-program'), join(fixtureRoot, 'mini-program'), { recursive: true });
  const cloudConfigPath = join(fixtureRoot, 'mini-program/config/cloud.js');
  const drifted = readFileSync(cloudConfigPath, 'utf8')
    .replace('__CLOUD_ENV_ID__', 'prod-cloud-env')
    .replace("GATEWAY_SIGNATURE_VERSION: 'v1'", "GATEWAY_SIGNATURE_VERSION: 'v1',\n  gatewaySecret: 'abcdefghijklmnopqrstuvwxyz123456'");
  writeFileSync(cloudConfigPath, drifted);

  const report = checkMiniProgramCloudbaseConfig(fixtureRoot);

  assert.equal(report.status, 'failed');
  assert.equal(report.issues.some(({ code }) => code === 'ENV_PLACEHOLDER_MISSING'), true);
  assert.equal(report.issues.some(({ code }) => code === 'CONCRETE_SECRET'), true);
});
