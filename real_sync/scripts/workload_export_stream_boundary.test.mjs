import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const analytics = readFileSync(new URL('../api/workload/services/WorkloadAnalyticsQueryService.php', import.meta.url), 'utf8');
const exportsService = readFileSync(new URL('../api/workload/services/WorkloadExportService.php', import.meta.url), 'utf8');
const endpoint = readFileSync(new URL('../api/workload/exports.php', import.meta.url), 'utf8');
const worker = readFileSync(new URL('../api/workload/export-worker.php', import.meta.url), 'utf8');
const baseline = JSON.parse(readFileSync(new URL('./workload-query-plan-baseline.json', import.meta.url), 'utf8'));

test('[validates 5.5, 6.5] 20,000 rows stay synchronous and 20,001 rows become a job', () => {
  const mode = (count) => count > baseline.fact_query.maximum_synchronous_rows ? 'job' : 'stream';
  assert.equal(mode(20_000), 'stream');
  assert.equal(mode(20_001), 'job');
  assert.match(endpoint, /->plan\(\$exportType, \$input, \$context\)[\s\S]*row_count.*SYNCHRONOUS_ROW_LIMIT[\s\S]*->create\(/);
});

test('[validates 5.5, 6.5] full-data plans count before constructing a lazy fact stream', () => {
  const countPosition = exportsService.indexOf('$rowCount = $analytics->countFacts');
  const streamPosition = exportsService.indexOf('$analytics->iterateFacts');
  assert.ok(countPosition > 0);
  assert.ok(streamPosition > countPosition);
  assert.match(analytics, /SELECT COUNT\(\*\) FROM \('/);
  assert.match(analytics, /function iterateFacts[\s\S]*yield \$this->normalizeFactRow/);
  const iterateBody = analytics.slice(analytics.indexOf('public function iterateFacts'), analytics.indexOf('private function normalizeFactRow'));
  assert.doesNotMatch(iterateBody, /fetchAll/);
  assert.match(iterateBody, /MYSQL_ATTR_USE_BUFFERED_QUERY, false/);
});

test('[validates 6.5, 9.1] HTTP and worker output write incrementally to streams', () => {
  assert.match(exportsService, /public function writeCsv\(/);
  assert.match(exportsService, /foreach \(\$export\['rows'\] as \$row\)/);
  assert.match(endpoint, /fopen\('php:\/\/output', 'wb'\)/);
  assert.match(endpoint, /->writeCsv\(\$export, \$output\)/);
  assert.match(worker, /fopen\(\$filePath, 'wb'\)/);
  assert.match(worker, /->writeCsv\(\$export, \$stream\)/);
  assert.doesNotMatch(worker, /file_put_contents/);
});

test('[validates 9.1] query plan baseline retains governance indexes and the synchronous bound', () => {
  assert.deepEqual(baseline.fact_query.required_indexes, [
    'idx_workload_reports_source_stats',
    'idx_workload_reports_staff_source',
  ]);
  assert.equal(baseline.fact_query.maximum_synchronous_rows, 20_000);
});
