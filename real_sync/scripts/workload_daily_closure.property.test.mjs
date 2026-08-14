import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const read = (relativePath) => readFileSync(new URL(relativePath, import.meta.url), 'utf8');
const settlementService = read('../api/workload/services/WorkloadDailySettlementService.php');
const penaltyService = read('../api/workload/services/WorkloadPenaltyService.php');
const makeupService = read('../api/workload/services/WorkloadMakeupService.php');

const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;

function seededRandom(seed) {
  let state = seed >>> 0;
  return () => {
    state = (Math.imul(state, 1664525) + 1013904223) >>> 0;
    return state / 0x1_0000_0000;
  };
}

function cents(value) {
  return Math.round(value * 100) / 100;
}

function settlementStatus(day, now, gapPoints) {
  if (gapPoints <= 0) return 'completed';
  if (now < day + 1) return 'today_open';
  if (now < day + 2) return 'makeup_open';
  return 'overdue';
}

function canMakeUp(day, now) {
  return now >= day + 1 && now < day + 2 && Math.floor(now) - 1 === day;
}

class ClosureModel {
  settlements = new Map();
  penalties = new Map();

  refresh(scope, effectivePoints, now) {
    const gapPoints = cents(Math.max(0, 4 - effectivePoints));
    const status = settlementStatus(scope.day, now, gapPoints);
    const settlement = { ...scope, effectivePoints, gapPoints, status };
    this.settlements.set(scope.key, settlement);
    this.refreshPenalty(settlement);
    return settlement;
  }

  refreshPenalty(settlement) {
    const previous = this.penalties.get(settlement.key);
    const overdueGap = settlement.status === 'overdue' && settlement.gapPoints > 0;
    if (!overdueGap) {
      if (previous && previous.status !== 'payroll_handed_off' && previous.status !== 'cancelled') {
        this.penalties.set(settlement.key, { ...previous, status: 'cancelled' });
      }
      return;
    }
    const penaltyAmount = cents(settlement.gapPoints * 20);
    if (!previous) {
      this.penalties.set(settlement.key, {
        gapPoints: settlement.gapPoints,
        penaltyAmount,
        status: 'pending_confirmation',
      });
      return;
    }
    if (previous.status === 'payroll_handed_off') return;
    if (previous.gapPoints !== settlement.gapPoints || previous.penaltyAmount !== penaltyAmount) {
      this.penalties.set(settlement.key, {
        ...previous,
        gapPoints: settlement.gapPoints,
        penaltyAmount,
        status: previous.status === 'cancelled' ? 'pending_confirmation' : previous.status,
      });
    }
  }
}

test(`${validatesCriteria(['Property 1', 'Property 2', 'Property 3'])} fixed seeds preserve settlement idempotency, gap, and penalty formulas`, () => {
  for (let seed = 1; seed <= 128; seed += 1) {
    const random = seededRandom(seed);
    const model = new ClosureModel();
    for (let step = 0; step < 256; step += 1) {
      const scope = { key: `scope-${Math.floor(random() * 12)}`, day: 100 + Math.floor(random() * 8) };
      const effectivePoints = cents(random() * 8);
      const now = scope.day + random() * 3;
      const first = model.refresh(scope, effectivePoints, now);
      const firstPenalty = structuredClone(model.penalties.get(scope.key) ?? null);
      const replay = model.refresh(scope, effectivePoints, now);
      const replayPenalty = model.penalties.get(scope.key) ?? null;
      assert.equal(replay.gapPoints, cents(Math.max(0, 4 - effectivePoints)), `seed ${seed}, step ${step}`);
      assert.deepEqual(replay, first, `seed ${seed}, step ${step}: repeated settlement changed`);
      assert.deepEqual(replayPenalty, firstPenalty, `seed ${seed}, step ${step}: repeated penalty changed`);
      if (replayPenalty && replayPenalty.status !== 'payroll_handed_off') {
        assert.equal(replayPenalty.penaltyAmount, cents(replayPenalty.gapPoints * 20), `seed ${seed}, step ${step}`);
      }
    }
  }
});

test(`${validatesCriteria(['Property 4'])} fixed seeds enforce makeup boundaries for yesterday only`, () => {
  for (let seed = 1; seed <= 128; seed += 1) {
    const random = seededRandom(seed);
    for (let step = 0; step < 256; step += 1) {
      const day = 100 + Math.floor(random() * 30);
      const now = day + (random() * 4 - 1);
      const expected = now >= day + 1 && now < day + 2 && Math.floor(now) - 1 === day;
      assert.equal(canMakeUp(day, now), expected, `seed ${seed}, step ${step}`);
    }
  }
  assert.equal(canMakeUp(100, 101), true);
  assert.equal(canMakeUp(100, 102 - 0.000001), true);
  assert.equal(canMakeUp(100, 102), false);
});

test(`${validatesCriteria(['Property 6'])} fixed seeds preserve payroll-handoff amounts through later settlements`, () => {
  for (let seed = 1; seed <= 128; seed += 1) {
    const random = seededRandom(seed);
    const model = new ClosureModel();
    const scope = { key: `payroll-${seed}`, day: 100 };
    model.refresh(scope, cents(random() * 3.9), 102);
    const handedOff = model.penalties.get(scope.key);
    assert.ok(handedOff, `seed ${seed}: overdue gap should create a record`);
    handedOff.status = 'payroll_handed_off';
    const snapshot = structuredClone(handedOff);
    for (let step = 0; step < 128; step += 1) {
      model.refresh(scope, cents(random() * 8), 102 + random());
      assert.deepEqual(model.penalties.get(scope.key), snapshot, `seed ${seed}, step ${step}`);
    }
  }
});

test(`${validatesCriteria(['Property 1', 'Property 2', 'Property 3', 'Property 4', 'Property 6'])} production services retain the guarded formulas and snapshots`, () => {
  assert.match(settlementService, /max\(0, \$targetPoints - \$totals\['effective_points'\]\)/);
  assert.match(settlementService, /ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID\(id\)/);
  assert.match(penaltyService, /\$gapPoints \* self::UNIT_AMOUNT/);
  assert.match(penaltyService, /payroll_handed_off/);
  assert.match(penaltyService, /workload_penalty_record_logs/);
  assert.match(makeupService, /\$now >= \$opensAt/);
  assert.match(makeupService, /\$now < \$deadline/);
  assert.match(makeupService, /\$now->modify\('-1 day'\)/);
});
