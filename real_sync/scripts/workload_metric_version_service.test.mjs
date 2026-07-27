import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const read = (relativePath) => readFileSync(new URL(relativePath, import.meta.url), 'utf8');
const service = read('../api/workload/services/WorkloadMetricVersionService.php');
const migration = read('../database/migrations/202607240002_workload_governance.sql');
const saveReport = read('../api/workload/save-report.php');
const operatingEndpoints = [
  '../api/workload/dashboard.php',
  '../api/workload/hq-summary.php',
  '../api/workload/store-summary.php',
  '../api/workload/staff-activity.php',
  '../api/workload/staff-detail.php',
  '../api/admin/workload/summary.php',
  '../api/admin/dashboard/overview.php',
].map(read);

function activeVersion(versions, now) {
  return versions
    .filter(({ effectiveAt }) => effectiveAt <= now)
    .sort((left, right) => right.effectiveAt.localeCompare(left.effectiveAt) || right.id - left.id)[0] ?? null;
}

function canonical(value) {
  if (Array.isArray(value)) return value.map(canonical);
  if (!value || typeof value !== 'object') return value;
  return Object.fromEntries(Object.keys(value).sort().map((key) => [key, canonical(value[key])]));
}

test('[validates 9.1, 9.2] migration seeds one traceable initial metric version', () => {
  assert.match(migration, /INSERT INTO workload_metric_versions/);
  assert.match(migration, /'workload-v1'/);
  assert.match(migration, /'1970-01-01 00:00:00'/);
  assert.match(migration, /source_policy_json/);
  assert.match(migration, /obligation_policy_json/);
  assert.match(migration, /effective_value_policy_json/);
});

test('[validates 9.2] latest effective version wins with a deterministic id tie-breaker', () => {
  const versions = [
    { id: 1, code: 'v1', effectiveAt: '1970-01-01 00:00:00' },
    { id: 2, code: 'future', effectiveAt: '2027-01-01 00:00:00' },
    { id: 3, code: 'v2-a', effectiveAt: '2026-07-25 00:00:00' },
    { id: 4, code: 'v2-b', effectiveAt: '2026-07-25 00:00:00' },
  ];
  assert.equal(activeVersion(versions, '2026-07-25 12:00:00').code, 'v2-b');
  assert.equal(activeVersion(versions, '1969-12-31 23:59:59'), null);
  assert.match(service, /WHERE effective_at <= CURRENT_TIMESTAMP/);
  assert.match(service, /ORDER BY effective_at DESC, id DESC LIMIT 1/);
});

test('[validates 9.1, 9.5] response and export metadata share one metric definition', () => {
  assert.match(service, /function responseMetadata\(array \$filters = \[\], array \$sourceScope = \[\]\)/);
  assert.match(service, /'metric_version' => \$version\['version_code'\]/);
  assert.match(service, /'generated_at' => \$version\['generated_at'\]/);
  assert.match(service, /'source_scope' => array_values\(\$sourceScope\)/);
  assert.match(service, /'metric_policy' => \[/);
  assert.match(service, /function exportMetadata\(array \$filters, array \$sourceScope\)/);
  assert.match(service, /return \$this->responseMetadata\(\$filters, \$sourceScope\)/);
});

test('[validates 9.1, 9.3] cache keys isolate metric version, filters, and permission scope', () => {
  const first = canonical({ metric_version: 'v1', filters: { role: 'sales', date: '2026-07-25' }, permission_scope: { stores: [1] } });
  const reordered = canonical({ permission_scope: { stores: [1] }, filters: { date: '2026-07-25', role: 'sales' }, metric_version: 'v1' });
  assert.deepEqual(first, reordered);
  assert.match(service, /'metric_version' => \$this->current\(\)\['version_code'\]/);
  assert.match(service, /'filters' => \$this->sortRecursively\(\$filters\)/);
  assert.match(service, /'permission_scope' => \$this->sortRecursively\(\$permissionScope\)/);
  assert.match(service, /hash\(\s*'sha256'/);
});

test('[validates 9.1, 9.5] every operating statistic returns and audits the same version service', () => {
  for (const endpoint of operatingEndpoints) {
    assert.match(endpoint, /WorkloadMetricVersionService\.php/);
    assert.match(endpoint, /responseMetadata\(/);
  }
  for (const endpoint of operatingEndpoints.slice(0, 5)) {
    assert.match(endpoint, /auditContext\(\)/);
  }
  assert.match(service, /function auditContext\(\): array/);
});

test('[validates 9.1, 9.2] report writes bind and disclose the effective metric version', () => {
  assert.match(saveReport, /WorkloadMetricVersionService\.php/);
  assert.match(saveReport, /\$metricVersion = \$metricVersionService->current\(\)/);
  assert.match(saveReport, /SET template_id=\?, metric_version_id=\?/);
  assert.match(saveReport, /template_id, metric_version_id, rule_version_id, submit_status/);
  assert.match(saveReport, /'metric_version' => \$metricVersion\['version_code'\]/);
  assert.match(saveReport, /\$metricVersionService->auditContext\(\)/);
});
