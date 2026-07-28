import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const root = new URL('../', import.meta.url);
const read = (path) => readFileSync(new URL(path, root), 'utf8');
const migration = read('database/migrations/202607280001_workload_store_offline_actions.sql');
const common = read('api/workload/_common.php');
const saveReport = read('api/workload/save-report.php');
const myReport = read('api/workload/my-report.php');
const template = read('api/workload/template.php');
const auditList = read('api/workload/audit-list.php');
const auditTaskService = read('api/workload/services/WorkloadAuditTaskService.php');
const h5 = read('mobile/workload-v2.html');
const miniJs = read('mini-program/pages/workload/index.js');
const miniWxml = read('mini-program/pages/workload/index.wxml');

test('manager store offline actions are seeded with evidence requirements', () => {
  for (const metricCode of [
    'manager_store_poi_checkin',
    'manager_store_favorite',
    'manager_nine_image_review',
    'manager_three_image_review',
    'manager_online_order_count',
    'manager_online_order_amount',
    'manager_video_post',
  ]) {
    assert.match(migration, new RegExp(metricCode));
  }
  assert.match(migration, /need_evidence[\s\S]*'evidence'/);
  assert.match(migration, /manager-store-offline-v4/);
  assert.match(migration, /metric\.max_value/);
  assert.doesNotMatch(migration, /WHEN metric\.metric_code = 'manager_store_poi_checkin' THEN 5[\s\S]*?metric\.max_value/);
});

test('coach and sales point-light uploads can satisfy the manager store metric', () => {
  assert.match(migration, /sales_store_poi_checkin/);
  assert.match(migration, /coach_store_poi_checkin/);
  assert.match(common, /workloadManagerStoreMetricSummary/);
  assert.match(common, /report\.role_code IN \('coach', 'sales'\)/);
  assert.match(common, /evidence\.metric_code IN \('coach_store_poi_checkin', 'sales_store_poi_checkin'\)/);
  assert.match(common, /function workloadReportEvidenceGapCount/);
  assert.match(common, /\$evidenceCounts = workloadApplyManagerStoreEvidenceCounts\(/);
  assert.match(saveReport, /workloadApplyManagerStoreMetricSummary/);
  assert.match(saveReport, /workloadApplyManagerStoreEvidenceCounts/);
  assert.match(myReport, /'store_metric_summary' => \$storeMetricSummary/);
  assert.match(auditList, /store_ev/);
  assert.match(auditList, /source_report\.role_code IN \('coach', 'sales'\)/);
  assert.match(auditList, /CONCAT_WS\(',', ev\.evidence_urls, store_ev\.evidence_urls\) AS evidence_urls/);
  assert.match(auditList, /\$row\['evidence_urls'\] = array_values\(array_filter\(\$urls/);
  assert.match(auditTaskService, /evidence_report\.role_code IN \('coach', 'sales'\)/);
});

test('employee clients expose manager workload and aggregate tips', () => {
  assert.match(h5, /<option value="manager">店长<\/option>/);
  assert.match(h5, /storeMetricSummary/);
  assert.match(h5, /storeMetricTip/);
  assert.match(h5, /function evidenceCountForMetric\(metricCode\)/);
  assert.match(h5, /metricCode==='manager_store_poi_checkin'\?Math\.max\(ownCount,aggregateCount\):ownCount/);
  assert.match(miniJs, /value: 'manager'/);
  assert.match(miniJs, /storeMetricSummary/);
  assert.match(miniJs, /evidenceCountForMetric\(metricCode\)/);
  assert.match(miniJs, /metricCode === 'manager_store_poi_checkin' \? Math\.max\(ownCount, aggregateCount\) : ownCount/);
  assert.match(miniWxml, /aggregate_tip/);
});

test('coach workload description states sales contribution and 4000 tier', () => {
  assert.match(migration, /销售相关产出也计算工作量，4000 元为销售档位口径/);
  assert.match(template, /'description' => \$ruleVersion\['description'\]/);
  assert.match(h5, /templateDescription/);
  assert.match(miniWxml, /templateDescription/);
});
