import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const read = (relativePath) => readFileSync(new URL(relativePath, import.meta.url), 'utf8');
const service = read('../api/workload/services/WorkloadSourcePolicyService.php');
const migration = read('../database/migrations/202607240002_workload_governance.sql');
const saveReport = read('../api/workload/save-report.php');
const dashboard = read('../api/workload/dashboard.php');
const hqSummary = read('../api/workload/hq-summary.php');
const storeSummary = read('../api/workload/store-summary.php');
const staffActivity = read('../api/workload/staff-activity.php');
const staffDetail = read('../api/workload/staff-detail.php');
const adminSummary = read('../api/admin/workload/summary.php');
const adminOverview = read('../api/admin/dashboard/overview.php');

const initialPolicies = new Map([
  ['h5', { kind: 'production', included: true }],
  ['mini_program', { kind: 'production', included: true }],
  ['codex-smoke', { kind: 'synthetic', included: false }],
  ['debug', { kind: 'synthetic', included: false }],
  ['h5-e2e', { kind: 'synthetic', included: false }],
  ['live_check', { kind: 'synthetic', included: false }],
  ['test', { kind: 'synthetic', included: false }],
]);

function defaultOperatingReports(reports) {
  return reports.filter(({ source }) => initialPolicies.get(source)?.included === true);
}

test('[validates 2.5, 9.2] initial source policies classify production and synthetic data', () => {
  for (const [source, policy] of initialPolicies) {
    const included = policy.included ? 1 : 0;
    assert.match(migration, new RegExp(`\\('${source.replace('-', '\\-')}', '${policy.kind}', ${included},`));
  }
});

test('[validates 2.5, 9.2] default operating reports include only configured production sources', () => {
  const reports = [...initialPolicies.keys(), 'unknown'].map((source, index) => ({ id: index + 1, source }));
  assert.deepEqual(defaultOperatingReports(reports).map(({ source }) => source), ['h5', 'mini_program']);
});

test('[validates 2.5, 9.2] source service validates registrations and exposes one reusable SQL policy', () => {
  assert.match(service, /FROM workload_source_policies WHERE source_code = \? LIMIT 1/);
  assert.match(service, /日报来源未登记/);
  assert.match(service, /\['production', 'synthetic'\]/);
  assert.match(service, /WHERE included_by_default = 1 ORDER BY source_code ASC/);
  assert.match(service, /source_policy\.source_code = ' \. \$reportAlias \. '\.source/);
  assert.match(service, /source_policy\.included_by_default = 1/);
  assert.match(service, /preg_match\('\/\^\[a-zA-Z_\]/);
});

test('[validates 2.5, 9.2] report saves accept only a registered source policy', () => {
  assert.match(saveReport, /WorkloadSourcePolicyService\.php/);
  assert.match(saveReport, /\(new WorkloadSourcePolicyService\(\$pdo\)\)->policy\(\$source\)/);
  assert.match(saveReport, /\$source = \$sourcePolicy\['source_code'\]/);
  assert.match(saveReport, /catch \(WorkloadSourcePolicyException \$e\)/);
});

test('[validates 2.5, 9.2] every workload operating view applies the default source policy', () => {
  for (const endpoint of [dashboard, hqSummary, storeSummary, staffActivity, staffDetail]) {
    assert.match(endpoint, /WorkloadSourcePolicyService\.php/);
    assert.match(endpoint, /includedByDefaultCondition\('r'\)/);
    assert.match(endpoint, /included_by_default/);
  }
  assert.match(dashboard, /JOIN workload_daily_reports r ON r\.id = t\.report_id/);
});

test('[validates 2.5, 9.2] admin summaries apply and disclose the same included sources', () => {
  for (const endpoint of [adminSummary, adminOverview]) {
    assert.match(endpoint, /WorkloadSourcePolicyService\.php/);
    assert.match(endpoint, /includedByDefaultCondition\('r'\)/);
    assert.match(endpoint, /includedSources/);
  }
});
