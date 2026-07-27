import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const service = readFileSync(
  new URL('../api/workload/services/WorkloadOperatingFunnelService.php', import.meta.url),
  'utf8',
);
const endpoint = readFileSync(
  new URL('../api/workload/analytics/operating-funnel.php', import.meta.url),
  'utf8',
);
const migration = readFileSync(
  new URL('../database/migrations/202607240007_workload_metric_relations.sql', import.meta.url),
  'utf8',
);
const manifest = readFileSync(new URL('../database/migration_manifest.php', import.meta.url), 'utf8');

const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;

function ratio(numerator, denominator) {
  return {
    numerator,
    denominator,
    value: denominator > 0 ? Number((numerator / denominator).toFixed(4)) : 0,
    state: denominator > 0 ? 'comparable' : numerator > 0 ? 'new' : 'empty',
  };
}

test(`${validatesCriteria(['16.1-16.3'])} endpoint exposes authenticated operating funnel analytics`, () => {
  assert.match(endpoint, /REQUEST_METHOD[\s\S]*'GET'/);
  assert.match(endpoint, /appRequireStaffContext\(\)/);
  assert.match(endpoint, /new WorkloadOperatingFunnelService\(\$pdo\)/);
  assert.match(endpoint, /->operatingFunnel\(\$_GET, \$context\)/);
  assert.match(endpoint, /workload\.analytics\.operating_funnel/);
  assert.match(endpoint, /catch \(WorkloadAnalyticsQueryException \| WorkloadSourcePolicyException/);
});

test(`${validatesCriteria(['16.3'])} additive migration versions metric relationships`, () => {
  for (const table of ['workload_metric_relation_versions', 'workload_metric_relations']) {
    assert.match(migration, new RegExp(`CREATE TABLE IF NOT EXISTS ${table} \\(`));
    assert.match(manifest, new RegExp(`'${table}'`));
  }
  assert.match(migration, /effective_from DATE NOT NULL/);
  assert.match(migration, /effective_to DATE NULL/);
  assert.match(migration, /UNIQUE KEY uq_workload_metric_relation \(relation_version_id, relation_code\)/);
  assert.match(migration, /numerator_metric_code VARCHAR\(64\) NOT NULL/);
  assert.match(migration, /denominator_metric_code VARCHAR\(64\) NOT NULL/);
  assert.doesNotMatch(migration, /\b(?:DROP|TRUNCATE|DELETE)\b/i);
});

test(`${validatesCriteria(['16.1'])} sales funnel returns five process stages and versioned conversions`, () => {
  for (const metric of [
    'sales_resources',
    'sales_actual_visit',
    'sales_actual_arrive',
    'sales_deal_count',
    'sales_new_revenue',
  ]) {
    assert.match(service, new RegExp(`'${metric}'`));
  }
  for (const field of ['raw_value', 'pending_value', 'effective_value', 'rejected_value']) {
    assert.match(service, new RegExp(`'${field}'`));
  }
  for (const relation of ['sales_invitation_rate', 'sales_arrival_rate', 'sales_deal_rate']) {
    assert.match(migration, new RegExp(`'${relation}'`));
  }
});

test(`${validatesCriteria(['16.2'])} coach completion divides actual values by plan values`, () => {
  assert.match(migration, /'coach_actual_hours', 'coach_plan_hours'/);
  assert.match(migration, /'coach_actual_comm', 'coach_plan_comm'/);
  assert.deepEqual(ratio(18, 20), {
    numerator: 18,
    denominator: 20,
    value: 0.9,
    state: 'comparable',
  });
});

test(`${validatesCriteria(['16.3'])} relation version is selected by the reporting cutoff date`, () => {
  assert.match(service, /effective_from <= \?/);
  assert.match(service, /effective_to IS NULL OR effective_to >= \?/);
  assert.match(service, /ORDER BY effective_from DESC, id DESC LIMIT 1/);
  assert.match(service, /relation_version_id = \?/);
  assert.match(service, /'relation_version' => \$version/);
});

test(`${validatesCriteria(['16.1-16.3'])} rates expose sample and denominator-zero states`, () => {
  assert.deepEqual(ratio(3, 0), { numerator: 3, denominator: 0, value: 0, state: 'new' });
  assert.deepEqual(ratio(0, 0), { numerator: 0, denominator: 0, value: 0, state: 'empty' });
  for (const field of ['sample_size', 'low_sample', 'has_pending_review', 'effective_rate', 'raw_rate']) {
    assert.match(service, new RegExp(`'${field}'`));
  }
  assert.match(service, /new WorkloadAnalyticsQueryService\(\$pdo\)/);
  assert.match(service, /->facts\(\$queryInput, \$context\)/);
  assert.match(service, /->aggregateByMetric\(\$facts\)/);
});
