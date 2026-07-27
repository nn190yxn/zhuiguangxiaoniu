import assert from 'node:assert/strict';
import { mkdirSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import test from 'node:test';

import { checkMiniProgramRelease } from './check_miniprogram_release.mjs';
import { checkMiniProgramRoutes } from './check_miniprogram_routes.mjs';

const root = new URL('../', import.meta.url);
const read = (path) => readFileSync(new URL(path, root), 'utf8');
const miniProgramRoot = new URL('../mini-program/', import.meta.url).pathname;

function writeJson(path, value) {
  writeFileSync(path, `${JSON.stringify(value, null, 2)}\n`);
}

function createPage(projectRoot, route) {
  const path = join(projectRoot, route);
  mkdirSync(path.substring(0, path.lastIndexOf('/')), { recursive: true });
  for (const extension of ['.js', '.json', '.wxml', '.wxss']) {
    writeFileSync(`${path}${extension}`, extension === '.json' ? '{}\n' : '\n');
  }
}

test('真实小程序的注册页面、固定路由和基础文件全部有效', () => {
  const report = checkMiniProgramRoutes(miniProgramRoot);
  assert.deepEqual(report.errors, []);
  for (const route of ['pages/wechat-bind/gate', 'pages/reminder/gate', 'pages/reminder/settings', 'pages/workload/index']) {
    assert.equal(report.registeredRoutes.includes(route), true, route);
  }
  assert.equal(report.checkedReferences > 0, true);
});

test('路由检查器返回未注册目标和缺失基础文件的文件行号', () => {
  const projectRoot = mkdtempSync(join(tmpdir(), 'mini-route-check-'));
  writeJson(join(projectRoot, 'app.json'), { pages: ['pages/home/index', 'pages/incomplete/index'] });
  createPage(projectRoot, 'pages/home/index');
  mkdirSync(join(projectRoot, 'pages/incomplete'), { recursive: true });
  writeFileSync(join(projectRoot, 'pages/incomplete/index.js'), 'Page({});\n');
  writeFileSync(join(projectRoot, 'pages/home/index.js'), "Page({ open() { wx.navigateTo({ url: '/pages/missing/detail?id=1' }); } });\n");

  const report = checkMiniProgramRoutes(projectRoot);
  assert.equal(report.errors.some((issue) => issue.file === 'pages/home/index.js' && issue.line === 1 && issue.route === 'pages/missing/detail'), true);
  assert.equal(report.errors.some((issue) => issue.message.includes('pages/incomplete/index.wxml')), true);
});

test('真实小程序通过域名、隐私声明和构建配置代码检查', () => {
  const report = checkMiniProgramRelease(miniProgramRoot);
  assert.deepEqual(report.errors, []);
  assert.match(report.appId, /^wx[0-9a-f]{16}$/i);
  assert.equal(report.domains.request.includes('https://supercalf.com'), true);
  assert.equal(report.manualChecks.length >= 6, true);
});

test('发布检查器阻断非 HTTPS、测试 AppID、关闭的质量设置和缺失隐私声明', () => {
  const projectRoot = mkdtempSync(join(tmpdir(), 'mini-release-check-'));
  mkdirSync(join(projectRoot, 'pages/agreement'), { recursive: true });
  writeJson(join(projectRoot, 'app.json'), { __usePrivacyCheck__: false });
  writeJson(join(projectRoot, 'project.config.json'), { appid: 'touristappid', compileType: 'plugin', setting: {} });
  writeJson(join(projectRoot, 'release-check.config.json'), {
    domains: { request: ['http://example.com'], uploadFile: [], downloadFile: [], webView: [] },
    privacyCapabilities: {},
    manualChecks: [],
  });
  writeFileSync(join(projectRoot, 'app.js'), "wx.request({ url: 'http://example.com/api' }); wx.chooseMedia({}); wx.getRecorderManager();\n");
  writeFileSync(join(projectRoot, 'pages/agreement/privacy.wxml'), '<view>隐私政策</view>\n');

  const report = checkMiniProgramRelease(projectRoot);
  const messages = report.errors.map((issue) => issue.message).join('\n');
  assert.match(messages, /非 HTTPS/);
  assert.match(messages, /正式微信小程序 AppID/);
  assert.match(messages, /setting\.urlCheck/);
  assert.match(messages, /隐私能力声明不完整/);
  assert.match(messages, /permission\.scope\.record\.desc/);
});

test('企业微信状态接口先校验系统设置权限再输出配置摘要', () => {
  const status = read('api/wecom/status.php');
  const permissionIndex = status.indexOf("adminRequirePermission('system.settings')");
  const configIndex = status.indexOf("'corp_id_configured'");
  assert.equal(permissionIndex >= 0, true);
  assert.equal(configIndex > permissionIndex, true);
  assert.match(status, /'login' => \$enabled/);
  assert.match(status, /'directory_sync' => \$enabled/);
  assert.match(status, /'message' => \$enabled/);
  assert.doesNotMatch(status, /'wecom_userid'\s*=>/);
});

test('企业微信默认关闭且登录、同步和消息运行分支保留恢复开关', () => {
  const config = read('api/config.php');
  const auth = read('api/auth-jwt.php');
  const common = read('api/wecom/_common.php');
  const app = read('mini-program/app.js');

  assert.match(config, /configValue\('WECOM_ENABLED', '0'\)/);
  assert.match(auth, /case 'wecomlogin':[\s\S]*?if \(!isWecomEnabled\(\)\)/);
  assert.match(auth, /case 'wecombind':[\s\S]*?if \(!isWecomEnabled\(\)\)/);
  assert.equal((common.match(/if \(!isWecomEnabled\(\)\)/g) || []).length >= 3, true);
  assert.match(app, /wecomEnabled: false/);
  assert.match(app, /this\.globalData\.wecomEnabled === true/);
});

test('企业微信后台仅系统管理员可进入并在停用时停止加载运行接口', () => {
  const admin = read('admin/wecom.html');
  assert.match(admin, /const canAccess=!!user\?\.is_admin\|\|role==='admin'/);
  assert.match(admin, /const status=await loadStatus\(\);if\(!status\.enabled\)\{renderDisabledStatus\(status\);return\}/);
  assert.match(admin, /企业微信能力已停用/);
  assert.match(admin, /data-wecom-operational/);
  assert.match(admin, /\[hidden\]\{display:none!important\}/);
});
