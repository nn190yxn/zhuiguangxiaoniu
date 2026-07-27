import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const paths = [
  '../api/workload/services/WorkloadComparisonService.php',
  '../api/workload/services/WorkloadStaffProfileService.php',
  '../api/workload/services/WorkloadMetricSelectionService.php',
];
const [service, staffService, metricService] = await Promise.all(
  paths.map((path) => readFile(new URL(path, import.meta.url), 'utf8')),
);
const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;

function compare(current, previous, currentSample = 10, previousSample = 10, lowSample = false) {
  const change = Number((current - previous).toFixed(2));
  return {
    current_value: current,
    previous_value: previous,
    change_value: change,
    change_rate: previous > 0 ? Number((change / previous).toFixed(4)) : null,
    comparison_state: previous > 0 ? (current === 0 ? 'down_to_zero' : 'comparable') : current > 0 ? 'new' : 'flat',
    current_sample_size: currentSample,
    previous_sample_size: previousSample,
    low_sample: lowSample,
  };
}

function benchmarks(values) {
  const sorted = [...values].sort((left, right) => right - left);
  const numerator = Number(values.reduce((sum, value) => sum + value, 0).toFixed(2));
  const index = Math.max(0, Math.ceil(values.length * 0.25) - 1);
  return {
    average: values.length ? Number((numerator / values.length).toFixed(2)) : 0,
    topQuartile: sorted[index] ?? 0,
  };
}

test(`${validatesCriteria(['14.5', '14.6'])} positive previous values return changes and rates`, () => {
  assert.deepEqual(compare(15, 10), {
    current_value: 15,
    previous_value: 10,
    change_value: 5,
    change_rate: 0.5,
    comparison_state: 'comparable',
    current_sample_size: 10,
    previous_sample_size: 10,
    low_sample: false,
  });
  assert.match(service, /round\(\$changeValue \/ \$previousValue, 4\)/);
});

test(`${validatesCriteria(['14.6'])} zero baselines return explicit new and flat states`, () => {
  assert.equal(compare(8, 0).comparison_state, 'new');
  assert.equal(compare(0, 0).comparison_state, 'flat');
  assert.equal(compare(8, 0).change_rate, null);
  assert.match(service, /\$currentValue > 0\.0 \? 'new' : 'flat'/);
});

test(`${validatesCriteria(['14.6'])} a positive baseline falling to zero is explainable`, () => {
  const result = compare(0, 12);
  assert.equal(result.comparison_state, 'down_to_zero');
  assert.equal(result.change_value, -12);
  assert.equal(result.change_rate, -1);
  assert.match(service, /\$currentValue === 0\.0 \? 'down_to_zero' : 'comparable'/);
});

test(`${validatesCriteria(['14.9'])} comparison preserves both sample sizes and low-sample state`, () => {
  const result = compare(9, 7, 9, 12, true);
  assert.equal(result.current_sample_size, 9);
  assert.equal(result.previous_sample_size, 12);
  assert.equal(result.low_sample, true);
  assert.match(service, /\$currentLowSample \|\| \$previousLowSample/);
});

test(`${validatesCriteria(['14.7', '14.8'])} averages and top-quartile references use the visible comparison set`, () => {
  const result = benchmarks([20, 10, 5, 0, 15]);
  assert.equal(result.average, 10);
  assert.equal(result.topQuartile, 15);
  assert.match(service, /public function benchmarks/);
  assert.match(service, /ceil\(count\(\$numericValues\) \* 0\.25\)/);
});

test(`${validatesCriteria(['14.5-14.9'])} staff comparisons and metric benchmarks reuse the common service`, () => {
  for (const source of [staffService, metricService]) {
    assert.match(source, /require_once __DIR__ \. '\/WorkloadComparisonService\.php'/);
    assert.match(source, /new WorkloadComparisonService\(\)/);
  }
  assert.match(staffService, /->compare\(/);
  assert.match(staffService, /->topQuartileReference\(/);
  assert.match(metricService, /->benchmarks\(/);
  assert.match(metricService, /->average\(/);
});
