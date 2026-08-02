import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const require = createRequire(import.meta.url);
const navigationPath = require.resolve('../mini-program/utils/navigation.js');
const capabilitiesPath = require.resolve('../mini-program/utils/capabilities.js');
const read = path => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

function loadNavigation() {
  const calls = [];
  global.wx = {
    switchTab: options => calls.push(['switchTab', options.url]),
    navigateTo: options => calls.push(['navigateTo', options.url]),
    redirectTo: options => calls.push(['redirectTo', options.url]),
    reLaunch: options => calls.push(['reLaunch', options.url]),
    showToast: options => calls.push(['showToast', options.title]),
    setStorageSync() {},
  };
  delete require.cache[navigationPath];
  return { navigation: require(navigationPath), calls };
}

test('统一导航按 Tab 清单和目标模式选择微信导航 API', () => {
  const runtime = loadNavigation();

  assert.equal(runtime.navigation.open('/pages/workload/index?date=2026-08-01'), true);
  assert.equal(runtime.navigation.open('/pages/knowledge/list'), true);
  assert.equal(runtime.navigation.replace('/pages/reminder/gate'), true);
  assert.equal(runtime.navigation.reLaunch('/pages/index/index'), true);

  assert.deepEqual(runtime.calls, [
    ['switchTab', '/pages/workload/index'],
    ['navigateTo', '/pages/knowledge/list'],
    ['redirectTo', '/pages/reminder/gate'],
    ['reLaunch', '/pages/index/index'],
  ]);
});

test('统一导航拒绝非页面目标并保留当前页面', () => {
  const runtime = loadNavigation();
  assert.equal(runtime.navigation.open('https://example.test/pages/workload/index'), false);
  assert.deepEqual(runtime.calls, [['showToast', '页面暂不可用']]);
});

test('能力版本只展示服务端明确启用且客户端版本满足的功能', () => {
  delete require.cache[capabilitiesPath];
  const capabilities = require(capabilitiesPath);
  const payload = {
    mini_program: {
      features: {
        workload: { enabled: true, minimum_client_version: '1.0.0' },
        drill: { enabled: true, minimum_client_version: '1.3.0' },
        knowledge: { enabled: false, minimum_client_version: '1.0.0' },
      },
    },
  };

  assert.deepEqual(capabilities.resolveFeatures(payload, '1.2.0'), {
    workload: true,
    drill: false,
    knowledge: false,
  });
  assert.deepEqual(capabilities.resolveFeatures(null, '1.2.0'), capabilities.CONSERVATIVE_FEATURES);
});

test('提醒门禁允许用户稍后设置并使用普通页重启导航', () => {
  const page = read('mini-program/pages/reminder/gate.js');
  const view = read('mini-program/pages/reminder/gate.wxml');
  const login = read('mini-program/pages/login/login.js');

  assert.match(page, /continueWithoutReminder\(\)/);
  assert.match(view, /bindtap="continueWithoutReminder"/);
  assert.match(view, /稍后设置/);
  assert.match(page, /navigation\.reLaunch\('\/pages\/index\/index'\)/);
  assert.match(login, /navigation\.reLaunch\('\/pages\/index\/index'\)/);
  assert.doesNotMatch(view, /需要先完成提醒授权/);
});

test('能力端点发布小程序功能版本与保守降级契约', () => {
  const endpoint = read('api/platform/capabilities.php');
  assert.match(endpoint, /'mini_program_feature_versions'/);
  assert.match(endpoint, /'fallback_mode'\s*=>\s*'explicit_allowlist'/);
  assert.match(endpoint, /'minimum_client_version'/);
  assert.match(endpoint, /'enabled'\s*=>\s*true/);
});
