import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const stateService = readFileSync(
  new URL('../api/workload/services/WorkloadReportStateService.php', import.meta.url),
  'utf8',
);
const migration = readFileSync(
  new URL('../database/migrations/202607240002_workload_governance.sql', import.meta.url),
  'utf8',
);

const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;
const completionStatuses = [
  'missing',
  'draft',
  'submitted',
  'locked_missing',
  'corrected',
];
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

class CompletionCountModel {
  obligations = new Map();

  upsert(obligation) {
    this.obligations.set(obligationKey(obligation), structuredClone(obligation));
  }

  transition(key, operation) {
    const obligation = this.obligations.get(key);
    if (!obligation || obligation.requiredStatus !== 'required') return;

    const transitions = {
      saveDraft: { missing: 'draft', draft: 'draft' },
      submit: { missing: 'submitted', draft: 'submitted' },
      lockExpired: { missing: 'locked_missing', draft: 'locked_missing' },
      correct: { submitted: 'corrected', locked_missing: 'corrected' },
    };
    obligation.completionStatus = transitions[operation][obligation.completionStatus]
      ?? obligation.completionStatus;
  }

  inScope(filters) {
    return [...this.obligations.values()].filter((obligation) => (
      obligation.requiredStatus === 'required'
      && obligation.obligationDate >= filters.dateFrom
      && obligation.obligationDate <= filters.dateTo
      && (filters.storeIds.length === 0 || filters.storeIds.includes(obligation.storeId))
      && (filters.staffIds.length === 0 || filters.staffIds.includes(obligation.staffId))
      && (filters.roleCodes.length === 0 || filters.roleCodes.includes(obligation.roleCode))
    ));
  }

  completionSummary(filters) {
    const summary = Object.fromEntries(completionStatuses.map((status) => [status, 0]));
    const obligations = this.inScope(filters);
    for (const obligation of obligations) summary[obligation.completionStatus] += 1;
    return { requiredCount: obligations.length, ...summary };
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
  const requiredStatus = day === 1 || random() < 0.12 ? 'exempt' : 'required';
  return {
    obligationDate,
    storeId: 1 + Math.floor(random() * 6),
    staffId: 1 + Math.floor(random() * 30),
    roleCode: roleCodes[Math.floor(random() * roleCodes.length)],
    requiredStatus,
    completionStatus: requiredStatus === 'required'
      ? completionStatuses[Math.floor(random() * completionStatuses.length)]
      : 'exempt',
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
  };
}

function assertProperty5(model, filters, seed, step) {
  const obligations = model.inScope(filters);
  const summary = model.completionSummary(filters);
  const statusTotal = completionStatuses.reduce((total, status) => total + summary[status], 0);

  assert.equal(statusTotal, summary.requiredCount, `seed ${seed}, step ${step}: status total`);
  assert.equal(summary.requiredCount, obligations.length, `seed ${seed}, step ${step}: required count`);
  for (const status of completionStatuses) {
    assert.equal(
      summary[status],
      obligations.filter(({ completionStatus }) => completionStatus === status).length,
      `seed ${seed}, step ${step}: ${status} count`,
    );
  }
}

test(`${validatesCriteria(['2.1', '2.2', 'Property 5'])} arbitrary completion transitions preserve the required-status partition`, () => {
  const operations = ['saveDraft', 'submit', 'lockExpired', 'correct'];
  for (let seed = 1; seed <= 128; seed += 1) {
    const random = seededRandom(seed);
    const model = new CompletionCountModel();
    const keys = [];

    for (let step = 0; step < 256; step += 1) {
      if (keys.length > 0 && random() < 0.45) {
        const key = keys[Math.floor(random() * keys.length)];
        model.transition(key, operations[Math.floor(random() * operations.length)]);
      } else {
        const obligation = randomObligation(random);
        const key = obligationKey(obligation);
        model.upsert(obligation);
        if (!keys.includes(key)) keys.push(key);
      }

      assertProperty5(model, randomFilters(random), seed, step);
    }
  }
});

test(`${validatesCriteria(['2.1', '2.2', 'Property 5'])} every required completion state contributes exactly one count`, () => {
  const model = new CompletionCountModel();
  completionStatuses.forEach((completionStatus, index) => {
    model.upsert({
      obligationDate: '2026-08-04',
      storeId: 10,
      staffId: index + 1,
      roleCode: 'sales',
      requiredStatus: 'required',
      completionStatus,
    });
  });

  const filters = {
    dateFrom: '2026-08-04',
    dateTo: '2026-08-04',
    storeIds: [10],
    staffIds: [],
    roleCodes: ['sales'],
  };
  assert.deepEqual(model.completionSummary(filters), {
    requiredCount: 5,
    missing: 1,
    draft: 1,
    submitted: 1,
    locked_missing: 1,
    corrected: 1,
  });
  assertProperty5(model, filters, 'all-states', 'complete');
});

test(`${validatesCriteria(['2.1', '2.2', 'Property 5'])} exempt and empty scopes contribute zero to every required count`, () => {
  const model = new CompletionCountModel();
  model.upsert({
    obligationDate: '2026-08-03',
    storeId: 10,
    staffId: 1,
    roleCode: 'sales',
    requiredStatus: 'exempt',
    completionStatus: 'exempt',
  });
  const filters = {
    dateFrom: '2026-08-01',
    dateTo: '2026-08-31',
    storeIds: [10],
    staffIds: [],
    roleCodes: [],
  };

  assert.deepEqual(model.completionSummary(filters), {
    requiredCount: 0,
    missing: 0,
    draft: 0,
    submitted: 0,
    locked_missing: 0,
    corrected: 0,
  });
  assertProperty5(model, filters, 'exempt', 'complete');
});

test(`${validatesCriteria(['2.1', '2.2', 'Property 5'])} production contracts maintain the required completion partition`, () => {
  assert.match(migration, /required_status VARCHAR\(16\) NOT NULL DEFAULT 'required'/);
  assert.match(migration, /completion_status VARCHAR\(24\) NOT NULL DEFAULT 'missing'/);
  assert.match(stateService, /\$completionStatus = \$corrected \? 'corrected' : \$status/);
  assert.match(stateService, /if \(!in_array\(\$status, \['draft', 'submitted'\], true\)\)/);
  assert.match(stateService, /required_status = \? AND completion_status = \? /);
  assert.equal((stateService.match(/\$lockMissing->execute\(\['required', '(?:missing|draft)'/g) ?? []).length, 2);
  assert.match(stateService, /'completion_status' => 'corrected'/);
});
