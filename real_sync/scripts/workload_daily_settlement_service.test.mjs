import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const service = readFileSync(new URL(
  '../api/workload/services/WorkloadDailySettlementService.php',
  import.meta.url,
), 'utf8');
const saveReport = readFileSync(new URL('../api/workload/save-report.php', import.meta.url), 'utf8');
const reportState = readFileSync(new URL(
  '../api/workload/services/WorkloadReportStateService.php',
  import.meta.url,
), 'utf8');
const auditTasks = readFileSync(new URL(
  '../api/workload/services/WorkloadAuditTaskService.php',
  import.meta.url,
), 'utf8');
const worker = readFileSync(new URL('../api/workload/settlement-worker.php', import.meta.url), 'utf8');
const closureMigration = readFileSync(new URL('../database/migrations/202608120001_workload_daily_closure.sql', import.meta.url), 'utf8');
const conversionWriter = readFileSync(new URL('../api/workload/services/WorkloadConversionResultService.php', import.meta.url), 'utf8');
const reportedPointsMigration = readFileSync(new URL('../database/migrations/202608120004_workload_conversion_reported_points.sql', import.meta.url), 'utf8');

test('daily settlement refresh is transactional, scoped, and idempotent', () => {
  assert.match(service, /if \(!\$this->pdo->inTransaction\(\)\)/);
  assert.match(service, /FOR UPDATE/);
  assert.match(closureMigration, /UNIQUE KEY uq_workload_daily_settlement_scope/);
  assert.match(service, /ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID\(id\)/);
  assert.match(service, /refreshReport\(int \$reportId/);
  assert.match(service, /refreshScope\(/);
  assert.match(service, /refreshDate\(/);
});

test('daily settlement derives four-point gaps and business-time statuses', () => {
    assert.match(service, /private const TARGET_POINTS = 4\.0/);
    assert.match(service, /POINT_SETTLEMENT_ROLES = \['sales', 'coach'\]/);
    assert.match(service, /management_statistics_only/);
    assert.match(service, /not_applicable/);
    assert.match(service, /max\(0, \$targetPoints - \$totals\['effective_points'\]\)/);
  assert.match(service, /\+2 days/);
  for (const status of ['today_open', 'makeup_open', 'completed', 'overdue']) {
    assert.match(service, new RegExp(`'${status}'`));
  }
});

test('daily settlement uses stored conversion outcomes and preserves their snapshots', () => {
  assert.match(service, /FROM workload_report_conversion_results result/);
  assert.match(service, /result\.reported_points/);
  assert.match(service, /result\.rule_snapshot_json/);
  assert.match(service, /'conversion_rules' => \$totals\['rule_snapshots'\]/);
  assert.doesNotMatch(service, /UPDATE\s+workload_daily_reports/i);
});

test('management roles keep statistics and bypass point settlement and penalties', () => {
  assert.match(service, /POINT_SETTLEMENT_ROLES = \['sales', 'coach'\]/);
  assert.match(service, /if \(!in_array\(\$roleCode, self::POINT_SETTLEMENT_ROLES, true\)\)/);
  assert.match(service, /settlement_id' => null/);
  assert.match(service, /target_points' => 0\.0/);
  assert.match(service, /settlement_status' => 'not_applicable'/);
  assert.match(service, /'applicable' => false/);
  assert.match(service, /'reason' => 'management_statistics_only'/);
});

test('conversion refresh stores converted reported points separately from source values', () => {
  assert.match(reportedPointsMigration, /ADD COLUMN reported_points DECIMAL\(18,2\) NOT NULL DEFAULT 0\.00/);
  assert.match(conversionWriter, /reported_points, pending_points, effective_points/);
  assert.match(conversionWriter, /points_per_match/);
  assert.match(conversionWriter, /daily_cap_points/);
  assert.doesNotMatch(service, /result\.raw_value/);
});

test('report submission, audit changes, corrections, and worker refresh settlements', () => {
  assert.match(saveReport, /WorkloadDailySettlementService/);
  assert.match(saveReport, /->refreshReport\(\$reportId\)/);
  assert.match(auditTasks, /->refreshScope\(/);
  assert.match(reportState, /->refreshReport\(\$reportId\)/);
  assert.doesNotMatch(reportState, /至少四个工作量指标具有正数值/);
  assert.match(worker, /WorkloadDailySettlementService/);
  assert.match(worker, /->refreshDate\(\$businessDate\)/);
});
