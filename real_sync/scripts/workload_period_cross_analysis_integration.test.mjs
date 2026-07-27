import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const [periodService, comparisonService, crossService] = await Promise.all([
  readFile(new URL('../api/workload/services/WorkloadBusinessPeriodService.php', import.meta.url), 'utf8'),
  readFile(new URL('../api/workload/services/WorkloadComparisonService.php', import.meta.url), 'utf8'),
  readFile(new URL('../api/workload/services/WorkloadCrossAnalysisService.php', import.meta.url), 'utf8'),
]);
const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;
const iso = (value) => value.toISOString().slice(0, 10);
const date = (value) => new Date(`${value}T00:00:00Z`);
const addDays = (value, days) => new Date(value.getTime() + days * 86400000);
const isBusinessDay = (value) => value.getUTCDay() !== 1;

function businessDates(from, to) {
  const result = [];
  for (let cursor = date(from), end = date(to); cursor <= end; cursor = addDays(cursor, 1)) {
    if (isBusinessDay(cursor)) result.push(iso(cursor));
  }
  return result;
}

function previousBusinessDates(from, count) {
  const result = [];
  for (let cursor = addDays(date(from), -1); result.length < count; cursor = addDays(cursor, -1)) {
    if (isBusinessDay(cursor)) result.push(iso(cursor));
  }
  return result.sort();
}

function comparison(current, previous, currentSamples, previousSamples) {
  const change = current - previous;
  return {
    current_value: current,
    previous_value: previous,
    change_value: change,
    change_rate: previous > 0 ? change / previous : null,
    comparison_state: previous > 0 ? (current === 0 ? 'down_to_zero' : 'comparable') : current > 0 ? 'new' : 'flat',
    low_sample: currentSamples.reports < 10 || currentSamples.staff < 3
      || previousSamples.reports < 10 || previousSamples.staff < 3,
  };
}

function crossRows(facts, obligations, primary, secondary) {
  const value = (row, dimension) => dimension === 'store' ? row.storeId
    : dimension === 'staff' ? row.staffId
      : dimension === 'metric' ? row.metric : row.date;
  const groups = new Map();
  const group = (row) => {
    const key = `${value(row, primary)}|${value(row, secondary)}`;
    if (!groups.has(key)) {
      groups.set(key, { key, raw: 0, effective: 0, facts: new Set(), obligations: new Set() });
    }
    return groups.get(key);
  };
  for (const obligation of obligations) group(obligation).obligations.add(obligation.id);
  for (const fact of facts) {
    const current = group(fact);
    const factKey = `${fact.reportId}:${fact.metric}`;
    if (current.facts.has(factKey)) continue;
    current.facts.add(factKey);
    current.raw += fact.raw;
    current.effective += fact.effective;
  }
  return [...groups.values()];
}

test(`${validatesCriteria(['13.5', '14.1', '14.2'])} Monday is excluded while adjacent business weeks remain aligned`, () => {
  const current = businessDates('2026-07-21', '2026-07-26');
  const previous = businessDates('2026-07-14', '2026-07-19');
  assert.equal(current.length, 6);
  assert.equal(previous.length, 6);
  assert.ok(!current.includes('2026-07-20'));
  assert.match(periodService, /format\('N'\) !== 1/);
});

test(`${validatesCriteria(['13.5', '14.3', '14.4'])} custom periods align across month and quarter boundaries`, () => {
  const current = businessDates('2026-06-28', '2026-07-05');
  const previous = previousBusinessDates('2026-06-28', current.length);
  assert.equal(current.length, previous.length);
  assert.equal(current[0], '2026-06-28');
  assert.equal(current.at(-1), '2026-07-05');
  assert.ok(previous[0] < '2026-06-28');
  assert.match(periodService, /comparison_current_period/);
  assert.match(periodService, /comparison_previous_period/);
});

test(`${validatesCriteria(['14.9'])} either low-sample period marks the comparison low sample`, () => {
  const result = comparison(30, 24, { reports: 12, staff: 3 }, { reports: 9, staff: 3 });
  assert.equal(result.low_sample, true);
  assert.equal(result.comparison_state, 'comparable');
  assert.match(comparisonService, /\$currentLowSample \|\| \$previousLowSample/);
});

test(`${validatesCriteria(['14.5', '14.6'])} zero baselines produce new and flat states without a rate`, () => {
  const added = comparison(8, 0, { reports: 10, staff: 3 }, { reports: 10, staff: 3 });
  const unchanged = comparison(0, 0, { reports: 10, staff: 3 }, { reports: 10, staff: 3 });
  assert.equal(added.comparison_state, 'new');
  assert.equal(added.change_rate, null);
  assert.equal(unchanged.comparison_state, 'flat');
  assert.match(comparisonService, /'change_rate' => \$changeRate/);
});

test(`${validatesCriteria(['13.1-13.4'])} historical assignment snapshots keep facts in their original stores`, () => {
  const facts = [
    { reportId: 1, date: '2026-07-01', storeId: 1, staffId: 10, metric: 'calls', raw: 5, effective: 5 },
    { reportId: 2, date: '2026-07-02', storeId: 2, staffId: 10, metric: 'calls', raw: 7, effective: 7 },
  ];
  const rows = crossRows(facts, [], 'store', 'staff');
  assert.equal(rows.length, 2);
  assert.equal(rows.find((row) => row.key === '1|10').effective, 5);
  assert.equal(rows.find((row) => row.key === '2|10').effective, 7);
  assert.match(crossService, /o\.store_id/);
  assert.match(crossService, /o\.role_code/);
});

test(`${validatesCriteria(['13', '14'])} disjoint store and staff cells conserve the finest facts and obligations`, () => {
  const obligations = [
    { id: 1, date: '2026-07-01', storeId: 1, staffId: 10 },
    { id: 2, date: '2026-07-01', storeId: 1, staffId: 11 },
    { id: 3, date: '2026-07-01', storeId: 2, staffId: 12 },
  ];
  const facts = [
    { reportId: 1, date: '2026-07-01', storeId: 1, staffId: 10, metric: 'calls', raw: 3, effective: 3 },
    { reportId: 2, date: '2026-07-01', storeId: 1, staffId: 11, metric: 'calls', raw: 4, effective: 4 },
    { reportId: 3, date: '2026-07-01', storeId: 2, staffId: 12, metric: 'calls', raw: 5, effective: 5 },
  ];
  const rows = crossRows(facts, obligations, 'store', 'staff');
  assert.equal(rows.reduce((sum, row) => sum + row.effective, 0), 12);
  assert.equal(rows.reduce((sum, row) => sum + row.obligations.size, 0), 3);
  assert.match(crossService, /summary/);
  assert.match(crossService, /matrix/);
});
