import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

import { buildPlatformInventory, parseCapabilityMatrix, validatePlatformInventory } from './platform_inventory.mjs';

const projectRoot = fileURLToPath(new URL('../', import.meta.url));
const matrixPath = fileURLToPath(
  new URL('../../.monkeycode/specs/2026-07-31-full-site-multi-client-architecture-upgrade/function-matrix.md', import.meta.url),
);

test('功能矩阵解析出 89 个唯一组级 ID 和统一生命周期', () => {
  const groups = parseCapabilityMatrix(readFileSync(matrixPath, 'utf8'));
  const validLifecycle = new Set(['planned', 'in_development', 'implemented', 'deployed', 'verified', 'deprecated']);

  assert.equal(groups.length, 89);
  assert.equal(new Set(groups.map(({ id }) => id)).size, groups.length);
  assert.ok(groups.every(({ lifecycle }) => validLifecycle.has(lifecycle)));
});

test('资产扫描结果确定且覆盖全部资产类型', () => {
  const first = buildPlatformInventory({ projectRoot, matrixPath });
  const second = buildPlatformInventory({ projectRoot, matrixPath });
  const requiredTypes = ['ai', 'api', 'cron', 'file', 'migration', 'mini-page', 'page', 'pwa', 'worker'];

  assert.deepEqual(first, second);
  assert.equal(first.groups.length, 89);
  assert.equal(new Set(first.assets.map(({ id }) => id)).size, first.assets.length);
  assert.deepEqual(Object.keys(first.summary.type_counts).sort(), requiredTypes);
  assert.ok(first.assets.every(({ group_ids: groupIds }) => groupIds.length > 0));
});

test('工作量与招聘并行资产进入冻结所有权', () => {
  const inventory = buildPlatformInventory({ projectRoot, matrixPath });
  const expectedFrozen = [
    'api/workload/',
    'mini-program/pages/workload/',
    'api/admin/recruitment/',
    'api/recruitment/',
  ];

  for (const prefix of expectedFrozen) {
    const matching = inventory.assets.filter(({ path }) => path.startsWith(prefix));
    assert.ok(matching.length > 0, `${prefix} 应至少发现一个资产`);
    assert.ok(matching.every(({ ownership }) => ownership === 'parallel-change-frozen'));
  }
});

test('完整清单通过路径、引用、类型和冻结边界验证', () => {
  const inventory = buildPlatformInventory({ projectRoot, matrixPath });

  assert.deepEqual(validatePlatformInventory(inventory, { projectRoot }), []);
});

test('清单验证器返回稳定问题码', () => {
  const inventory = buildPlatformInventory({ projectRoot, matrixPath });
  const invalid = structuredClone(inventory);
  invalid.groups[0].lifecycle = 'unknown';
  invalid.assets[0].group_ids = ['UNKNOWN-001'];
  invalid.assets[1].id = invalid.assets[0].id;
  invalid.assets[2].path = 'missing/example.php';

  const issues = validatePlatformInventory(invalid, { projectRoot });
  const codes = new Set(issues.map(({ code }) => code));
  assert.ok(codes.has('INVALID_LIFECYCLE'));
  assert.ok(codes.has('UNKNOWN_GROUP_REFERENCE'));
  assert.ok(codes.has('DUPLICATE_ASSET_ID'));
  assert.ok(codes.has('MISSING_ASSET_PATH'));
});
