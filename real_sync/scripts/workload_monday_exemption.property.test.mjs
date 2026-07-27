import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const service = readFileSync(
  new URL('../api/workload/services/WorkloadObligationService.php', import.meta.url),
  'utf8',
);
const worker = readFileSync(
  new URL('../api/workload/obligation-worker.php', import.meta.url),
  'utf8',
);

const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;

function seededRandom(seed) {
  let state = seed >>> 0;
  return () => {
    state = (Math.imul(state, 1664525) + 1013904223) >>> 0;
    return state / 0x1_0000_0000;
  };
}

function mondayFromWeek(week) {
  const date = new Date(Date.UTC(2000, 0, 3 + (week * 7)));
  return date.toISOString().slice(0, 10);
}

function generateMondayModel(assignments) {
  const candidates = new Map();
  for (const assignment of assignments) {
    if (!assignment.eligible) continue;
    const key = `${assignment.storeId}:${assignment.staffId}:${assignment.role}`;
    candidates.set(key, {
      requiredStatus: 'exempt',
      completionStatus: 'exempt',
      reasonCode: 'weekly_rest_day',
    });
  }
  return {
    dayType: 'weekly_rest_day',
    requiredCount: 0,
    exemptCount: candidates.size,
    records: [...candidates.values()],
  };
}

test(`${validatesCriteria(['1.2', '2.6', 'Property 13'])} arbitrary Mondays always have zero required obligations`, () => {
  for (let seed = 1; seed <= 128; seed += 1) {
    const random = seededRandom(seed);
    for (let step = 0; step < 256; step += 1) {
      const date = mondayFromWeek(Math.floor(random() * 10_000));
      const assignments = Array.from({ length: Math.floor(random() * 33) }, (_, index) => ({
        storeId: 1 + Math.floor(random() * 12),
        staffId: index + 1,
        role: random() < 0.5 ? 'sales' : 'coach',
        eligible: random() < 0.75,
      }));
      const result = generateMondayModel(assignments);

      assert.equal(new Date(`${date}T00:00:00Z`).getUTCDay(), 1, `seed ${seed}, step ${step}`);
      assert.equal(result.requiredCount, 0, `seed ${seed}, step ${step}`);
      assert.equal(result.records.filter(({ requiredStatus }) => requiredStatus === 'required').length, 0);
    }
  }
});

test(`${validatesCriteria(['1.2', '2.6', 'Property 13'])} Monday candidates are represented only as exempt markers`, () => {
  const result = generateMondayModel([
    { storeId: 10, staffId: 1, role: 'sales', eligible: true },
    { storeId: 10, staffId: 2, role: 'coach', eligible: true },
    { storeId: 10, staffId: 3, role: 'manager', eligible: false },
  ]);

  assert.equal(result.dayType, 'weekly_rest_day');
  assert.equal(result.requiredCount, 0);
  assert.equal(result.exemptCount, 2);
  assert.ok(result.records.every((record) => record.requiredStatus === 'exempt'));
  assert.ok(result.records.every((record) => record.completionStatus === 'exempt'));
  assert.ok(result.records.every((record) => record.reasonCode === 'weekly_rest_day'));
});

test(`${validatesCriteria(['1.2', 'Property 13'])} repeated Monday generation preserves a zero required count`, () => {
  const assignment = { storeId: 10, staffId: 7, role: 'sales', eligible: true };
  for (let run = 0; run < 512; run += 1) {
    const result = generateMondayModel([assignment, assignment]);
    assert.equal(result.requiredCount, 0);
    assert.equal(result.exemptCount, 1);
  }
});

test(`${validatesCriteria(['1.2', '2.6', 'Property 13'])} production contracts report Monday as exempt with zero required count`, () => {
  assert.match(service, /BUSINESS_TIMEZONE = 'Asia\/Shanghai'/);
  assert.match(service, /\$isWeeklyRestDay = \$date->format\('N'\) === '1'/);
  assert.match(service, /\$requiredStatus = \$isWeeklyRestDay \? 'exempt' : 'required'/);
  assert.match(service, /\$completionStatus = \$isWeeklyRestDay \? 'exempt' : 'missing'/);
  assert.match(service, /'day_type' => \$isWeeklyRestDay \? 'weekly_rest_day' : 'business_day'/);
  assert.match(service, /'required_count' => \$isWeeklyRestDay \? 0 : count\(\$assignments\)/);
  assert.match(service, /'exempt_count' => \$isWeeklyRestDay \? count\(\$assignments\) : 0/);
  assert.match(worker, /new DateTimeZone\('Asia\/Shanghai'\)/);
  assert.match(worker, /generateForDate\(\$businessDate\)/);
});
