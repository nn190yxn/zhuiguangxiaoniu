import assert from 'node:assert/strict';
import { cpSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import test from 'node:test';

import { checkMiniProgramContracts } from './check_miniprogram_contracts.mjs';

const projectRoot = new URL('../', import.meta.url).pathname;

test('小程序七类静态契约在当前代码基线上通过', () => {
  const report = checkMiniProgramContracts(projectRoot);

  assert.equal(report.status, 'passed');
  assert.deepEqual(report.issues, []);
  assert.deepEqual(report.categories.map(({ category }) => category), [
    'page_registration',
    'navigation',
    'request_layer',
    'device_session',
    'state_sync',
    'upload',
    'capability_version',
  ]);
  assert.equal(report.registeredRoutes.length, 32);
  assert.equal(report.checkedReferences > 0, true);
});

test('小程序静态契约检查器阻断 Tab 清单漂移', () => {
  const fixtureRoot = mkdtempSync(join(tmpdir(), 'mini-contract-check-'));
  cpSync(join(projectRoot, 'mini-program'), join(fixtureRoot, 'mini-program'), { recursive: true });
  cpSync(join(projectRoot, 'api/platform'), join(fixtureRoot, 'api/platform'), { recursive: true });
  const navigationPath = join(fixtureRoot, 'mini-program/utils/navigation.js');
  const navigation = readFileSync(navigationPath, 'utf8').replace("  '/pages/mine/mine',\n", '');
  writeFileSync(navigationPath, navigation);

  const report = checkMiniProgramContracts(fixtureRoot);

  assert.equal(report.status, 'failed');
  assert.equal(report.issues.some(({ category, code }) => category === 'navigation' && code === 'TAB_ROUTE_DRIFT'), true);
});

test('小程序静态契约检查器阻断页面绝对业务 URL、媒体入口和云配置漂移', () => {
  const fixtureRoot = mkdtempSync(join(tmpdir(), 'mini-contract-check-'));
  cpSync(join(projectRoot, 'mini-program'), join(fixtureRoot, 'mini-program'), { recursive: true });
  cpSync(join(projectRoot, 'api/platform'), join(fixtureRoot, 'api/platform'), { recursive: true });
  const pagePath = join(fixtureRoot, 'mini-program/pages/index/index.js');
  const mediaPath = join(fixtureRoot, 'mini-program/utils/media.js');
  const cloudConfigPath = join(fixtureRoot, 'mini-program/config/cloud.js');

  writeFileSync(pagePath, `${readFileSync(pagePath, 'utf8')}\nconst leakedApi = 'https://supercalf.com/api/todos/my.php';\n`);
  writeFileSync(mediaPath, readFileSync(mediaPath, 'utf8').replace('function uploadAndRegister(', 'function uploadAndRegisterMissing('));
  writeFileSync(cloudConfigPath, readFileSync(cloudConfigPath, 'utf8').replace('TRANSPORT_POLICY_VERSION: 1', 'TRANSPORT_POLICY_VERSION: 2'));

  const report = checkMiniProgramContracts(fixtureRoot);
  const codes = new Set(report.issues.map(({ code }) => code));

  assert.equal(report.status, 'failed');
  assert.equal(codes.has('ABSOLUTE_BUSINESS_URL_OUTSIDE_API_CLIENT'), true);
  assert.equal(codes.has('MEDIA_UPLOAD_REGISTER_MISSING'), true);
  assert.equal(codes.has('CLOUD_TRANSPORT_POLICY_VERSION_MISSING'), true);
});
