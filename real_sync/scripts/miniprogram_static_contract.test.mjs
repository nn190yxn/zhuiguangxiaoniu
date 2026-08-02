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
