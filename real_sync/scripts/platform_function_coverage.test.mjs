import assert from 'node:assert/strict';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

import { buildPlatformInventory } from './platform_inventory.mjs';
import {
  functionCoverage,
  summarizeFunctionCoverage,
  summarizeNodeTestRun,
  validateFunctionCoverage,
} from './platform_function_coverage.mjs';

const projectRoot = fileURLToPath(new URL('../', import.meta.url));
const inventory = buildPlatformInventory({ projectRoot });

test('89 个功能组与 inventory 双向一致且证据完整', () => {
  assert.equal(functionCoverage.length, 89);
  assert.equal(new Set(functionCoverage.map(({ id }) => id)).size, 89);
  assert.deepEqual(validateFunctionCoverage(functionCoverage, inventory, { projectRoot }), []);
});

test('覆盖摘要报告生命周期、目标状态、测试与外部门禁', () => {
  const summary = summarizeFunctionCoverage(functionCoverage);
  assert.equal(summary.coverage_group_count, 89);
  assert.ok(summary.coverage_test_file_count > 20);
  assert.equal(summary.coverage_lifecycle_counts.deployed, 60);
  assert.equal(summary.coverage_lifecycle_counts.implemented, 27);
  assert.equal(summary.coverage_lifecycle_counts.planned, 2);
  assert.equal(summary.coverage_target_lifecycle_counts.verified, 87);
  assert.equal(summary.coverage_target_lifecycle_counts.planned, 2);
  assert.ok(summary.coverage_release_verification_counts.blocked_external > 0);
  assert.ok(summary.coverage_release_verification_counts.approval_required > 0);
});

test('验证器对缺项、重复项、证据缺失和生命周期漂移 fail closed', () => {
  const invalid = structuredClone(functionCoverage);
  invalid.pop();
  invalid[1].id = invalid[0].id;
  invalid[2].static_evidence = ['missing/evidence.php'];
  invalid[3].lifecycle = 'implemented';
  invalid[4].release_verification = 'unknown';
  const codes = new Set(validateFunctionCoverage(invalid, inventory, { projectRoot }).map(({ code }) => code));
  assert.ok(codes.has('COVERAGE_COUNT'));
  assert.ok(codes.has('DUPLICATE_COVERAGE_ID'));
  assert.ok(codes.has('MISSING_COVERAGE_ID'));
  assert.ok(codes.has('MISSING_COVERAGE_EVIDENCE'));
  assert.ok(codes.has('COVERAGE_LIFECYCLE_DRIFT'));
  assert.ok(codes.has('INVALID_RELEASE_VERIFICATION'));
});

test('Node 测试输出聚合为稳定的逐项覆盖结果', () => {
  const output = '# tests 37\n# pass 35\n# fail 1\n# skipped 1\n';
  assert.deepEqual(summarizeNodeTestRun(output, 1, 12, 89), {
    status: 'failed',
    exit_code: 1,
    covered_group_count: 89,
    test_file_count: 12,
    test_count: 37,
    passed_test_count: 35,
    failed_test_count: 1,
    skipped_test_count: 1,
  });
});
