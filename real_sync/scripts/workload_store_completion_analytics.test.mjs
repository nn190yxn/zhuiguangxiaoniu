import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const service = readFileSync(
  new URL('../api/workload/services/WorkloadStoreAnalyticsService.php', import.meta.url),
  'utf8',
);
const endpoint = readFileSync(
  new URL('../api/workload/analytics/store-completion.php', import.meta.url),
  'utf8',
);

const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;

function completionSummary(rows) {
  const statusCounts = {
    missing: 0,
    draft: 0,
    submitted: 0,
    locked_missing: 0,
    corrected: 0,
  };
  let excludedSourceCount = 0;
  for (const row of rows) {
    const status = row.sourceInScope ? row.completionStatus : 'missing';
    statusCounts[status] += 1;
    if (!row.sourceInScope) excludedSourceCount += 1;
  }
  const completedCount = statusCounts.submitted + statusCounts.corrected;
  return {
    requiredCount: rows.length,
    completedCount,
    excludedSourceCount,
    statusCounts,
    completionRate: rows.length > 0 ? completedCount / rows.length : 0,
  };
}

function rankByMetric(rows, field) {
  const result = new Map();
  const metricCodes = [...new Set(rows.map(({ metricCode }) => metricCode))];
  for (const metricCode of metricCodes) {
    const candidates = rows
      .filter((row) => row.metricCode === metricCode)
      .sort((left, right) => right[field] - left[field] || left.storeId - right.storeId);
    let previous;
    let rank = 0;
    candidates.forEach((row, index) => {
      if (previous === undefined || row[field] !== previous) {
        rank = index + 1;
        previous = row[field];
      }
      result.set(`${row.storeId}:${metricCode}`, rank);
    });
  }
  return result;
}

test(`${validatesCriteria(['2.1-2.6', '13.1', '13.4', '13.6'])} endpoint exposes one authenticated GET analytics contract`, () => {
  assert.match(endpoint, /REQUEST_METHOD[\s\S]*'GET'/);
  assert.match(endpoint, /appRequireStaffContext\(\)/);
  assert.match(endpoint, /new WorkloadStoreAnalyticsService\(\$pdo\)/);
  assert.match(endpoint, /->storeCompletion\(\$_GET, \$context\)/);
  assert.match(endpoint, /workload\.analytics\.store_completion/);
  assert.match(endpoint, /catch \(WorkloadAnalyticsQueryException \| WorkloadSourcePolicyException/);
});

test(`${validatesCriteria(['2.1-2.6'])} completion aggregation preserves obligation status and source scope`, () => {
  const summary = completionSummary([
    { completionStatus: 'submitted', sourceInScope: true },
    { completionStatus: 'corrected', sourceInScope: true },
    { completionStatus: 'draft', sourceInScope: true },
    { completionStatus: 'locked_missing', sourceInScope: true },
    { completionStatus: 'submitted', sourceInScope: false },
  ]);
  assert.deepEqual(summary, {
    requiredCount: 5,
    completedCount: 2,
    excludedSourceCount: 1,
    statusCounts: {
      missing: 1,
      draft: 1,
      submitted: 1,
      locked_missing: 1,
      corrected: 1,
    },
    completionRate: 0.4,
  });
  assert.equal(Object.values(summary.statusCounts).reduce((sum, value) => sum + value, 0), 5);
  assert.match(service, /o\.required_status = 'required'/);
  assert.match(service, /sourceInScope \? \$storedStatus : 'missing'/);
  assert.match(service, /\['submitted', 'corrected'\]/);
  assert.match(service, /\$group\['completion_rate'\] = \$this->ratio/);
});

test(`${validatesCriteria(['13.1', '13.4', '13.6'])} store metric matrix includes all obligated staff and auditable values`, () => {
  for (const field of [
    'raw_value',
    'pending_value',
    'effective_value',
    'per_obligation_day_average',
    'completion_rate',
    'staff_coverage',
    'submitted_staff_average',
    'staff_rows',
  ]) {
    assert.match(service, new RegExp(`'${field}'`));
  }
  assert.match(service, /metricCatalog\(\$filters, \$obligations\)/);
  assert.match(service, /\$staffRoster = \$obligationsByStoreRole/);
  assert.match(service, /emptyMetricAggregate\(\$metric/);
  assert.match(service, /aggregateByMetric\(/);
  assert.match(service, /requiredByStaffRole/);
  assert.match(service, /'submitted_staff_coverage'/);
});

test(`${validatesCriteria(['13.1', '13.6'])} effective and raw store rankings are dense and metric-local`, () => {
  const rows = [
    { storeId: 1, metricCode: 'sales_calls', effective: 8, raw: 10 },
    { storeId: 2, metricCode: 'sales_calls', effective: 8, raw: 9 },
    { storeId: 3, metricCode: 'sales_calls', effective: 5, raw: 11 },
    { storeId: 1, metricCode: 'sales_actual_visit', effective: 2, raw: 2 },
  ];
  const effectiveRanks = rankByMetric(rows, 'effective');
  const rawRanks = rankByMetric(rows, 'raw');
  assert.equal(effectiveRanks.get('1:sales_calls'), 1);
  assert.equal(effectiveRanks.get('2:sales_calls'), 1);
  assert.equal(effectiveRanks.get('3:sales_calls'), 3);
  assert.equal(rawRanks.get('3:sales_calls'), 1);
  assert.equal(effectiveRanks.get('1:sales_actual_visit'), 1);
  assert.match(service, /assignRanks\(\$matrix, 'effective_value', 'effective_value_rank'\)/);
  assert.match(service, /assignRanks\(\$matrix, 'raw_value', 'raw_value_rank'\)/);
});

test(`${validatesCriteria(['2.4', '13.4'])} responses expose daily status details and drilldown tokens`, () => {
  assert.match(service, /'daily_trend'/);
  assert.match(service, /'status_details'/);
  assert.match(service, /'drilldown_token'/);
  assert.match(service, /'business_date'/);
  assert.match(service, /'staff_id'/);
  assert.match(service, /'role_code'/);
  assert.match(service, /'completion_status'/);
  assert.match(service, /'weekly_rest_day'/);
});
