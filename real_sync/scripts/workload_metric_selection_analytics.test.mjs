import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const service = readFileSync(
  new URL('../api/workload/services/WorkloadMetricSelectionService.php', import.meta.url),
  'utf8',
);
const endpoint = readFileSync(
  new URL('../api/workload/analytics/metric-selection.php', import.meta.url),
  'utf8',
);

const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;

function denseCompetitionRanks(rows, groupFields, valueField) {
  const groups = new Map();
  rows.forEach((row, index) => {
    const key = groupFields.map((field) => row[field]).join(':');
    const indexes = groups.get(key) ?? [];
    indexes.push(index);
    groups.set(key, indexes);
  });
  const ranks = new Map();
  for (const indexes of groups.values()) {
    indexes.sort((left, right) => rows[right][valueField] - rows[left][valueField] || left - right);
    let previous;
    let rank = 0;
    indexes.forEach((index, position) => {
      if (previous === undefined || rows[index][valueField] !== previous) {
        rank = position + 1;
        previous = rows[index][valueField];
      }
      ranks.set(rows[index].id, rank);
    });
  }
  return ranks;
}

function ratio(numerator, denominator) {
  return { numerator, denominator, value: denominator > 0 ? numerator / denominator : 0 };
}

test(`${validatesCriteria(['3.1-3.7'])} endpoint exposes authenticated metric selection analytics`, () => {
  assert.match(endpoint, /REQUEST_METHOD[\s\S]*'GET'/);
  assert.match(endpoint, /appRequireStaffContext\(\)/);
  assert.match(endpoint, /new WorkloadMetricSelectionService\(\$pdo\)/);
  assert.match(endpoint, /->metricSelection\(\$_GET, \$context\)/);
  assert.match(endpoint, /workload\.analytics\.metric_selection/);
  assert.match(endpoint, /catch \(WorkloadAnalyticsQueryException \| WorkloadSourcePolicyException/);
});

test(`${validatesCriteria(['3.1-3.6'])} project summaries reuse the canonical aggregate contract`, () => {
  assert.match(service, /new WorkloadAnalyticsQueryService\(\$pdo\)/);
  assert.match(service, /->facts\(\$input, \$context\)/);
  assert.match(service, /->aggregateByMetric\(\$facts, \$requiredCount\)/);
  for (const field of [
    'selection_rate',
    'effective_selection_rate',
    'zero_rate',
    'staff_coverage',
    'store_coverage',
    'raw_value',
    'pending_value',
    'effective_value',
    'all_staff_average',
    'participant_staff_average',
    'per_obligation_day_average',
    'low_sample',
  ]) {
    assert.match(service, new RegExp(`'${field}'`));
  }
  assert.deepEqual(ratio(3, 4), { numerator: 3, denominator: 4, value: 0.75 });
});

test(`${validatesCriteria(['3.1-3.7'])} applicable projects and obligated staff receive zero-value rows`, () => {
  assert.match(service, /metricCatalog\(\$filters, \$dimensions\['roles'\]\)/);
  assert.match(service, /foreach \(\$catalog as \$metric\)/);
  assert.match(service, /foreach \(\$dimensions\['staff'\] as \$staffKey => \$staff\)/);
  assert.match(service, /emptyAggregate\(\$metric, \$requiredCount\)/);
  assert.match(service, /'required_obligation_days' => \$requiredCount/);
  assert.match(service, /'sample_size' => 0/);
  assert.match(service, /'has_pending_review'/);
});

test(`${validatesCriteria(['3.7', '14.7'])} store rankings expose effective, raw, coverage, average, and top-quartile benchmarks`, () => {
  const rows = [
    { id: 'a', role: 'sales', metric: 'calls', effective: 8 },
    { id: 'b', role: 'sales', metric: 'calls', effective: 8 },
    { id: 'c', role: 'sales', metric: 'calls', effective: 5 },
    { id: 'd', role: 'coach', metric: 'lessons', effective: 20 },
  ];
  const ranks = denseCompetitionRanks(rows, ['role', 'metric'], 'effective');
  assert.equal(ranks.get('a'), 1);
  assert.equal(ranks.get('b'), 1);
  assert.equal(ranks.get('c'), 3);
  assert.equal(ranks.get('d'), 1);
  for (const field of [
    'effective_value_rank',
    'raw_value_rank',
    'staff_coverage_rank',
    'all_store_effective_average',
    'all_store_raw_average',
    'top_quartile_effective_reference',
  ]) {
    assert.match(service, new RegExp(`'${field}'`));
  }
});

test(`${validatesCriteria(['3.7', '14.8', '14.9'])} staff rankings are isolated by store and by visible same-role scope`, () => {
  const rows = [
    { id: 's1', store: 1, role: 'sales', metric: 'calls', effective: 10 },
    { id: 's2', store: 1, role: 'sales', metric: 'calls', effective: 8 },
    { id: 's3', store: 2, role: 'sales', metric: 'calls', effective: 9 },
    { id: 'c1', store: 1, role: 'coach', metric: 'calls', effective: 30 },
  ];
  const storeRanks = denseCompetitionRanks(rows, ['store', 'role', 'metric'], 'effective');
  const allStoreRoleRanks = denseCompetitionRanks(rows, ['role', 'metric'], 'effective');
  assert.equal(storeRanks.get('s1'), 1);
  assert.equal(storeRanks.get('s2'), 2);
  assert.equal(storeRanks.get('s3'), 1);
  assert.equal(allStoreRoleRanks.get('s1'), 1);
  assert.equal(allStoreRoleRanks.get('s3'), 2);
  assert.equal(allStoreRoleRanks.get('s2'), 3);
  assert.equal(allStoreRoleRanks.get('c1'), 1);
  for (const field of [
    'store_effective_value_rank',
    'store_raw_value_rank',
    'all_store_role_effective_value_rank',
    'all_store_role_raw_value_rank',
    'store_role_effective_average',
    'all_store_role_effective_average',
  ]) {
    assert.match(service, new RegExp(`'${field}'`));
  }
});
