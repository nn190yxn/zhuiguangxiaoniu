import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const read = (relativePath) => readFileSync(new URL(relativePath, import.meta.url), 'utf8');
const queryService = read('../api/workload/services/WorkloadConversionResultQueryService.php');
const analyticsService = read('../api/workload/services/WorkloadAnalyticsQueryService.php');
const myReport = read('../api/workload/my-report.php');
const auditList = read('../api/workload/audit-list.php');
const miniProgram = read('../mini-program/pages/workload/index.js');
const miniProgramTemplate = read('../mini-program/pages/workload/index.wxml');
const adminPage = read('../admin/workload.html');
const exportService = read('../api/workload/services/WorkloadExportService.php');
const funnelService = read('../api/workload/services/WorkloadOperatingFunnelService.php');

test('[validates 3.1-3.3, 6.1-6.3] result queries use report conversion snapshots and preserve point buckets', () => {
  assert.match(queryService, /FROM workload_report_conversion_results result/);
  assert.match(queryService, /rule_snapshot_json/);
  assert.match(queryService, /pending_points/);
  assert.match(queryService, /effective_points/);
  assert.match(queryService, /rejected_points/);
  assert.match(queryService, /public static function aggregate/);
  assert.match(queryService, /required_points' => 4\.0/);
});

test('[validates 3.1-3.3] report, audit, and analytics responses expose the same conversion summary', () => {
  assert.match(myReport, /'conversion_results' => \$conversionResults/);
  assert.match(myReport, /'conversion_summary' => WorkloadConversionResultQueryService::summary/);
  assert.match(auditList, /\$row\['conversion_results'\] = \$conversionResults/);
  assert.match(auditList, /\$row\['conversion_summary'\] = WorkloadConversionResultQueryService::summary/);
  assert.match(analyticsService, /'conversion_summary' => WorkloadConversionResultQueryService::aggregate/);
  assert.match(analyticsService, /function attachConversion\(array &\$row, array \$results\): void/);
  assert.match(analyticsService, /conversion_effective_points/);
  assert.match(exportService, /'conversion_effective_points'/);
  assert.match(exportService, /有效工作量点数/);
  assert.match(funnelService, /'conversion_summary' => \$factsResult\['conversion_summary'\]/);
});

test('[validates 6.1-6.3] employee and reviewer views show effective, pending, rejected, gap, and rule explanations', () => {
  assert.match(miniProgram, /conversionResults/);
  assert.match(miniProgram, /conversionSummary/);
  assert.match(miniProgramTemplate, /有效 \{\{conversionSummary\.effective_points\}\} 点/);
  assert.match(miniProgramTemplate, /还差 \{\{conversionSummary\.gap_points\}\} 点/);
  assert.match(miniProgramTemplate, /\{\{item\.explanation\}\}/);
  assert.match(adminPage, /function conversionKpis/);
  assert.match(adminPage, /row\.conversion_results/);
  assert.match(adminPage, /有效工作量点数/);
});
