import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8');
const servicePath = fileURLToPath(new URL('../api/workload/services/WorkloadConversionResultQueryService.php', import.meta.url));
const service = read('../api/workload/services/WorkloadConversionResultQueryService.php');
const myReport = read('../api/workload/my-report.php');
const auditList = read('../api/workload/audit-list.php');

test('换算查询保留历史规则、权限范围与分批限制', () => {
  for (const value of ['workload_report_conversion_results', 'workload_conversion_rules', 'workload_conversion_rule_versions', 'summaryForScope', 'REPORT_ID_BATCH_SIZE']) assert.match(service, new RegExp(value));
  assert.match(service, /array_chunk\(\$reportIds, self::REPORT_ID_BATCH_SIZE\)/);
  assert.match(service, /report\.report_date BETWEEN \? AND \?/);
  assert.match(service, /permissionScope\['scope_type'\]/);
});

test('换算摘要同时处理点数与必做项', () => {
  const program = `require_once ${JSON.stringify(servicePath)}; echo json_encode(WorkloadConversionResultQueryService::summary([["conversion_mode" => "threshold", "effective_points" => 4], ["conversion_mode" => "required_check", "completion_state" => "met"]]));`;
  const result = spawnSync('php', ['-r', program], { encoding: 'utf8', timeout: 10_000 });
  assert.equal(result.status, 0, result.stderr);
  const summary = JSON.parse(result.stdout);
  assert.equal(summary.completion_state, 'met');
  assert.equal(summary.gap_points, 0);
});

test('日报、审核、指标和后台展示换算摘要', () => {
  for (const source of [myReport, auditList, read('../api/workload/services/WorkloadMetricSelectionService.php')]) {
    assert.match(source, /WorkloadConversionResultQueryService/);
    assert.match(source, /conversion_summary/);
  }
  assert.match(myReport, /workloadAllowedRoleForContext/);
  assert.match(myReport, /appRequireViewStore/);
  assert.match(auditList, /WorkloadPermissionScopeService/);
  const admin = read('../admin/workload.html');
  assert.match(admin, /function conversionKpis\(summary\)/);
  assert.match(admin, /conversion_summary/);
  assert.match(admin, /conversion_results/);
});

test('范围汇总保留无换算结果日报的完成率分母', () => {
  const program = [
    `require_once ${JSON.stringify(servicePath)};`,
    `$summary = WorkloadConversionResultQueryService::aggregate([1 => [], 2 => [['conversion_mode' => 'threshold', 'effective_points' => 4]]]);`,
    'echo json_encode($summary);',
  ].join('\n');
  const result = spawnSync('php', ['-r', program], { encoding: 'utf8', timeout: 10_000 });
  assert.equal(result.status, 0, result.stderr);
  const summary = JSON.parse(result.stdout);
  assert.equal(summary.report_count, 2);
  assert.equal(summary.completed_report_count, 1);
  assert.equal(summary.required_points, 8);
  assert.equal(summary.completion_rate, 0.5);
});

test('换算查询按固定上限分批处理日报', () => {
  const program = [
    `require_once ${JSON.stringify(servicePath)};`,
    'class BatchStatement extends PDOStatement {',
    '  public function __construct(private BatchPdo $owner) {}',
    '  public function execute(?array $params = null): bool { $this->owner->batchSizes[] = count($params ?? []); return true; }',
    '  public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return []; }',
    '}',
    'class BatchPdo extends PDO {',
    '  public array $batchSizes = [];',
    '  public function __construct() {}',
    '  public function prepare(string $query, array $options = []): PDOStatement|false { return new BatchStatement($this); }',
    '}',
    '$pdo = new BatchPdo();',
    '$service = new WorkloadConversionResultQueryService($pdo);',
    '$service->forReports(range(1, 1201));',
    'echo json_encode($pdo->batchSizes);',
  ].join('\n');
  const result = spawnSync('php', ['-r', program], { encoding: 'utf8', timeout: 10_000 });
  assert.equal(result.status, 0, result.stderr);
  assert.deepEqual(JSON.parse(result.stdout), [500, 500, 201]);
});

test('事实级筛选不执行不精确的汇总查询', () => {
  const program = [
    `require_once ${JSON.stringify(servicePath)};`,
    'class GuardPdo extends PDO {',
    '  public function __construct() {}',
    '  public function prepare(string $query, array $options = []): PDOStatement|false { throw new RuntimeException("query should stay unused"); }',
    '}',
    '$service = new WorkloadConversionResultQueryService(new GuardPdo());',
    '$summary = $service->summaryForScope(["date_from" => "2026-08-01", "date_to" => "2026-08-02", "metric_codes" => ["sales"]], ["scope_type" => "all"]);',
    'echo json_encode($summary);',
  ].join('\n');
  const result = spawnSync('php', ['-r', program], { encoding: 'utf8', timeout: 10_000 });
  assert.equal(result.status, 0, result.stderr);
  assert.equal(JSON.parse(result.stdout), null);
});
