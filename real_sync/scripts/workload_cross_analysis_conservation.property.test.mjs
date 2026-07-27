import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const service = await readFile(
  new URL('../api/workload/services/WorkloadCrossAnalysisService.php', import.meta.url),
  'utf8',
);
const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;
const dimensions = ['store', 'metric', 'staff', 'time'];
const valueFields = ['raw_value', 'pending_value', 'effective_value', 'rejected_value'];

function random(seed) {
  let state = seed >>> 0;
  return () => {
    state = (1664525 * state + 1013904223) >>> 0;
    return state / 0x100000000;
  };
}

function dimensionValue(row, dimension) {
  if (dimension === 'store') return row.store_id;
  if (dimension === 'metric') return row.metric_code;
  if (dimension === 'staff') return row.staff_id;
  return row.business_date;
}

function addValues(target, row) {
  for (const field of valueFields) target[field] += row[field];
}

function finestAggregate(rows) {
  const uniqueFacts = new Map();
  for (const row of rows) uniqueFacts.set(`${row.report_id}:${row.metric_code}`, row);
  const result = Object.fromEntries(valueFields.map((field) => [field, 0]));
  for (const row of uniqueFacts.values()) addValues(result, row);
  return result;
}

function crossAggregate(rows, primary, secondary) {
  const cells = new Map();
  for (const row of rows) {
    const cellKey = `${dimensionValue(row, primary)}|${dimensionValue(row, secondary)}`;
    if (!cells.has(cellKey)) {
      cells.set(cellKey, {
        values: Object.fromEntries(valueFields.map((field) => [field, 0])),
        factKeys: new Set(),
      });
    }
    const cell = cells.get(cellKey);
    const factKey = `${row.report_id}:${row.metric_code}`;
    if (cell.factKeys.has(factKey)) continue;
    cell.factKeys.add(factKey);
    addValues(cell.values, row);
  }
  const result = Object.fromEntries(valueFields.map((field) => [field, 0]));
  for (const cell of cells.values()) addValues(result, cell.values);
  return result;
}

function generatedFacts(next) {
  const facts = [];
  for (let reportId = 1; reportId <= 256; reportId += 1) {
    const storeId = 1 + Math.floor(next() * 8);
    const staffId = 1 + Math.floor(next() * 40);
    const businessDate = `2026-${String(1 + Math.floor(next() * 4)).padStart(2, '0')}-${String(1 + Math.floor(next() * 28)).padStart(2, '0')}`;
    const metricCount = 1 + Math.floor(next() * 4);
    for (let metricIndex = 0; metricIndex < metricCount; metricIndex += 1) {
      const raw = Math.floor(next() * 101);
      const pending = Math.floor(next() * (raw + 1));
      const rejected = Math.floor(next() * (raw - pending + 1));
      const row = {
        report_id: reportId,
        store_id: storeId,
        staff_id: staffId,
        business_date: businessDate,
        metric_code: `metric_${metricIndex + 1}`,
        raw_value: raw,
        pending_value: pending,
        effective_value: raw - pending - rejected,
        rejected_value: rejected,
      };
      facts.push(row);
      if (next() < 0.15) facts.push({ ...row });
    }
  }
  return facts;
}

test(`${validatesCriteria(['17', '13.1-13.6'])} every cross-table value sum equals the finest fact aggregate`, () => {
  for (let seed = 0; seed < 128; seed += 1) {
    const next = random(20260726 + seed);
    const facts = generatedFacts(next);
    const expected = finestAggregate(facts);
    for (const primary of dimensions) {
      for (const secondary of dimensions) {
        if (primary === secondary) continue;
        assert.deepEqual(crossAggregate(facts, primary, secondary), expected, `${seed}:${primary}:${secondary}`);
      }
    }
  }
  assert.match(service, /\$factKey = \(int\) \(\$row\['report_id'\]/);
  assert.match(service, /if \(isset\(\$state\['_fact_keys'\]\[\$factKey\]\)\)/);
  assert.match(service, /'summary' => \$this->finalizeState\(\$summary\)/);
  assert.match(service, /'matrix' => \$rows/);
});
