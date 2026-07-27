import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const service = await readFile(
  new URL('../api/workload/services/WorkloadBusinessPeriodService.php', import.meta.url),
  'utf8',
);
const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;
const periodTypes = ['day', 'business_week', 'month_to_date', 'full_month', 'quarter', 'custom'];
const dayMs = 86400000;

const date = (value) => new Date(`${value}T00:00:00Z`);
const iso = (value) => value.toISOString().slice(0, 10);
const addDays = (value, days) => new Date(value.getTime() + days * dayMs);
const isBusinessDay = (value) => value.getUTCDay() !== 1;

function businessDates(from, to) {
  const dates = [];
  for (let cursor = from; cursor <= to; cursor = addDays(cursor, 1)) {
    if (isBusinessDay(cursor)) dates.push(iso(cursor));
  }
  return dates;
}

function monthStart(value) {
  return new Date(Date.UTC(value.getUTCFullYear(), value.getUTCMonth(), 1));
}

function monthEnd(value) {
  return new Date(Date.UTC(value.getUTCFullYear(), value.getUTCMonth() + 1, 0));
}

function currentRange(type, anchor, customFrom, customTo) {
  if (type === 'day') return [anchor, anchor];
  if (type === 'business_week') {
    const day = anchor.getUTCDay() === 1 ? 6 : anchor.getUTCDay() - 2;
    const from = addDays(anchor, -day);
    return [from, addDays(from, 5)];
  }
  if (type === 'month_to_date') return [monthStart(anchor), anchor];
  if (type === 'full_month') return [monthStart(anchor), monthEnd(anchor)];
  if (type === 'quarter') {
    const month = Math.floor(anchor.getUTCMonth() / 3) * 3;
    const from = new Date(Date.UTC(anchor.getUTCFullYear(), month, 1));
    return [from, addDays(new Date(Date.UTC(anchor.getUTCFullYear(), month + 3, 1)), -1)];
  }
  return [customFrom, customTo];
}

function previousBusinessDates(from, count) {
  const dates = [];
  for (let cursor = addDays(from, -1); dates.length < count; cursor = addDays(cursor, -1)) {
    if (isBusinessDay(cursor)) dates.push(iso(cursor));
  }
  return dates.sort();
}

function resolve(type, anchor, customFrom, customTo) {
  const [currentFrom, currentTo] = currentRange(type, anchor, customFrom, customTo);
  const current = businessDates(currentFrom, currentTo);
  if (current.length === 0) return { current, previous: [] };
  let previous;
  if (type === 'business_week') {
    const from = addDays(currentFrom, -7);
    previous = businessDates(from, addDays(from, 5));
  } else if (type === 'month_to_date') {
    const previousMonth = new Date(Date.UTC(anchor.getUTCFullYear(), anchor.getUTCMonth() - 1, 1));
    previous = businessDates(monthStart(previousMonth), monthEnd(previousMonth)).slice(0, current.length);
  } else if (type === 'full_month') {
    const from = new Date(Date.UTC(anchor.getUTCFullYear(), anchor.getUTCMonth() - 1, 1));
    previous = businessDates(from, monthEnd(from));
  } else if (type === 'quarter') {
    const from = new Date(Date.UTC(currentFrom.getUTCFullYear(), currentFrom.getUTCMonth() - 3, 1));
    previous = businessDates(from, addDays(currentFrom, -1));
  } else {
    previous = previousBusinessDates(currentFrom, current.length);
  }
  const alignedCount = Math.min(current.length, previous.length);
  return { current: current.slice(0, alignedCount), previous: previous.slice(0, alignedCount) };
}

function random(seed) {
  let state = seed >>> 0;
  return () => {
    state = (1664525 * state + 1013904223) >>> 0;
    return state / 0x100000000;
  };
}

test(`${validatesCriteria(['16', '14.1-14.6'])} every generated comparison window has equal business-day counts`, () => {
  for (let seed = 0; seed < 128; seed += 1) {
    const next = random(20260724 + seed);
    for (let step = 0; step < 256; step += 1) {
      const anchor = new Date(Date.UTC(2023 + Math.floor(next() * 7), Math.floor(next() * 12), 1 + Math.floor(next() * 28)));
      const type = periodTypes[Math.floor(next() * periodTypes.length)];
      const customFrom = addDays(anchor, -Math.floor(next() * 365));
      const customTo = addDays(customFrom, Math.floor(next() * 365));
      const result = resolve(type, anchor, customFrom, customTo);
      assert.equal(result.current.length, result.previous.length, `${seed}:${step}:${type}`);
      assert.ok(result.current.every((value) => isBusinessDay(date(value))));
      assert.ok(result.previous.every((value) => isBusinessDay(date(value))));
    }
  }
  assert.match(service, /\$alignedCount = min\(/);
  assert.match(service, /comparison_current_period/);
  assert.match(service, /comparison_previous_period/);
});
