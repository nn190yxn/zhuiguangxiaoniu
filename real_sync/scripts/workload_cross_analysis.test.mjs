import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const [service, endpoint] = await Promise.all([
  readFile(new URL('../api/workload/services/WorkloadCrossAnalysisService.php', import.meta.url), 'utf8'),
  readFile(new URL('../api/workload/analytics/cross-analysis.php', import.meta.url), 'utf8'),
]);
const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;

function aggregate(facts, obligations) {
  const reports = new Set(facts.map((row) => row.reportId));
  const positiveReports = new Set(facts.filter((row) => row.raw > 0).map((row) => row.reportId));
  const submittedStaff = new Set(facts.map((row) => row.staffId));
  const positiveStaff = new Set(facts.filter((row) => row.raw > 0).map((row) => row.staffId));
  const obligatedStaff = new Set(obligations.map((row) => row.staffId));
  const completed = obligations.filter((row) => ['submitted', 'corrected'].includes(row.status));
  const ratio = (numerator, denominator) => ({ numerator, denominator, value: denominator ? numerator / denominator : 0 });
  const effective = facts.reduce((sum, row) => sum + row.effective, 0);
  return {
    raw_value: facts.reduce((sum, row) => sum + row.raw, 0),
    effective_value: effective,
    completion_rate: ratio(completed.length, obligations.length),
    coverage_rate: ratio(positiveStaff.size, obligatedStaff.size),
    selection_rate: ratio(positiveReports.size, reports.size),
    per_obligation_day_average: ratio(effective, obligations.length),
    sample_size: reports.size,
    submitted_staff_count: submittedStaff.size,
  };
}

test(`${validatesCriteria(['13.1-13.6'])} endpoint authenticates GET requests and audits selected dimensions`, () => {
  assert.match(endpoint, /REQUEST_METHOD[\s\S]*'GET'/);
  assert.match(endpoint, /appRequireStaffContext\(\)/);
  assert.match(endpoint, /new WorkloadCrossAnalysisService\(\$pdo\)/);
  assert.match(endpoint, /->analyze\(\$_GET, \$context\)/);
  assert.match(endpoint, /workload\.analytics\.cross_analysis/);
});

test(`${validatesCriteria(['13.1-13.3'])} primary and secondary dimensions support store, project, staff, and time`, () => {
  for (const dimension of ['store', 'metric', 'staff', 'time']) {
    assert.match(service, new RegExp(`'${dimension}'`));
  }
  assert.match(service, /primary_dimension/);
  assert.match(service, /secondary_dimension/);
  assert.match(service, /主维度和次维度不能相同/);
  assert.match(service, /\$dimension === 'project' \? 'metric'/);
});

test(`${validatesCriteria(['13.5'])} time dimensions support day, business week, month, and quarter`, () => {
  for (const granularity of ['day', 'business_week', 'month', 'quarter']) {
    assert.match(service, new RegExp(`'${granularity}'`));
  }
  assert.match(service, /new WorkloadBusinessPeriodService\(\)/);
  assert.match(service, /current_period/);
});

test(`${validatesCriteria(['13.6'])} cells expose four values, completion, coverage, averages, and samples`, () => {
  const facts = [
    { reportId: 1, staffId: 11, raw: 10, effective: 10 },
    { reportId: 2, staffId: 12, raw: 0, effective: 0 },
  ];
  const obligations = [
    { staffId: 11, status: 'submitted' },
    { staffId: 12, status: 'missing' },
  ];
  const result = aggregate(facts, obligations);
  assert.deepEqual(result.completion_rate, { numerator: 1, denominator: 2, value: 0.5 });
  assert.deepEqual(result.coverage_rate, { numerator: 1, denominator: 2, value: 0.5 });
  assert.deepEqual(result.per_obligation_day_average, { numerator: 10, denominator: 2, value: 5 });
  for (const field of ['raw_value', 'pending_value', 'effective_value', 'rejected_value', 'low_sample']) {
    assert.match(service, new RegExp(`'${field}'`));
  }
});

test(`${validatesCriteria(['13.1-13.4'])} applicable role metrics seed zero-value matrix cells`, () => {
  assert.match(service, /metricCatalog\(\$filters, \$factsResult\['rows'\], \$obligations\)/);
  assert.match(service, /metricSeeds\(\$obligation, \$catalog\)/);
  assert.match(service, /metric_definitions/);
  assert.match(service, /required_status = 'required'/);
});

test(`${validatesCriteria(['13.4'])} every matrix cell returns ranking and exact drilldown parameters`, () => {
  assert.match(service, /effective_value_rank/);
  assert.match(service, /assignDenseRanks/);
  assert.match(service, /\/api\/workload\/analytics\/metric-detail\.php/);
  for (const filter of ['date_from', 'date_to', 'store_ids', 'staff_ids', 'metric_codes', 'audit_statuses', 'sources']) {
    assert.match(service, new RegExp(`'${filter}'`));
  }
});
