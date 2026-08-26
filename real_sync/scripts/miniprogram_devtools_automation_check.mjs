#!/usr/bin/env node

import { existsSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const currentFile = fileURLToPath(import.meta.url);
const projectRoot = resolve(dirname(currentFile), '..');

export const MAIN_JOURNEY_STEPS = Object.freeze([
  { id: 'password_login', route: 'pages/login/login', action: 'password_login', assertions: ['token', 'session_id', 'session_type'] },
  { id: 'wechat_login_or_bind', route: 'pages/wechat-bind/gate', action: 'wechat_bind', assertions: ['pending_wechat_bind_ticket', 'session_version'] },
  { id: 'home', route: 'pages/index/index', action: 'open_home', assertions: ['todos', 'capabilities'] },
  { id: 'privacy', route: 'pages/agreement/privacy', action: 'open_privacy', assertions: ['privacy_content', 'subscription_message_purpose'] },
  { id: 'knowledge', route: 'pages/knowledge/list', action: 'open_knowledge', assertions: ['search_filter', 'detail_reading'] },
  { id: 'workload', route: 'pages/workload/index', action: 'open_workload', assertions: ['state_version', 'media_upload'] },
  { id: 'mine', route: 'pages/mine/mine', action: 'open_mine', assertions: ['profile', 'logout'] },
  { id: 'drill', route: 'pages/drill/list/list', action: 'open_drill', assertions: ['attempt', 'audio_recovery', 'ai_status'] },
]);

export const RECOVERY_SCENARIOS = Object.freeze([
  { id: 'cloud_function_timeout', trigger: 'callFunction timeout', expected: 'timeout_retryable' },
  { id: 'concurrent_401_refresh', trigger: 'parallel 401 responses', expected: 'single_refresh_replay' },
  { id: 'media_resume', trigger: 'cloud file registration retry', expected: 'stable_asset_key' },
  { id: 'ai_processing', trigger: 'long-running drill scoring', expected: 'retry_pending_status' },
  { id: 'page_restore', trigger: 'app hide and relaunch', expected: 'state_restored_from_storage' },
  { id: 'state_conflict_409', trigger: 'stale state_version write', expected: 'authoritative_state_exposed' },
  { id: 'transport_rollback', trigger: 'emergency transport switch', expected: 'direct_transport_selected' },
]);

export const AUTOMATION_EXECUTION_PROTOCOL = Object.freeze({
  runner: 'miniprogram-automator',
  launch_environment: [
    'WECHAT_DEVTOOLS_CLI',
    'MINIPROGRAM_AUTOMATION_PROJECT',
    'MINIPROGRAM_AUTOMATION_ARTIFACT_DIR',
    'MINIPROGRAM_TEST_ACCOUNT or MINIPROGRAM_LOGIN_STATE',
    'MINIPROGRAM_NETWORK_PROFILE',
  ],
  required_artifacts: ['tap_report', 'journey_screenshots', 'network_trace', 'storage_snapshot'],
  fail_closed_assertions: ['missing_route', 'missing_assertion', 'unexpected_transport', 'raw_business_url'],
});

const NETWORK_PROFILES = new Set(['devtools', 'real_device', 'weak_3g', 'weak_4g', 'offline_recovery']);

export function buildAutomationArtifactPaths({ env = process.env, root = projectRoot } = {}) {
  const artifactDir = String(env.MINIPROGRAM_AUTOMATION_ARTIFACT_DIR || resolve(root, 'var', 'miniprogram-automation')).trim();
  return {
    tap_report: resolve(artifactDir, 'tap-report.tap'),
    journey_screenshots: resolve(artifactDir, 'journey-screenshots'),
    network_trace: resolve(artifactDir, 'network-trace.json'),
    storage_snapshot: resolve(artifactDir, 'storage-snapshot.json'),
  };
}

export function buildAutomationExecutionPlan({ env = process.env, root = projectRoot } = {}) {
  const automationProject = String(env.MINIPROGRAM_AUTOMATION_PROJECT || resolve(root, 'mini-program')).trim();
  const artifactPaths = buildAutomationArtifactPaths({ env, root });
  return {
    schema_version: 1,
    runner: AUTOMATION_EXECUTION_PROTOCOL.runner,
    project: automationProject,
    launch_environment: AUTOMATION_EXECUTION_PROTOCOL.launch_environment,
    required_artifacts: AUTOMATION_EXECUTION_PROTOCOL.required_artifacts,
    artifact_paths: artifactPaths,
    launch_options: {
      cliPath: String(env.WECHAT_DEVTOOLS_CLI || '').trim(),
      projectPath: automationProject,
      accountSource: env.MINIPROGRAM_LOGIN_STATE ? 'MINIPROGRAM_LOGIN_STATE' : 'MINIPROGRAM_TEST_ACCOUNT',
      networkProfile: String(env.MINIPROGRAM_NETWORK_PROFILE || '').trim(),
    },
    fail_closed_assertions: AUTOMATION_EXECUTION_PROTOCOL.fail_closed_assertions,
    main_journeys: MAIN_JOURNEY_STEPS.map((step, order) => ({ order: order + 1, ...step })),
    recovery_scenarios: RECOVERY_SCENARIOS.map((scenario, order) => ({ order: order + 1, ...scenario })),
  };
}

export function verifyAutomationArtifacts({ env = process.env, root = projectRoot } = {}) {
  const paths = buildAutomationArtifactPaths({ env, root });
  const issues = Object.entries(paths)
    .filter(([, path]) => !existsSync(path))
    .map(([artifact, path]) => ({ code: 'MISSING_AUTOMATION_ARTIFACT', artifact, path }));

  return {
    schema_version: 1,
    status: issues.length === 0 ? 'passed' : 'failed',
    artifact_paths: paths,
    issues,
  };
}

export function evaluateAutomationReadiness({ env = process.env, root = projectRoot } = {}) {
  const issues = [];
  const cliPath = String(env.WECHAT_DEVTOOLS_CLI || '').trim();
  const automationProject = String(env.MINIPROGRAM_AUTOMATION_PROJECT || resolve(root, 'mini-program')).trim();
  const artifactDir = String(env.MINIPROGRAM_AUTOMATION_ARTIFACT_DIR || '').trim();
  const loginState = String(env.MINIPROGRAM_LOGIN_STATE || '').trim();
  const testAccount = String(env.MINIPROGRAM_TEST_ACCOUNT || '').trim();
  const networkProfile = String(env.MINIPROGRAM_NETWORK_PROFILE || '').trim();
  const executionPlan = buildAutomationExecutionPlan({ env, root });

  if (!cliPath) {
    issues.push({
      code: 'MISSING_WECHAT_DEVTOOLS_CLI',
      message: 'WECHAT_DEVTOOLS_CLI is required to run WeChat DevTools automation.',
    });
  } else if (!existsSync(cliPath)) {
    issues.push({ code: 'INVALID_WECHAT_DEVTOOLS_CLI', path: cliPath });
  }

  if (!existsSync(automationProject)) {
    issues.push({ code: 'MISSING_MINIPROGRAM_AUTOMATION_PROJECT', path: automationProject });
  }

  if (!artifactDir) {
    issues.push({ code: 'MISSING_MINIPROGRAM_AUTOMATION_ARTIFACT_DIR' });
  } else if (!existsSync(artifactDir)) {
    issues.push({ code: 'INVALID_MINIPROGRAM_AUTOMATION_ARTIFACT_DIR', path: artifactDir });
  }

  if (!testAccount && !loginState) {
    issues.push({ code: 'MISSING_MINIPROGRAM_TEST_IDENTITY' });
  } else if (loginState && !existsSync(loginState)) {
    issues.push({ code: 'INVALID_MINIPROGRAM_LOGIN_STATE', path: loginState });
  }

  if (!networkProfile) {
    issues.push({ code: 'MISSING_MINIPROGRAM_NETWORK_PROFILE' });
  } else if (!NETWORK_PROFILES.has(networkProfile)) {
    issues.push({ code: 'INVALID_MINIPROGRAM_NETWORK_PROFILE', value: networkProfile, allowed: [...NETWORK_PROFILES] });
  }

  return {
    schema_version: 1,
    status: issues.length === 0 ? 'ready' : 'blocked_external',
    runner: executionPlan.runner,
    journey_count: MAIN_JOURNEY_STEPS.length,
    recovery_scenario_count: RECOVERY_SCENARIOS.length,
    required_artifact_count: executionPlan.required_artifacts.length,
    external_requirements: [
      'WECHAT_DEVTOOLS_CLI',
      'MINIPROGRAM_AUTOMATION_PROJECT',
      'MINIPROGRAM_AUTOMATION_ARTIFACT_DIR',
      'MINIPROGRAM_TEST_ACCOUNT or MINIPROGRAM_LOGIN_STATE',
      'MINIPROGRAM_NETWORK_PROFILE',
      AUTOMATION_EXECUTION_PROTOCOL.runner,
      'WeChat DevTools automation service',
      'authorized mini program test account or reusable login state',
      'real device or DevTools network throttle profile',
    ],
    execution_plan: executionPlan,
    issues,
  };
}

if (process.argv[1] && resolve(process.argv[1]) === currentFile) {
  const report = evaluateAutomationReadiness();
  process.stdout.write(`${JSON.stringify(report, null, 2)}\n`);
  if (report.status !== 'ready') process.exitCode = 1;
}
