import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const migration = readFileSync(
  new URL('../database/migrations/202607240002_workload_governance.sql', import.meta.url),
  'utf8',
);
const obligationService = readFileSync(
  new URL('../api/workload/services/WorkloadObligationService.php', import.meta.url),
  'utf8',
);

const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;
const completionStatuses = ['missing', 'draft', 'submitted', 'locked_missing', 'corrected'];
const roleCodes = ['sales', 'coach'];

function seededRandom(seed) {
  let state = seed >>> 0;
  return () => {
    state = (Math.imul(state, 1664525) + 1013904223) >>> 0;
    return state / 0x1_0000_0000;
  };
}

function obligationKey(obligation) {
  return [
    obligation.obligationDate,
    obligation.storeId,
    obligation.staffId,
    obligation.roleCode,
  ].join(':');
}

class StoreObligationModel {
  obligations = new Map();

  upsert(obligation) {
    this.obligations.set(obligationKey(obligation), structuredClone(obligation));
  }

  updateCompletion(key, completionStatus) {
    const current = this.obligations.get(key);
    if (current) current.completionStatus = completionStatus;
  }

  details(filters) {
    return [...this.obligations.values()].filter((obligation) => (
      obligation.requiredStatus === 'required'
      && obligation.obligationDate >= filters.dateFrom
      && obligation.obligationDate <= filters.dateTo
      && (filters.storeIds.length === 0 || filters.storeIds.includes(obligation.storeId))
      && (filters.staffIds.length === 0 || filters.staffIds.includes(obligation.staffId))
      && (filters.roleCodes.length === 0 || filters.roleCodes.includes(obligation.roleCode))
      && (
        filters.completionStatuses.length === 0
        || filters.completionStatuses.includes(obligation.completionStatus)
      )
    ));
  }

  storeSummary(filters) {
    const rows = new Map();
    for (const obligation of this.details(filters)) {
      const current = rows.get(obligation.storeId) ?? {
        storeId: obligation.storeId,
        requiredCount: 0,
      };
      current.requiredCount += 1;
      rows.set(obligation.storeId, current);
    }
    return [...rows.values()].sort((left, right) => left.storeId - right.storeId);
  }
}

function randomDate(random) {
  return `2026-08-${String(1 + Math.floor(random() * 28)).padStart(2, '0')}`;
}

function randomSubset(random, values) {
  if (random() < 0.35) return [];
  return values.filter(() => random() < 0.5);
}

function randomObligation(random) {
  const obligationDate = randomDate(random);
  const day = new Date(`${obligationDate}T00:00:00Z`).getUTCDay();
  return {
    obligationDate,
    storeId: 1 + Math.floor(random() * 6),
    staffId: 1 + Math.floor(random() * 30),
    roleCode: roleCodes[Math.floor(random() * roleCodes.length)],
    requiredStatus: day === 1 || random() < 0.12 ? 'exempt' : 'required',
    completionStatus: completionStatuses[Math.floor(random() * completionStatuses.length)],
  };
}

function randomFilters(random) {
  const firstDay = 1 + Math.floor(random() * 28);
  const secondDay = 1 + Math.floor(random() * 28);
  return {
    dateFrom: `2026-08-${String(Math.min(firstDay, secondDay)).padStart(2, '0')}`,
    dateTo: `2026-08-${String(Math.max(firstDay, secondDay)).padStart(2, '0')}`,
    storeIds: randomSubset(random, [1, 2, 3, 4, 5, 6]),
    staffIds: randomSubset(random, Array.from({ length: 30 }, (_, index) => index + 1)),
    roleCodes: randomSubset(random, roleCodes),
    completionStatuses: randomSubset(random, completionStatuses),
  };
}

function assertProperty4(model, filters, seed, step) {
  const details = model.details(filters);
  const summary = model.storeSummary(filters);
  const summaryTotal = summary.reduce((total, row) => total + row.requiredCount, 0);

  assert.equal(summaryTotal, details.length, `seed ${seed}, step ${step}: total required count`);
  for (const row of summary) {
    const storeDetailCount = details.filter(({ storeId }) => storeId === row.storeId).length;
    assert.equal(
      row.requiredCount,
      storeDetailCount,
      `seed ${seed}, step ${step}: store ${row.storeId} required count`,
    );
  }
}

test(`${validatesCriteria(['2.2', '2.4', 'Property 4'])} arbitrary store summaries equal their filtered obligation details`, () => {
  for (let seed = 1; seed <= 128; seed += 1) {
    const random = seededRandom(seed);
    const model = new StoreObligationModel();
    const keys = [];

    for (let step = 0; step < 256; step += 1) {
      if (keys.length > 0 && random() < 0.3) {
        const key = keys[Math.floor(random() * keys.length)];
        model.updateCompletion(
          key,
          completionStatuses[Math.floor(random() * completionStatuses.length)],
        );
      } else {
        const obligation = randomObligation(random);
        const key = obligationKey(obligation);
        model.upsert(obligation);
        if (!keys.includes(key)) keys.push(key);
      }

      assertProperty4(model, randomFilters(random), seed, step);
    }
  }
});

test(`${validatesCriteria(['2.2', '2.4', 'Property 4'])} exempt days and out-of-scope snapshots contribute zero required details`, () => {
  const model = new StoreObligationModel();
  const base = {
    obligationDate: '2026-08-04',
    storeId: 10,
    staffId: 1,
    roleCode: 'sales',
    requiredStatus: 'required',
    completionStatus: 'missing',
  };
  model.upsert(base);
  model.upsert({ ...base, staffId: 2, completionStatus: 'submitted' });
  model.upsert({ ...base, staffId: 3, requiredStatus: 'exempt' });
  model.upsert({ ...base, staffId: 4, storeId: 20 });
  model.upsert({ ...base, staffId: 5, roleCode: 'coach' });
  model.upsert({ ...base, staffId: 6, obligationDate: '2026-08-03' });

  const filters = {
    dateFrom: '2026-08-04',
    dateTo: '2026-08-04',
    storeIds: [10],
    staffIds: [],
    roleCodes: ['sales'],
    completionStatuses: [],
  };

  assert.deepEqual(model.storeSummary(filters), [{ storeId: 10, requiredCount: 2 }]);
  assert.equal(model.details(filters).length, 2);
  assertProperty4(model, filters, 'snapshot', 'complete');
});

test(`${validatesCriteria(['2.2', '2.4', 'Property 4'])} empty and disjoint store scopes preserve required-detail conservation`, () => {
  const model = new StoreObligationModel();
  const filters = {
    dateFrom: '2026-08-01',
    dateTo: '2026-08-31',
    storeIds: [99],
    staffIds: [],
    roleCodes: [],
    completionStatuses: [],
  };

  assert.deepEqual(model.storeSummary(filters), []);
  assert.deepEqual(model.details(filters), []);
  assertProperty4(model, filters, 'empty', 'complete');
});

test(`${validatesCriteria(['2.2', '2.4', 'Property 4'])} production schema defines required obligation snapshots and uniqueness`, () => {
  assert.match(migration, /CREATE TABLE IF NOT EXISTS workload_submission_obligations/);
  assert.match(migration, /obligation_date DATE NOT NULL/);
  assert.match(migration, /store_id BIGINT UNSIGNED NOT NULL/);
  assert.match(migration, /staff_id BIGINT UNSIGNED NOT NULL/);
  assert.match(migration, /role_code VARCHAR\(32\) NOT NULL/);
  assert.match(migration, /required_status VARCHAR\(16\) NOT NULL DEFAULT 'required'/);
  assert.match(migration, /completion_status VARCHAR\(24\) NOT NULL DEFAULT 'missing'/);
  assert.match(
    migration,
    /UNIQUE KEY uq_workload_submission_obligation \(obligation_date, store_id, staff_id, role_code\)/,
  );
  assert.match(obligationService, /'required_count' => \$isWeeklyRestDay \? 0 : count\(\$assignments\)/);
});
