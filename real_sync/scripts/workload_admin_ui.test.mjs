import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const page = readFileSync(new URL('../admin/workload.html', import.meta.url), 'utf8');

test('unified filter bar covers every analytics dimension and applied summary', () => {
  for (const id of [
    'dateFrom',
    'dateTo',
    'storeId',
    'roleCode',
    'staffId',
    'metricCode',
    'reportStatus',
    'auditStatus',
    'source',
  ]) {
    assert.match(page, new RegExp(`id="${id}"`));
  }
  assert.match(page, /id="appliedFilters" aria-live="polite"/);
  assert.match(page, /function filters\(\)[\s\S]*date_from[\s\S]*report_status[\s\S]*audit_status[\s\S]*source/);
  assert.match(page, /function updateApplied\(\)[\s\S]*byId\('appliedFilters'\)\.innerHTML/);
});

test('filter enums match the unified analytics query contract', () => {
  const reportFilter = page.match(/<select id="reportStatus">([\s\S]*?)<\/select>/)?.[1] || '';
  const auditFilter = page.match(/<select id="auditStatus">([\s\S]*?)<\/select>/)?.[1] || '';

  assert.match(reportFilter, /value="submitted"/);
  assert.match(reportFilter, /value="draft"/);
  assert.doesNotMatch(reportFilter, /value="(?:corrected|missing|locked_missing)"/);
  for (const status of ['missing', 'pending', 'approved', 'rejected', 'needs_resubmit', 'not_required']) {
    assert.match(auditFilter, new RegExp(`value="${status}"`));
  }
});

test('trend rows aggregate stores by date and derive staff completion from obligations', () => {
  assert.match(page, /function aggregateDailyCompletion\(rows\)/);
  assert.match(page, /trendSection\(aggregateDailyCompletion\(storeData\.daily_trend\|\|\[\]\)\)/);
  assert.match(page, /function staffTrendSection\(rows\)[\s\S]*required_obligation_days[\s\S]*submitted_report_count/);
});

test('five accessible views use the unified analytics endpoints', () => {
  for (const view of ['dashboard', 'store', 'metric', 'staff', 'matrix']) {
    assert.match(page, new RegExp(`role="tab"[^>]*data-view="${view}"`));
  }
  for (const endpoint of ['store-completion', 'metric-selection', 'staff-profile', 'cross-analysis', 'metric-detail']) {
    assert.match(page, new RegExp(`/api/workload/analytics/${endpoint}\\.php`));
  }
  assert.doesNotMatch(page, /\/api\/workload\/(?:dashboard|hq-summary|store-summary|staff-detail)\.php/);
  assert.match(page, /Promise\.all\(endpoints\.map/);
});

test('permission-aware defaults select and lock the available scope', () => {
  assert.match(page, /role==='manager'\)currentView='store'/);
  assert.match(page, /currentView='staff';byId\('staffId'\)\.value=String\(context\.staff_id/);
  assert.match(page, /byId\('storeId'\)\.disabled=true/);
  assert.match(page, /permission_scope[\s\S]*scope_type[\s\S]*ranking_scope/);
  assert.match(page, /role!==['"]manager['"][\s\S]*staffId['"]\)\.readOnly=true[\s\S]*role===['"]manager['"][\s\S]*staffId['"]\)\.readOnly=false/);
});

test('current-view export reuses analytics filters and standard export types', () => {
  assert.match(page, /\/api\/workload\/exports\.php/);
  for (const exportType of ['store_completion', 'metric_selection', 'staff_full_data', 'metric_full_dimension']) {
    assert.match(page, new RegExp(`${exportType}`));
  }
  assert.match(page, /JSON\.stringify\(Object\.assign\(\{export_type:exportType\},filters\(\)\)\)/);
  assert.match(page, /response\.status|response\.headers\.get\('content-type'\)/);
  assert.match(page, /URL\.createObjectURL\(blob\)/);
  assert.match(page, /downloadExportJob\(jobId,headers\)/);
  assert.match(page, /exports\.php\?id=['"]?\+encodeURIComponent\(jobId\)/);
  assert.match(page, /job\.download_ready[\s\S]*download=1/);
});

test('desktop tables and narrow-screen cards share row actions', () => {
  assert.match(page, /class="table-wrap"/);
  assert.match(page, /class="mobile-cards"/);
  assert.match(page, /data-action="store" data-index/);
  assert.match(page, /data-action="metric" data-index/);
  assert.match(page, /data-action="staff-record" data-index/);
  assert.match(page, /data-action="matrix" data-index/);
  assert.match(page, /@media\(max-width:760px\)[\s\S]*\.table-wrap\{display:none\}[\s\S]*\.mobile-cards\{display:grid\}/);
});
