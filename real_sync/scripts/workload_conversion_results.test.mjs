import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';

const read = (relativePath) => readFileSync(new URL(relativePath, import.meta.url), 'utf8');
const service = read('../api/workload/services/WorkloadConversionResultQueryService.php');
const myReport = read('../api/workload/my-report.php');
const auditList = read('../api/workload/audit-list.php');
const admin = read('../admin/workload.html');
const miniProgram = read('../mini-program/pages/workload/index.js');
const miniProgramView = read('../mini-program/pages/workload/index.wxml');
const servicePath = fileURLToPath(new URL('../api/workload/services/WorkloadConversionResultQueryService.php', import.meta.url));

test('conversion query keeps historical rule snapshots and review point states', () => {
  for (const table of ['workload_report_conversion_results', 'workload_conversion_rules', 'workload_conversion_rule_versions']) {
    assert.match(service, new RegExp(table));
  }
  for (const field of ['raw_value', 'pending_points', 'effective_points', 'rejected_points', 'rule_version_code', 'completion_state']) {
    assert.match(service, new RegExp(`'${field}'`));
  }
  assert.match(service, /'required_points' => 4\.0/);
  assert.match(service, /max\(0, \$summary\['required_points'\] - \$summary\['effective_points'\]\)/);
  assert.match(service, /function summaryForScope/);
  assert.match(service, /report\.report_date BETWEEN \? AND \?/);
  assert.match(service, /permissionScope\['scope_type'\]/);
});

test('employee and audit contracts expose conversion details without weakening their existing scope', () => {
  for (const endpoint of [myReport, auditList]) {
    assert.match(endpoint, /WorkloadConversionResultQueryService/);
    assert.match(endpoint, /'conversion_results'/);
    assert.match(endpoint, /'conversion_summary'/);
  }
  assert.match(myReport, /workloadAllowedRoleForContext/);
  assert.match(myReport, /appRequireViewStore/);
  assert.match(auditList, /WorkloadPermissionScopeService/);
  assert.match(auditList, /scope_type.*staff/);
});

test('admin and mini program render effective, pending, rejected, and gap semantics', () => {
  assert.match(admin, /function conversionKpis\(summary\)/);
  for (const field of ['effective_points', 'pending_points', 'rejected_points', 'gap_points', 'conversion_results']) {
    assert.match(admin, new RegExp(field));
  }
  assert.match(miniProgram, /conversionSummary/);
  assert.match(miniProgram, /effective_points/);
  assert.match(miniProgram, /required_points/);
  assert.match(miniProgram, /gap_points/);
  assert.match(miniProgramView, /工作量换算结果/);
  assert.match(admin, /if \(!summary\) return "";/);
});

test('metric analytics returns conversion KPI data for compatible filters and permission scope', () => {
  const metricSelection = read('../api/workload/services/WorkloadMetricSelectionService.php');
  assert.match(metricSelection, /WorkloadConversionResultQueryService/);
  assert.match(metricSelection, /'conversion_summary'/);
  assert.match(metricSelection, /summaryForScope\(\$filters, \$permissionScope\)/);
  assert.match(service, /'report\.submit_status', \$filters\['report_statuses'\]/);
  assert.match(service, /'report\.source', \$filters\['sources'\]/);
  assert.match(service, /if \(\$allowedStoreIds === \[\]\) \{\s*return \$this->hasFactLevelFilters\(\$filters\) \? null : self::aggregate\(\[\]\);/s);
  assert.match(service, /\$summary\['required_points'\] = round\(\$summary\['report_count'\] \* 4, 2\)/);
});

test('scope summary keeps reports without conversion rows in the completion denominator', () => {
  assert.match(service, /foreach \(\$reportIds as \$reportId\) \{\s*\$resultsByReport\[\$reportId\] \?\?= \[\];/s);
  const php = [
    `require_once ${JSON.stringify(servicePath)};`,
    `$summary = WorkloadConversionResultQueryService::aggregate([1 => [], 2 => [['effective_points' => 4]]]);`,
    'echo json_encode($summary);',
  ].join('\n');
  const result = spawnSync('php', ['-r', php], { encoding: 'utf8', timeout: 10_000 });
  assert.equal(result.status, 0, result.stderr);
  const summary = JSON.parse(result.stdout);
  assert.equal(summary.report_count, 2);
  assert.equal(summary.completed_report_count, 1);
  assert.equal(summary.required_points, 8);
  assert.equal(summary.completion_rate, 0.5);
});

test('conversion queries split large report scopes into bounded batches', () => {
  const php = [
    `require_once ${JSON.stringify(servicePath)};`,
    'class BatchStatement extends PDOStatement {',
    '  private array $params = [];',
    '  public function __construct(private BatchPdo $owner) {}',
    '  public function execute(?array $params = null): bool {',
    '    $this->params = $params ?? [];',
    '    $this->owner->batchSizes[] = count($this->params);',
    '    return true;',
    '  }',
    '  public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {',
    '    return array_map(static fn($id) => [',
    '      "report_id" => $id, "conversion_rule_id" => $id,',
    '      "rule_snapshot_json" => "{}", "raw_value" => 1,',
    '      "pending_points" => 0, "effective_points" => 1, "rejected_points" => 0,',
    '      "completion_state" => "not_met", "explanation" => "", "rule_version_code" => "v1",',
    '    ], $this->params);',
    '  }',
    '}',
    'class BatchPdo extends PDO {',
    '  public array $batchSizes = [];',
    '  public int $prepareCount = 0;',
    '  public function __construct() {}',
    '  public function prepare(string $query, array $options = []): PDOStatement|false {',
    '    $this->prepareCount++;',
    '    return new BatchStatement($this);',
    '  }',
    '}',
    '$pdo = new BatchPdo();',
    '$service = new WorkloadConversionResultQueryService($pdo);',
    '$grouped = $service->forReports(range(1, 1201));',
    'echo json_encode(["batch_sizes" => $pdo->batchSizes, "report_count" => count($grouped)]);',
  ].join('\n');
  const result = spawnSync('php', ['-r', php], { encoding: 'utf8', timeout: 10_000 });
  assert.equal(result.status, 0, result.stderr);
  const payload = JSON.parse(result.stdout);
  assert.deepEqual(payload.batch_sizes, [500, 500, 201]);
  assert.equal(payload.report_count, 1201);
});

test('fact-level filters mark aggregate conversion KPI as unavailable', () => {
  const php = [
    `require_once ${JSON.stringify(servicePath)};`,
    'class GuardPdo extends PDO {',
    '  public int $prepareCount = 0;',
    '  public function __construct() {}',
    '  public function prepare(string $query, array $options = []): PDOStatement|false {',
    '    $this->prepareCount++;',
    '    throw new RuntimeException("conversion query should stay unused");',
    '  }',
    '}',
    '$pdo = new GuardPdo();',
    '$service = new WorkloadConversionResultQueryService($pdo);',
    '$base = ["date_from" => "2026-08-01", "date_to" => "2026-08-02"];',
    '$metric = $service->summaryForScope($base + ["metric_codes" => ["sales"]], ["scope_type" => "all"]);',
    '$audit = $service->summaryForScope($base + ["audit_statuses" => ["approved"]], ["scope_type" => "all"]);',
    'echo json_encode(["metric" => $metric, "audit" => $audit, "prepare_count" => $pdo->prepareCount]);',
  ].join('\n');
  const result = spawnSync('php', ['-r', php], { encoding: 'utf8', timeout: 10_000 });
  assert.equal(result.status, 0, result.stderr);
  assert.deepEqual(JSON.parse(result.stdout), { metric: null, audit: null, prepare_count: 0 });
});
