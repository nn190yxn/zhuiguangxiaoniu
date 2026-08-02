import assert from 'node:assert/strict';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

import { evaluatePreflight, runPlatformPreflight } from './platform_preflight.mjs';

const projectRoot = fileURLToPath(new URL('../', import.meta.url));

test('平台预检在当前代码基线上通过并报告并行冻结警告', () => {
  const report = runPlatformPreflight({ projectRoot });

  assert.equal(report.status, 'passed');
  assert.deepEqual(report.blocking_issues, []);
  assert.equal(report.metrics.group_count, 89);
  assert.equal(report.metrics.coverage_group_count, 89);
  assert.ok(report.metrics.coverage_test_file_count > 20);
  assert.equal(report.metrics.mini_program_route_count, 32);
  assert.equal(report.metrics.mini_program_contract_category_count, 7);
  assert.deepEqual(report.checks.find(({ name }) => name === 'mini_program_contracts'), {
    name: 'mini_program_contracts',
    issue_count: 0,
  });
  assert.deepEqual(report.checks.find(({ name }) => name === 'function_coverage'), {
    name: 'function_coverage',
    issue_count: 0,
  });
  assert.ok(report.metrics.endpoint_count > 300);
  assert.ok(report.warnings.every(({ code }) => code === 'PARALLEL_CHANGE_FROZEN'));
});

test('预检评估器区分阻断问题与并行冻结警告', () => {
  const checks = [
    { name: 'inventory', issues: [{ code: 'MISSING_ASSET_PATH', path: 'api/missing.php' }] },
    { name: 'frozen_paths', issues: [{ code: 'PARALLEL_CHANGE_FROZEN', path: 'real_sync/api/workload/index.php' }] },
  ];
  const normal = evaluatePreflight(checks);
  const strict = evaluatePreflight(checks, { strictFrozen: true });

  assert.equal(normal.status, 'failed');
  assert.equal(normal.blocking_issues.length, 1);
  assert.equal(normal.warnings.length, 1);
  assert.equal(strict.blocking_issues.length, 2);
  assert.deepEqual(strict.warnings, []);
});
