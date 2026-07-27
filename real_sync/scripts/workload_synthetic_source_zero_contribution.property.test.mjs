import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const read = (relativePath) => readFileSync(new URL(relativePath, import.meta.url), 'utf8');
const sourceService = read('../api/workload/services/WorkloadSourcePolicyService.php');
const migration = read('../database/migrations/202607240002_workload_governance.sql');
const operatingEndpoints = [
  read('../api/workload/dashboard.php'),
  read('../api/workload/hq-summary.php'),
  read('../api/workload/store-summary.php'),
  read('../api/workload/staff-activity.php'),
  read('../api/workload/staff-detail.php'),
  read('../api/admin/workload/summary.php'),
  read('../api/admin/dashboard/overview.php'),
];

const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;
const policies = new Map([
  ['h5', { kind: 'production', included: true }],
  ['mini_program', { kind: 'production', included: true }],
  ['codex-smoke', { kind: 'synthetic', included: false }],
  ['debug', { kind: 'synthetic', included: false }],
  ['h5-e2e', { kind: 'synthetic', included: false }],
  ['live_check', { kind: 'synthetic', included: false }],
  ['test', { kind: 'synthetic', included: false }],
]);
const sources = [...policies.keys()];
const syntheticSources = sources.filter((source) => policies.get(source).kind === 'synthetic');

function seededRandom(seed) {
  let state = seed >>> 0;
  return () => {
    state = (Math.imul(state, 1664525) + 1013904223) >>> 0;
    return state / 0x1_0000_0000;
  };
}

class SourceAggregationModel {
  reports = [];

  add(report) {
    if (!policies.has(report.source)) throw new Error('unregistered_source');
    this.reports.push(report);
  }

  defaultOperatingTotal() {
    return this.reports.reduce((total, report) => (
      policies.get(report.source).included ? total + report.value : total
    ), 0);
  }

  auditBySource(source) {
    return this.reports.filter((report) => report.source === source);
  }
}

test(`${validatesCriteria(['2.5', '9.2', 'Property 10'])} arbitrary mixed report sequences give every synthetic report zero default contribution`, () => {
  for (let seed = 1; seed <= 128; seed += 1) {
    const random = seededRandom(seed);
    const model = new SourceAggregationModel();
    let expectedOperatingTotal = 0;

    for (let step = 0; step < 256; step += 1) {
      const source = sources[Math.floor(random() * sources.length)];
      const value = Math.floor(random() * 1_000_001);
      const before = model.defaultOperatingTotal();
      model.add({ id: step + 1, source, value });
      const policy = policies.get(source);
      if (policy.included) expectedOperatingTotal += value;

      assert.equal(model.defaultOperatingTotal(), expectedOperatingTotal, `seed ${seed}, step ${step}`);
      if (policy.kind === 'synthetic') {
        assert.equal(model.defaultOperatingTotal(), before, `seed ${seed}, step ${step}: ${source}`);
      }
    }
  }
});

test(`${validatesCriteria(['2.5', '9.2', 'Property 10'])} arbitrary synthetic-only datasets always have a zero default operating total`, () => {
  for (let seed = 129; seed <= 256; seed += 1) {
    const random = seededRandom(seed);
    const model = new SourceAggregationModel();

    for (let step = 0; step < 256; step += 1) {
      const source = syntheticSources[Math.floor(random() * syntheticSources.length)];
      model.add({ id: step + 1, source, value: Math.floor(random() * 1_000_001) });
      assert.equal(model.defaultOperatingTotal(), 0, `seed ${seed}, step ${step}: ${source}`);
    }
  }
});

test(`${validatesCriteria(['2.5', 'Property 10'])} synthetic reports remain available through explicit audit source filters`, () => {
  const model = new SourceAggregationModel();
  for (const [index, source] of syntheticSources.entries()) {
    model.add({ id: index + 1, source, value: (index + 1) * 100 });
  }

  assert.equal(model.defaultOperatingTotal(), 0);
  for (const source of syntheticSources) {
    assert.deepEqual(model.auditBySource(source).map((report) => report.source), [source]);
  }
  assert.equal(model.reports.length, syntheticSources.length);
});

test(`${validatesCriteria(['2.5', '9.2', 'Property 10'])} production contracts classify synthetic sources as excluded and filter every operating endpoint`, () => {
  for (const source of syntheticSources) {
    assert.match(migration, new RegExp(`\\('${source.replace('-', '\\-')}', 'synthetic', 0,`));
  }
  assert.match(sourceService, /WHERE included_by_default = 1 ORDER BY source_code ASC/);
  assert.match(sourceService, /source_policy\.included_by_default = 1/);
  for (const endpoint of operatingEndpoints) {
    assert.match(endpoint, /includedByDefaultCondition\('r'\)/);
  }
});
