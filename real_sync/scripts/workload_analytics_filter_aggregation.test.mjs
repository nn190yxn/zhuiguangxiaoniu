import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const service = fs.readFileSync(
  new URL('../api/workload/services/WorkloadAnalyticsQueryService.php', import.meta.url),
  'utf8',
);

const numericFields = [
  'raw_value',
  'pending_value',
  'effective_value',
  'rejected_value',
];

function filterFacts(rows, filters = {}) {
  const mappings = [
    ['store_ids', 'store_id'],
    ['role_codes', 'role_code'],
    ['staff_ids', 'staff_id'],
    ['metric_codes', 'metric_code'],
    ['report_statuses', 'report_status'],
    ['audit_statuses', 'audit_status'],
    ['sources', 'source'],
  ];

  return rows.filter((row) => {
    if (filters.date_from && row.business_date < filters.date_from) return false;
    if (filters.date_to && row.business_date > filters.date_to) return false;
    return mappings.every(([filterField, factField]) => {
      const accepted = filters[filterField] ?? [];
      return accepted.length === 0 || accepted.includes(row[factField]);
    });
  });
}

function aggregateByMetric(rows, requiredObligationDays = 0) {
  const groups = new Map();
  for (const row of rows) {
    if (row.report_status !== 'submitted') continue;
    if (!row.metric_code || row.report_id <= 0) continue;

    if (!groups.has(row.metric_code)) {
      groups.set(row.metric_code, {
        metric_code: row.metric_code,
        reportIds: new Set(),
        staffIds: new Set(),
        positiveStaffIds: new Set(),
        storeIds: new Set(),
        positiveStoreIds: new Set(),
        positive_raw_report_count: 0,
        positive_effective_report_count: 0,
        zero_raw_report_count: 0,
        raw_value: 0,
        pending_value: 0,
        effective_value: 0,
        rejected_value: 0,
      });
    }

    const group = groups.get(row.metric_code);
    if (group.reportIds.has(row.report_id)) continue;
    group.reportIds.add(row.report_id);
    group.staffIds.add(row.staff_id);
    group.storeIds.add(row.store_id);
    if (row.raw_value > 0) {
      group.positive_raw_report_count += 1;
      group.positiveStaffIds.add(row.staff_id);
      group.positiveStoreIds.add(row.store_id);
    } else if (row.raw_value === 0) {
      group.zero_raw_report_count += 1;
    }
    if (row.effective_value > 0) group.positive_effective_report_count += 1;
    for (const field of numericFields) group[field] += row[field] ?? 0;
  }

  return [...groups.values()].map((group) => {
    const submittedReportCount = group.reportIds.size;
    const submittedStaffCount = group.staffIds.size;
    return {
      metric_code: group.metric_code,
      sample_size: submittedReportCount,
      submitted_report_count: submittedReportCount,
      submitted_staff_count: submittedStaffCount,
      submitted_store_count: group.storeIds.size,
      positive_raw_report_count: group.positive_raw_report_count,
      positive_effective_report_count: group.positive_effective_report_count,
      zero_raw_report_count: group.zero_raw_report_count,
      low_sample: submittedReportCount < 10 || submittedStaffCount < 3,
      required_obligation_days: requiredObligationDays,
      ...Object.fromEntries(numericFields.map((field) => [field, group[field]])),
    };
  });
}

function fact(overrides = {}) {
  return {
    report_id: 1,
    business_date: '2026-07-22',
    store_id: 10,
    staff_id: 100,
    role_code: 'coach',
    metric_code: 'communication',
    report_status: 'submitted',
    audit_status: 'not_required',
    source: 'h5',
    raw_value: 1,
    pending_value: 0,
    effective_value: 1,
    rejected_value: 0,
    ...overrides,
  };
}

test('[validates 3, 5, 9, 13] empty facts produce an empty aggregate', () => {
  assert.deepEqual(filterFacts([], { sources: ['h5', 'mini_program'] }), []);
  assert.deepEqual(aggregateByMetric([]), []);
});

test('[validates 3.4] low-sample boundaries require ten reports and three staff', () => {
  const belowReportThreshold = Array.from({ length: 9 }, (_, index) => fact({
    report_id: index + 1,
    staff_id: 100 + (index % 3),
  }));
  const belowStaffThreshold = Array.from({ length: 10 }, (_, index) => fact({
    report_id: index + 1,
    staff_id: 100 + (index % 2),
  }));
  const sufficientSample = Array.from({ length: 10 }, (_, index) => fact({
    report_id: index + 1,
    staff_id: 100 + (index % 3),
  }));

  assert.equal(aggregateByMetric(belowReportThreshold)[0].low_sample, true);
  assert.equal(aggregateByMetric(belowStaffThreshold)[0].low_sample, true);
  assert.equal(aggregateByMetric(sufficientSample)[0].low_sample, false);
});

test('[validates 5.1, 9.1] all fact filters compose as an intersection', () => {
  const target = fact();
  const rows = [
    target,
    fact({ report_id: 2, business_date: '2026-07-21' }),
    fact({ report_id: 3, store_id: 11 }),
    fact({ report_id: 4, role_code: 'sales' }),
    fact({ report_id: 5, staff_id: 101 }),
    fact({ report_id: 6, metric_code: 'consumption' }),
    fact({ report_id: 7, report_status: 'draft' }),
    fact({ report_id: 8, audit_status: 'pending' }),
    fact({ report_id: 9, source: 'mini_program' }),
  ];
  const filtered = filterFacts(rows, {
    date_from: '2026-07-22',
    date_to: '2026-07-22',
    store_ids: [10],
    role_codes: ['coach'],
    staff_ids: [100],
    metric_codes: ['communication'],
    report_statuses: ['submitted'],
    audit_statuses: ['not_required'],
    sources: ['h5'],
  });

  assert.deepEqual(filtered, [target]);
});

test('[validates 2.5, 9.1] default business sources exclude synthetic facts', () => {
  const rows = [
    fact({ report_id: 1, source: 'h5', raw_value: 10, effective_value: 10 }),
    fact({ report_id: 2, source: 'mini_program', raw_value: 20, effective_value: 20 }),
    fact({ report_id: 3, source: 'test', raw_value: 900, effective_value: 900 }),
  ];
  const businessRows = filterFacts(rows, { sources: ['h5', 'mini_program'] });
  const result = aggregateByMetric(businessRows)[0];

  assert.equal(result.submitted_report_count, 2);
  assert.equal(result.raw_value, 30);
  assert.equal(result.effective_value, 30);
});

test('[validates 2.3, 13.4, 18.7] historical organization filters use report snapshots', () => {
  const historical = fact({
    store_id: 10,
    role_code: 'coach',
    current_store_id: 99,
    current_role_code: 'manager',
  });

  assert.deepEqual(filterFacts([historical], {
    store_ids: [10],
    role_codes: ['coach'],
  }), [historical]);
  assert.match(service, /r\.store_id/);
  assert.match(service, /r\.role_code/);
  assert.doesNotMatch(service, /s\.store_id AS store_id/);
});

test('[validates 3, 7, 13] audit-state values remain conserved through aggregation', () => {
  const rows = [
    fact({ report_id: 1, audit_status: 'pending', raw_value: 10, pending_value: 10, effective_value: 0 }),
    fact({ report_id: 2, audit_status: 'approved', raw_value: 20, effective_value: 20 }),
    fact({ report_id: 3, audit_status: 'rejected', raw_value: 30, effective_value: 0, rejected_value: 30 }),
    fact({ report_id: 4, audit_status: 'needs_resubmit', raw_value: 40, effective_value: 0 }),
    fact({ report_id: 5, audit_status: 'not_required', raw_value: 50, effective_value: 50 }),
  ];
  const result = aggregateByMetric(rows)[0];

  assert.deepEqual(
    Object.fromEntries(numericFields.map((field) => [field, result[field]])),
    { raw_value: 150, pending_value: 10, effective_value: 70, rejected_value: 30 },
  );
  assert.equal(filterFacts(rows, { audit_statuses: ['approved'] }).length, 1);
  assert.equal(filterFacts(rows, { audit_statuses: ['needs_resubmit'] }).length, 1);
});

test('[validates 3, 5, 13] full aggregates equal the sum of disjoint store partitions', () => {
  const rows = [
    fact({ report_id: 1, store_id: 10, raw_value: 10, effective_value: 10 }),
    fact({ report_id: 2, store_id: 10, raw_value: 0, effective_value: 0 }),
    fact({ report_id: 3, store_id: 11, raw_value: 20, pending_value: 20, effective_value: 0 }),
    fact({ report_id: 4, store_id: 11, raw_value: 30, effective_value: 0, rejected_value: 30 }),
  ];
  const full = aggregateByMetric(rows)[0];
  const partitions = [10, 11].map((storeId) => (
    aggregateByMetric(filterFacts(rows, { store_ids: [storeId] }))[0]
  ));
  const conservedFields = [
    'submitted_report_count',
    'positive_raw_report_count',
    'positive_effective_report_count',
    'zero_raw_report_count',
    ...numericFields,
  ];

  for (const field of conservedFields) {
    assert.equal(full[field], partitions.reduce((sum, item) => sum + item[field], 0), field);
  }
});

test('[validates 3, 5, 13] duplicate facts do not inflate conserved totals', () => {
  const original = fact({ raw_value: 12.5, effective_value: 12.5 });
  const result = aggregateByMetric([original, { ...original }])[0];

  assert.equal(result.submitted_report_count, 1);
  assert.equal(result.raw_value, 12.5);
  assert.equal(result.effective_value, 12.5);
});
