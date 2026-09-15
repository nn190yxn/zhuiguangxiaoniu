import assert from 'node:assert/strict';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

import {
  classifyStageResult,
  evidenceSummary,
  evaluateRegressionPreflight,
  loadRegressionPreflightConfig,
} from './platform_regression_preflight.mjs';

const projectRoot = fileURLToPath(new URL('../', import.meta.url));

test('发布预检配置覆盖 13.3 的全部本地门禁领域', () => {
  const config = loadRegressionPreflightConfig({ projectRoot });
  const stageIds = new Set(config.stages.map(({ id }) => id));

  for (const stageId of [
    'inventory_89',
    'node_all',
    'php_lint_all',
    'migration_catalog',
    'migration_compatibility',
    'migration_readiness_dry_run',
    'platform_preflight',
    'miniprogram_cloudbase_contracts',
    'miniprogram_devtools_automation',
    'permission_matrix',
    'state_sync',
    'file_policy',
    'job_recovery',
    'ai_compliance',
    'legacy_endpoint_governance',
    'miniprogram_ten_domains',
    'documentation_links',
    'git_diff_check',
  ]) {
    assert.ok(stageIds.has(stageId), `missing stage ${stageId}`);
  }
  assert.equal(config.schema_version, 1);
  assert.ok(config.stages.every(({ waves }) => Array.isArray(waves) && waves.length > 0));
  assert.equal(config.stages.some(({ command }) => /apply(?!.*--dry-run)/.test(command.join(' '))), false);

  const cloudbaseStage = config.stages.find(({ id }) => id === 'miniprogram_cloudbase_contracts');
  for (const file of [
    'scripts/miniprogram_api_proxy.test.mjs',
    'scripts/miniprogram_auth_proxy.test.mjs',
    'scripts/miniprogram_media_contract.test.mjs',
    'scripts/miniprogram_drill_cloud_path.test.mjs',
    'scripts/miniprogram_sales_drill_experience.test.mjs',
    'scripts/miniprogram_api_client.test.mjs',
    'scripts/miniprogram_devtools_automation.test.mjs',
    'scripts/migration_runner.test.mjs',
    'scripts/migration_compatibility.property.test.mjs',
    'scripts/platform_release_gate.test.mjs',
  ]) {
    assert.ok(cloudbaseStage.command.includes(file), `missing cloudbase contract ${file}`);
  }
});

test('关键本地门禁失败时预检 fail closed', () => {
  const report = evaluateRegressionPreflight([
    { id: 'node_all', classification: 'required_local', status: 'failed', exit_code: 1 },
    { id: 'migration_readiness_dry_run', classification: 'external_environment', status: 'blocked_external', exit_code: 1 },
  ]);

  assert.equal(report.status, 'failed');
  assert.equal(report.exit_code, 1);
  assert.deepEqual(report.blocking_stage_ids, ['node_all']);
  assert.deepEqual(report.blocked_external_stage_ids, ['migration_readiness_dry_run']);
});

test('外部环境与人工审批保持显式分类且不伪造本地失败', () => {
  assert.equal(classifyStageResult({ classification: 'external_environment' }, 1, '', 'DB_PASSWORD is required'), 'blocked_external');
  assert.equal(classifyStageResult({ classification: 'external_environment' }, 1, '', 'MISSING_WECHAT_DEVTOOLS_CLI'), 'blocked_external');
  assert.equal(classifyStageResult({ classification: 'external_environment' }, 1, '', 'MISSING_MINIPROGRAM_TEST_IDENTITY'), 'blocked_external');
  assert.equal(classifyStageResult({ classification: 'external_environment' }, 1, '', 'MISSING_MINIPROGRAM_NETWORK_PROFILE'), 'blocked_external');
  assert.equal(classifyStageResult({ classification: 'external_environment' }, 1, '', 'MISSING_MINIPROGRAM_AUTOMATION_ARTIFACT_DIR'), 'blocked_external');
  assert.equal(classifyStageResult({ classification: 'approval' }, 0, '', ''), 'approval_required');
  assert.equal(classifyStageResult({ classification: 'required_local' }, 1, '', ''), 'failed');

  const report = evaluateRegressionPreflight([
    { id: 'inventory_89', waves: [0], classification: 'required_local', status: 'passed', exit_code: 0 },
    { id: 'migration_readiness_dry_run', waves: [0], classification: 'external_environment', status: 'blocked_external', exit_code: 1 },
    { id: 'production_release', waves: [0], classification: 'approval', status: 'approval_required', exit_code: null },
  ]);
  assert.equal(report.status, 'passed_with_gates');
  assert.equal(report.exit_code, 0);
  assert.deepEqual(report.wave_evidence, [{
    wave: 0,
    status: 'passed_with_gates',
    stage_ids: ['inventory_89', 'migration_readiness_dry_run', 'production_release'],
  }]);
});

test('每个阶段证据包含名称、命令、耗时、状态和摘要', () => {
  const report = evaluateRegressionPreflight([{
    id: 'inventory_89',
    name: '89 项资产覆盖',
    waves: [0],
    classification: 'required_local',
    command: ['node', 'scripts/platform_function_coverage.mjs'],
    duration_ms: 12,
    status: 'passed',
    exit_code: 0,
    evidence_summary: 'coverage_group_count=89',
  }]);

  for (const field of ['name', 'command', 'duration_ms', 'status', 'evidence_summary']) {
    assert.ok(field in report.stages[0]);
  }
});

test('DevTools 外部门禁摘要列出缺失条件代码', () => {
  const summary = evidenceSummary({ id: 'miniprogram_devtools_automation' }, {
    stdout: JSON.stringify({
      status: 'blocked_external',
      issues: [
        { code: 'MISSING_WECHAT_DEVTOOLS_CLI' },
        { code: 'MISSING_MINIPROGRAM_TEST_IDENTITY' },
      ],
    }),
    stderr: '',
  });

  assert.equal(
    summary,
    'status=blocked_external, issues=MISSING_WECHAT_DEVTOOLS_CLI|MISSING_MINIPROGRAM_TEST_IDENTITY'
  );
});
