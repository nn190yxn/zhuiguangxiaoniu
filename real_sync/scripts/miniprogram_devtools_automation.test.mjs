import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

import {
  AUTOMATION_EXECUTION_PROTOCOL,
  MAIN_JOURNEY_STEPS,
  RECOVERY_SCENARIOS,
  buildAutomationArtifactPaths,
  buildAutomationExecutionPlan,
  evaluateAutomationReadiness,
  verifyAutomationArtifacts,
} from './miniprogram_devtools_automation_check.mjs';

const projectRoot = new URL('../', import.meta.url);
const matrix = JSON.parse(readFileSync(new URL('mini-program/business-domain-matrix.json', projectRoot), 'utf8'));
const registeredRoutes = new Set(matrix.route_contracts.map(({ route }) => route));

test('小程序主流程自动化计划覆盖双登录和 12 个业务入口', () => {
  assert.equal(MAIN_JOURNEY_STEPS.length, 14);
  for (const id of [
    'password_login',
    'wechat_login_or_bind',
    'home',
    'policy',
    'notifications',
    'learning',
    'knowledge',
    'exam',
    'pass',
    'points',
    'workload',
    'reminder',
    'mine',
    'drill',
  ]) {
    assert.ok(MAIN_JOURNEY_STEPS.some((step) => step.id === id), `missing journey ${id}`);
  }
  for (const step of MAIN_JOURNEY_STEPS) {
    assert.equal(registeredRoutes.has(step.route), true, step.route);
    assert.ok(step.action);
    assert.ok(step.assertions.length >= 2);
  }
});

test('弱网、并发和恢复自动化计划覆盖 7 个阻断场景', () => {
  const scenarioIds = RECOVERY_SCENARIOS.map(({ id }) => id);
  assert.deepEqual(scenarioIds, [
    'cloud_function_timeout',
    'concurrent_401_refresh',
    'media_resume',
    'ai_processing',
    'page_restore',
    'state_conflict_409',
    'transport_rollback',
  ]);
  assert.ok(RECOVERY_SCENARIOS.every(({ trigger, expected }) => trigger && expected));
});

test('自动化执行计划声明真实 DevTools runner、产物和 fail-closed 规则', () => {
  const plan = buildAutomationExecutionPlan({ env: {}, root: new URL('../', import.meta.url).pathname });

  assert.equal(plan.runner, 'miniprogram-automator');
  assert.deepEqual(plan.launch_environment, [
    'WECHAT_DEVTOOLS_CLI',
    'MINIPROGRAM_AUTOMATION_PROJECT',
    'MINIPROGRAM_AUTOMATION_ARTIFACT_DIR',
    'MINIPROGRAM_TEST_ACCOUNT or MINIPROGRAM_LOGIN_STATE',
    'MINIPROGRAM_NETWORK_PROFILE',
  ]);
  assert.deepEqual(plan.required_artifacts, ['tap_report', 'journey_screenshots', 'network_trace', 'storage_snapshot']);
  assert.deepEqual(plan.fail_closed_assertions, ['missing_route', 'missing_assertion', 'unexpected_transport', 'raw_business_url']);
  assert.equal(plan.launch_options.projectPath.endsWith('/mini-program'), true);
  assert.equal(plan.launch_options.accountSource, 'MINIPROGRAM_TEST_ACCOUNT');
  assert.equal(plan.artifact_paths.tap_report.endsWith('/var/miniprogram-automation/tap-report.tap'), true);
  assert.equal(plan.main_journeys.length, MAIN_JOURNEY_STEPS.length);
  assert.equal(plan.recovery_scenarios.length, RECOVERY_SCENARIOS.length);
  assert.equal(AUTOMATION_EXECUTION_PROTOCOL.runner, plan.runner);
});

test('微信开发者工具自动化在缺少外部 CLI 时输出外部门禁状态', () => {
  const report = evaluateAutomationReadiness({ env: {}, root: new URL('../', import.meta.url).pathname });

  assert.equal(report.status, 'blocked_external');
  assert.equal(report.runner, 'miniprogram-automator');
  assert.equal(report.journey_count, 14);
  assert.equal(report.recovery_scenario_count, 7);
  assert.equal(report.required_artifact_count, 4);
  assert.equal(report.issues.some(({ code }) => code === 'MISSING_WECHAT_DEVTOOLS_CLI'), true);
  assert.equal(report.issues.some(({ code }) => code === 'MISSING_MINIPROGRAM_AUTOMATION_ARTIFACT_DIR'), true);
  assert.equal(report.issues.some(({ code }) => code === 'MISSING_MINIPROGRAM_TEST_IDENTITY'), true);
  assert.equal(report.issues.some(({ code }) => code === 'MISSING_MINIPROGRAM_NETWORK_PROFILE'), true);
  assert.equal(report.external_requirements.includes('WECHAT_DEVTOOLS_CLI'), true);
  assert.equal(report.external_requirements.includes('MINIPROGRAM_TEST_ACCOUNT or MINIPROGRAM_LOGIN_STATE'), true);
  assert.equal(report.external_requirements.includes('MINIPROGRAM_NETWORK_PROFILE'), true);
  assert.equal(report.external_requirements.includes('miniprogram-automator'), true);
  assert.equal(report.execution_plan.main_journeys.some(({ id }) => id === 'drill'), true);
  assert.equal(report.execution_plan.recovery_scenarios.some(({ id }) => id === 'transport_rollback'), true);
});

test('自动化产物路径固定且缺失时 fail-closed', () => {
  const root = new URL('../', import.meta.url).pathname;
  const artifactPaths = buildAutomationArtifactPaths({ env: {}, root });
  const report = verifyAutomationArtifacts({ env: {}, root });

  assert.equal(Object.keys(artifactPaths).length, 4);
  assert.equal(artifactPaths.network_trace.endsWith('/var/miniprogram-automation/network-trace.json'), true);
  assert.equal(report.status, 'failed');
  assert.deepEqual(report.issues.map(({ artifact }) => artifact).sort(), [
    'journey_screenshots',
    'network_trace',
    'storage_snapshot',
    'tap_report',
  ]);
});
