import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8');
const migration = read('../database/migrations/202608130002_sales_deal_amount_manual_evidence.sql');
const conversionServicePath = fileURLToPath(new URL('../api/workload/services/WorkloadConversionResultService.php', import.meta.url));
const conversionService = read('../api/workload/services/WorkloadConversionResultService.php');
const template = read('../api/workload/template.php');
const miniProgram = read('../mini-program/pages/workload/index.wxml');
const h5 = read('../mobile/workload-v2.html');

test('成交金额迁移使用独立规则版本并保留历史销售规则区间', () => {
  assert.match(migration, /'sales-v4-deal-amount-manual'/);
  assert.match(migration, /SET effective_to = '2026-08-12'/);
  assert.match(migration, /effective_from < '2026-08-13'/);
  assert.match(migration, /source_rule\.metric_code/);
  assert.match(migration, /source_rule\.rule_code/);
  assert.doesNotMatch(migration, /DELETE\s+FROM/i);
  assert.doesNotMatch(migration, /DROP\s+(?:TABLE|COLUMN|INDEX)/i);
});

test('成交金额规则要求截图审核，并按 4000 元两档折算', () => {
  for (const value of ["'sales_deal_amount'", "'tier'", '1, 1, 10, \'full\'', '"min":4000', 'daily_cap_points']) {
    assert.match(migration, new RegExp(value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
  }
  assert.match(migration, /填写当天全部成交总金额/);
  assert.match(migration, /成交系统截图/);
});

test('金额两档换算覆盖零值、未满档和满档', () => {
  const program = [
    `require_once ${JSON.stringify(conversionServicePath)};`,
    'class TestPdo extends PDO { public function __construct() {} }',
    '$service = new WorkloadConversionResultService(new TestPdo());',
    '$method = new ReflectionMethod(WorkloadConversionResultService::class, "convertedPoints");',
    '$method->setAccessible(true);',
    '$tiers = [["min" => 0.01, "max" => 3999.99, "points" => 1, "priority" => 1], ["min" => 4000, "points" => 2, "priority" => 2]];',
    'echo json_encode([$method->invoke($service, ["sales_deal_amount" => 0], "tier", null, null, 2, $tiers, false), $method->invoke($service, ["sales_deal_amount" => 3999.99], "tier", null, null, 2, $tiers, false), $method->invoke($service, ["sales_deal_amount" => 4000], "tier", null, null, 2, $tiers, false)]);',
  ].join('\n');
  const result = spawnSync('php', ['-r', program], { encoding: 'utf8', timeout: 10_000 });
  assert.equal(result.status, 0, result.stderr);
  assert.deepEqual(JSON.parse(result.stdout), [0, 1, 2]);
  assert.match(conversionService, /\$conversionMode === 'tier'/);
  assert.match(conversionService, /大于 0 元计 1 点，满 4000 元计 2 点/);
});

test('各端展示当天全部成交金额、两点门槛和成交系统截图', () => {
  assert.match(template, /当天全部成交总金额。金额大于 0 元计 1 点，满 4000 元计 2 点；提交时上传成交系统截图/);
  assert.match(miniProgram, /成交系统截图/);
  assert.match(h5, /成交系统截图/);
  assert.match(h5, /item\.input_hint/);
});
