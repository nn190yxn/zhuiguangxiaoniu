import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const service = fs.readFileSync(
  new URL('../api/workload/services/WorkloadAnalyticsQueryService.php', import.meta.url),
  'utf8',
);
const metricVersionService = fs.readFileSync(
  new URL('../api/workload/services/WorkloadMetricVersionService.php', import.meta.url),
  'utf8',
);

test('[validates 3.1-3.7] analytics core aggregates submitted facts by metric', () => {
  assert.match(service, /function aggregateByMetric\(array \$rows, int \$requiredObligationDays = 0\): array/);
  assert.match(service, /\$row\['report_status'\].*'submitted'/s);
  assert.match(service, /\$groups\[\$metricCode\]/);
  assert.match(service, /'sample_size'/);
  assert.match(service, /'submitted_report_count'/);
  assert.match(service, /'positive_raw_report_count'/);
  assert.match(service, /'positive_effective_report_count'/);
  assert.match(service, /'zero_raw_report_count'/);
});

test('[validates 3.1-3.3] every ratio exposes numerator, denominator, and value', () => {
  for (const field of [
    'selection_rate',
    'effective_selection_rate',
    'zero_rate',
    'staff_coverage',
    'store_coverage',
  ]) {
    assert.match(service, new RegExp(`'${field}'`));
  }
  assert.match(service, /function ratio\(int \$numerator, int \$denominator\): array/);
  assert.match(service, /'numerator' => \$numerator/);
  assert.match(service, /'denominator' => \$denominator/);
  assert.match(service, /'value' => \$denominator > 0/);
});

test('[validates 3.4, 3.6] aggregates low-sample state, totals, and auditable averages', () => {
  assert.match(service, /MINIMUM_SUBMITTED_REPORTS = 10/);
  assert.match(service, /MINIMUM_SUBMITTED_STAFF = 3/);
  assert.match(service, /'low_sample'/);
  assert.match(service, /'raw_value'/);
  assert.match(service, /'pending_value'/);
  assert.match(service, /'effective_value'/);
  assert.match(service, /'rejected_value'/);
  assert.match(service, /'all_staff_average'/);
  assert.match(service, /'participant_staff_average'/);
  assert.match(service, /'per_obligation_day_average'/);
  assert.match(service, /function average\(float \$numerator, int \$denominator\): array/);
});

test('[validates 9.5] statistics expose data cutoff and one metric-version metadata contract', () => {
  assert.match(service, /WorkloadMetricVersionService\.php/);
  assert.match(service, /new WorkloadMetricVersionService\(\$this->pdo\)/);
  assert.match(service, /function statistics\(array \$input, array \$context, int \$requiredObligationDays = 0\): array/);
  assert.match(service, /responseMetadata\(\$facts\['filters'\], \$facts\['filters'\]\['sources'\]\)/);
  assert.match(service, /'data_cutoff_at' => \$metadata\['generated_at'\]/);
  assert.match(metricVersionService, /'metric_version' => \$version\['version_code'\]/);
  assert.match(metricVersionService, /'metric_version_id' => \$version\['id'\]/);
});
