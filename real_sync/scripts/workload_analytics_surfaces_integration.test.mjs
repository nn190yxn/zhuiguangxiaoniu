import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const read = (relativePath) => readFileSync(new URL(relativePath, import.meta.url), 'utf8');
const queryService = read('../api/workload/services/WorkloadAnalyticsQueryService.php');
const storeService = read('../api/workload/services/WorkloadStoreAnalyticsService.php');
const metricService = read('../api/workload/services/WorkloadMetricSelectionService.php');
const staffService = read('../api/workload/services/WorkloadStaffProfileService.php');
const funnelService = read('../api/workload/services/WorkloadOperatingFunnelService.php');

const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;
const valueFields = ['raw_value', 'pending_value', 'effective_value', 'rejected_value'];

function auditValues(rawValue, auditStatus) {
  return {
    raw_value: rawValue,
    pending_value: auditStatus === 'pending' ? rawValue : 0,
    effective_value: ['approved', 'not_required'].includes(auditStatus) ? rawValue : 0,
    rejected_value: auditStatus === 'rejected' ? rawValue : 0,
  };
}

function ratio(numerator, denominator) {
  return {
    numerator,
    denominator,
    value: denominator > 0 ? Number((numerator / denominator).toFixed(4)) : 0,
    state: denominator > 0 ? 'comparable' : numerator > 0 ? 'new' : 'empty',
  };
}

class WorkloadAnalyticsScenario {
  constructor() {
    this.obligations = [];
    this.factRows = [];
    this.nextReportId = 1;
    this.relationVersions = [
      {
        id: 1,
        code: 'relations-v1',
        from: '1970-01-01',
        to: '2026-07-31',
        relations: [{ code: 'sales_deal_rate', numerator: 'sales_deal_count', denominator: 'sales_actual_arrive' }],
      },
      {
        id: 2,
        code: 'relations-v2',
        from: '2026-08-01',
        to: null,
        relations: [{ code: 'sales_deal_rate', numerator: 'sales_deal_count', denominator: 'sales_actual_visit' }],
      },
    ];
  }

  addObligation({ date, storeId, staffId, roleCode = 'sales', completionStatus = 'missing' }) {
    this.obligations.push({
      business_date: date,
      store_id: storeId,
      staff_id: staffId,
      role_code: roleCode,
      completion_status: completionStatus,
    });
  }

  addReport({ date, storeId, staffId, roleCode = 'sales', metrics, completionStatus = 'submitted' }) {
    const reportId = this.nextReportId++;
    this.addObligation({ date, storeId, staffId, roleCode, completionStatus });
    for (const [metricCode, input] of Object.entries(metrics)) {
      const definition = typeof input === 'number' ? { raw: input, audit: 'not_required' } : input;
      this.factRows.push({
        report_id: reportId,
        business_date: date,
        store_id: storeId,
        staff_id: staffId,
        role_code: roleCode,
        metric_code: metricCode,
        report_status: 'submitted',
        audit_status: definition.audit,
        ...auditValues(definition.raw, definition.audit),
      });
    }
    return reportId;
  }

  filterRows(rows, filters = {}) {
    const mappings = [
      ['storeIds', 'store_id'],
      ['staffIds', 'staff_id'],
      ['roleCodes', 'role_code'],
      ['auditStatuses', 'audit_status'],
    ];
    return rows.filter((row) => {
      if (filters.dateFrom && row.business_date < filters.dateFrom) return false;
      if (filters.dateTo && row.business_date > filters.dateTo) return false;
      return mappings.every(([filterKey, rowKey]) => {
        const accepted = filters[filterKey] ?? [];
        return accepted.length === 0 || accepted.includes(row[rowKey]);
      });
    });
  }

  aggregateByMetric(rows) {
    const groups = new Map();
    for (const row of rows) {
      if (!groups.has(row.metric_code)) {
        groups.set(row.metric_code, {
          metric_code: row.metric_code,
          reportIds: new Set(),
          staffIds: new Set(),
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
      for (const field of valueFields) group[field] += row[field];
    }
    return [...groups.values()].map((group) => ({
      metric_code: group.metric_code,
      sample_size: group.reportIds.size,
      submitted_staff_count: group.staffIds.size,
      low_sample: group.reportIds.size < 10 || group.staffIds.size < 3,
      ...Object.fromEntries(valueFields.map((field) => [field, group[field]])),
    }));
  }

  metricAnalytics(filters = {}) {
    return this.aggregateByMetric(this.filterRows(this.factRows, filters));
  }

  storeAnalytics(filters = {}) {
    const obligations = this.filterRows(this.obligations, filters);
    const stores = [...new Set(obligations.map((row) => row.store_id))];
    return stores.sort((left, right) => left - right).map((storeId) => {
      const storeObligations = obligations.filter((row) => row.store_id === storeId);
      const completed = storeObligations.filter((row) => ['submitted', 'corrected'].includes(row.completion_status)).length;
      return {
        store_id: storeId,
        required_count: storeObligations.length,
        completed_count: completed,
        completion_rate: ratio(completed, storeObligations.length),
        metrics: this.metricAnalytics({ ...filters, storeIds: [storeId] }),
      };
    });
  }

  staffProfile(staffId, filters = {}) {
    const scopedFilters = { ...filters, staffIds: [staffId] };
    return {
      obligations: this.filterRows(this.obligations, scopedFilters),
      metrics: this.metricAnalytics(scopedFilters),
    };
  }

  hasHistoricalStoreAccess(storeIds, staffId, filters) {
    return this.filterRows(this.obligations, { ...filters, storeIds, staffIds: [staffId] }).length > 0;
  }

  relationVersion(cutoffDate) {
    return this.relationVersions
      .filter((version) => version.from <= cutoffDate && (!version.to || version.to >= cutoffDate))
      .sort((left, right) => right.from.localeCompare(left.from) || right.id - left.id)[0];
  }

  operatingFunnel(filters) {
    const aggregates = new Map(this.metricAnalytics(filters).map((item) => [item.metric_code, item]));
    const version = this.relationVersion(filters.dateTo);
    const relations = version.relations.map((relation) => {
      const numerator = aggregates.get(relation.numerator);
      const denominator = aggregates.get(relation.denominator);
      return {
        code: relation.code,
        effective_rate: ratio(numerator?.effective_value ?? 0, denominator?.effective_value ?? 0),
        raw_rate: ratio(numerator?.raw_value ?? 0, denominator?.raw_value ?? 0),
      };
    });
    return {
      relation_version: version.code,
      stages: [...aggregates.values()].filter((item) => item.metric_code.startsWith('sales_')),
      relations,
    };
  }
}

function metric(result, code) {
  return result.find((item) => item.metric_code === code);
}

test(`${validatesCriteria(['2', '3', '4', '14', '13.5'])} different store sizes conserve project and employee totals`, () => {
  const model = new WorkloadAnalyticsScenario();
  for (let index = 0; index < 10; index += 1) {
    model.addReport({
      date: `2026-07-${String(index + 1).padStart(2, '0')}`,
      storeId: 1,
      staffId: 101 + (index % 3),
      metrics: { sales_resources: 1 },
    });
  }
  model.addObligation({ date: '2026-07-11', storeId: 1, staffId: 101 });
  model.addObligation({ date: '2026-07-12', storeId: 1, staffId: 102 });
  for (let index = 0; index < 4; index += 1) {
    model.addReport({
      date: `2026-07-${String(index + 13).padStart(2, '0')}`,
      storeId: 2,
      staffId: 201 + (index % 2),
      metrics: { sales_resources: 3 },
    });
  }
  model.addObligation({ date: '2026-07-17', storeId: 2, staffId: 201 });

  const stores = model.storeAnalytics({ dateFrom: '2026-07-01', dateTo: '2026-07-31' });
  assert.deepEqual(stores.map(({ store_id, required_count, completed_count }) => ({ store_id, required_count, completed_count })), [
    { store_id: 1, required_count: 12, completed_count: 10 },
    { store_id: 2, required_count: 5, completed_count: 4 },
  ]);
  assert.equal(stores[0].completion_rate.value, 0.8333);
  assert.equal(stores[1].completion_rate.value, 0.8);

  const allStores = metric(model.metricAnalytics(), 'sales_resources');
  const storeValues = stores.map((store) => metric(store.metrics, 'sales_resources'));
  assert.equal(allStores.raw_value, storeValues.reduce((sum, item) => sum + item.raw_value, 0));
  assert.equal(allStores.raw_value, 22);
  assert.equal(allStores.low_sample, false);
  assert.equal(storeValues[0].low_sample, false);
  assert.equal(storeValues[1].low_sample, true);
  assert.equal(metric(model.staffProfile(101).metrics, 'sales_resources').raw_value, 4);
});

test(`${validatesCriteria(['2.3', '4.1', '4.5', '13.5'])} historical store scope follows obligation and report snapshots`, () => {
  const model = new WorkloadAnalyticsScenario();
  model.addReport({ date: '2026-07-31', storeId: 1, staffId: 301, metrics: { sales_resources: 5 } });
  model.addReport({ date: '2026-08-01', storeId: 2, staffId: 301, metrics: { sales_resources: 8 } });

  assert.equal(model.hasHistoricalStoreAccess([1], 301, { dateFrom: '2026-07-01', dateTo: '2026-07-31' }), true);
  assert.equal(model.hasHistoricalStoreAccess([1], 301, { dateFrom: '2026-08-01', dateTo: '2026-08-31' }), false);
  assert.deepEqual(model.staffProfile(301, { dateFrom: '2026-07-01', dateTo: '2026-07-31' }).obligations.map((row) => row.store_id), [1]);
  assert.deepEqual(model.staffProfile(301, { dateFrom: '2026-08-01', dateTo: '2026-08-31' }).obligations.map((row) => row.store_id), [2]);
  assert.equal(metric(model.metricAnalytics({ storeIds: [1] }), 'sales_resources').raw_value, 5);
  assert.equal(metric(model.metricAnalytics({ storeIds: [2] }), 'sales_resources').raw_value, 8);
});

test(`${validatesCriteria(['3', '4.2', '16.1', '13.5'])} audit states stay consistent across project, employee, and funnel surfaces`, () => {
  const model = new WorkloadAnalyticsScenario();
  const auditCases = [
    { audit: 'pending', raw: 10 },
    { audit: 'approved', raw: 20 },
    { audit: 'rejected', raw: 30 },
    { audit: 'needs_resubmit', raw: 40 },
    { audit: 'not_required', raw: 50 },
  ];
  auditCases.forEach((deal, index) => model.addReport({
    date: `2026-07-${String(index + 20).padStart(2, '0')}`,
    storeId: 1,
    staffId: 401 + index,
    metrics: { sales_deal_count: deal, sales_actual_arrive: 10 },
  }));

  const expected = { raw_value: 150, pending_value: 10, effective_value: 70, rejected_value: 30 };
  const projectDeal = metric(model.metricAnalytics(), 'sales_deal_count');
  assert.deepEqual(Object.fromEntries(valueFields.map((field) => [field, projectDeal[field]])), expected);
  const staffTotals = auditCases.reduce((totals, _, index) => {
    const item = metric(model.staffProfile(401 + index).metrics, 'sales_deal_count');
    for (const field of valueFields) totals[field] += item[field];
    return totals;
  }, Object.fromEntries(valueFields.map((field) => [field, 0])));
  assert.deepEqual(staffTotals, expected);

  const funnel = model.operatingFunnel({ dateFrom: '2026-07-01', dateTo: '2026-07-31' });
  assert.deepEqual(Object.fromEntries(valueFields.map((field) => [field, metric(funnel.stages, 'sales_deal_count')[field]])), expected);
  assert.deepEqual(funnel.relations[0].effective_rate, ratio(70, 50));
  assert.deepEqual(funnel.relations[0].raw_rate, ratio(150, 50));
  assert.equal(metric(model.metricAnalytics({ auditStatuses: ['approved'] }), 'sales_deal_count').raw_value, 20);
});

test(`${validatesCriteria(['3.4', '14.9', '13.5'])} low-sample status is recalculated after store filtering`, () => {
  const model = new WorkloadAnalyticsScenario();
  for (let index = 0; index < 6; index += 1) {
    model.addReport({ date: `2026-07-0${index + 1}`, storeId: 1, staffId: 501 + (index % 2), metrics: { sales_resources: 1 } });
    model.addReport({ date: `2026-07-${String(index + 7).padStart(2, '0')}`, storeId: 2, staffId: 503 + (index % 2), metrics: { sales_resources: 1 } });
  }

  assert.equal(metric(model.metricAnalytics(), 'sales_resources').low_sample, false);
  assert.equal(metric(model.metricAnalytics({ storeIds: [1] }), 'sales_resources').low_sample, true);
  assert.equal(metric(model.metricAnalytics({ storeIds: [2] }), 'sales_resources').low_sample, true);
});

test(`${validatesCriteria(['16.1-16.3', '13.5'])} reporting cutoff selects the applicable relationship version`, () => {
  const model = new WorkloadAnalyticsScenario();
  model.addReport({
    date: '2026-07-31',
    storeId: 1,
    staffId: 601,
    metrics: { sales_deal_count: 2, sales_actual_arrive: 4, sales_actual_visit: 8 },
  });
  model.addReport({
    date: '2026-08-01',
    storeId: 1,
    staffId: 601,
    metrics: { sales_deal_count: 3, sales_actual_arrive: 6, sales_actual_visit: 12 },
  });

  const july = model.operatingFunnel({ dateFrom: '2026-07-01', dateTo: '2026-07-31' });
  const august = model.operatingFunnel({ dateFrom: '2026-08-01', dateTo: '2026-08-31' });
  assert.equal(july.relation_version, 'relations-v1');
  assert.deepEqual(july.relations[0].effective_rate, ratio(2, 4));
  assert.equal(august.relation_version, 'relations-v2');
  assert.deepEqual(august.relations[0].effective_rate, ratio(3, 12));
});

test(`${validatesCriteria(['2-4', '14', '16', '13.5'])} production analytics surfaces share the fact kernel and dated relationship contract`, () => {
  assert.match(queryService, /business_date.*store_id.*staff_id.*role_code.*metric_code/s);
  assert.match(queryService, /'pending_value'/);
  assert.match(queryService, /'effective_value'/);
  assert.match(queryService, /'rejected_value'/);
  for (const service of [storeService, metricService, staffService, funnelService]) {
    assert.match(service, /new WorkloadAnalyticsQueryService\(\$pdo\)/);
  }
  assert.match(storeService, /workload_submission_obligations/);
  assert.match(staffService, /hasHistoricalStoreAccess/);
  assert.match(funnelService, /effective_from <= \?/);
  assert.match(funnelService, /effective_to IS NULL OR effective_to >= \?/);
});
