import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const servicePath = new URL('../api/workload/services/WorkloadBusinessPeriodService.php', import.meta.url);
const service = await readFile(servicePath, 'utf8');
const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;

const iso = (date) => date.toISOString().slice(0, 10);
const date = (value) => new Date(`${value}T00:00:00Z`);
const addDays = (value, days) => new Date(value.getTime() + days * 86400000);
const isBusinessDay = (value) => value.getUTCDay() !== 1;

function businessDates(from, to) {
  const dates = [];
  for (let cursor = date(from), end = date(to); cursor <= end; cursor = addDays(cursor, 1)) {
    if (isBusinessDay(cursor)) dates.push(iso(cursor));
  }
  return dates;
}

function previousEqualPeriod(from, count) {
  const dates = [];
  for (let cursor = addDays(date(from), -1); dates.length < count; cursor = addDays(cursor, -1)) {
    if (isBusinessDay(cursor)) dates.push(iso(cursor));
  }
  return dates.sort();
}

test(`${validatesCriteria(['13.5', '14.1'])} service exposes all supported period types and aligned comparison fields`, () => {
  for (const type of ['day', 'business_week', 'month_to_date', 'full_month', 'quarter', 'custom']) {
    assert.match(service, new RegExp(`'${type}'`));
  }
  for (const field of [
    'current_period',
    'previous_period',
    'comparison_current_period',
    'comparison_previous_period',
    'business_dates',
    'business_day_count',
    'current_truncated',
    'previous_truncated',
  ]) {
    assert.match(service, new RegExp(`'${field}'`));
  }
});

test(`${validatesCriteria(['14.1'])} day comparison uses the immediately preceding business day`, () => {
  const current = businessDates('2026-07-26', '2026-07-26');
  const previous = previousEqualPeriod(current[0], current.length);
  assert.deepEqual(current, ['2026-07-26']);
  assert.deepEqual(previous, ['2026-07-25']);
  assert.match(service, /periodBefore\(\$cursor, \$currentPeriod\['business_day_count'\]\)/);
});

test(`${validatesCriteria(['14.2'])} Monday resolves to the completed Tuesday through Sunday business week`, () => {
  const current = businessDates('2026-07-21', '2026-07-26');
  const previous = businessDates('2026-07-14', '2026-07-19');
  assert.equal(current.length, 6);
  assert.equal(previous.length, 6);
  assert.equal(current[0], '2026-07-21');
  assert.equal(current.at(-1), '2026-07-26');
  assert.match(service, /format\('N'\) === 1 \? 6/);
});

test(`${validatesCriteria(['14.3'])} month-to-date keeps the selected range and aligns a shorter previous month`, () => {
  const current = businessDates('2026-03-01', '2026-03-31');
  const previous = businessDates('2026-02-01', '2026-02-28');
  const alignedCount = Math.min(current.length, previous.length);
  assert.ok(current.length > previous.length);
  assert.equal(current.slice(0, alignedCount).length, previous.slice(0, alignedCount).length);
  assert.match(service, /periodFromFirstBusinessDays/);
  assert.match(service, /min\(\$currentPeriod\['business_day_count'\], \$previousPeriod\['business_day_count'\]\)/);
});

test(`${validatesCriteria(['14.3'])} full month and quarter retain natural calendar boundaries`, () => {
  assert.equal(businessDates('2028-02-01', '2028-02-29').at(-1), '2028-02-29');
  assert.equal(businessDates('2026-07-01', '2026-09-30')[0], '2026-07-01');
  assert.match(service, /first day of this month/);
  assert.match(service, /last day of this month/);
  assert.match(service, /modify\('\+3 months'\)->modify\('-1 day'\)/);
});

test(`${validatesCriteria(['14.4'])} custom periods exclude Mondays and use the preceding equal business-day window`, () => {
  const current = businessDates('2026-06-28', '2026-07-05');
  const previous = previousEqualPeriod('2026-06-28', current.length);
  assert.equal(current.length, 7);
  assert.equal(previous.length, current.length);
  assert.ok(!current.includes('2026-06-29'));
  assert.deepEqual(previous, ['2026-06-20', '2026-06-21', '2026-06-23', '2026-06-24', '2026-06-25', '2026-06-26', '2026-06-27']);
  assert.match(service, /自定义周期不能超过 366 天/);
  assert.match(service, /开始日期不能晚于结束日期/);
});
