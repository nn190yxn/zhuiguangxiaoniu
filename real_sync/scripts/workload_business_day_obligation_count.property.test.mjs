import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const service = readFileSync(
  new URL('../api/workload/services/WorkloadObligationService.php', import.meta.url),
  'utf8',
);

const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;
const roleAliases = {
  sales: ['sales', 'sale', 'consultant', '销售', '实习销售'],
  coach: ['coach', '教练', '实习教练'],
  manager: ['manager', 'store_manager', 'shop_manager', '店长'],
};

function seededRandom(seed) {
  let state = seed >>> 0;
  return () => {
    state = (Math.imul(state, 1664525) + 1013904223) >>> 0;
    return state / 0x1_0000_0000;
  };
}

function normalizeRole(role) {
  const value = String(role).trim().toLowerCase();
  if (roleAliases.sales.includes(value)) return 'sales';
  if (roleAliases.coach.includes(value)) return 'coach';
  if (roleAliases.manager.includes(value)) return 'manager';
  return value;
}

function addDays(date, days) {
  const value = new Date(`${date}T00:00:00Z`);
  value.setUTCDate(value.getUTCDate() + days);
  return value.toISOString().slice(0, 10);
}

function businessDate(random) {
  const monday = new Date(Date.UTC(2000, 0, 3 + (Math.floor(random() * 10_000) * 7)));
  monday.setUTCDate(monday.getUTCDate() + 1 + Math.floor(random() * 6));
  return monday.toISOString().slice(0, 10);
}

function isEligible(date, assignment) {
  const role = normalizeRole(assignment.role);
  const effective = assignment.startDate <= date
    && (assignment.endDate === null || assignment.endDate >= date);
  const staffEligible = (
    assignment.accountEnabled
    && assignment.lifecycleStatus === 'active'
  ) || (
    assignment.lifecycleStatus === 'offboarded'
    && assignment.offboardedAt !== null
    && assignment.offboardedAt >= date
  );
  return effective
    && staffEligible
    && assignment.storeActive
    && assignment.positionActive
    && ['sales', 'coach', 'manager'].includes(role);
}

function generateBusinessDayModel(date, assignments) {
  const candidates = new Map();
  for (const assignment of assignments) {
    if (!isEligible(date, assignment)) continue;
    const role = normalizeRole(assignment.role);
    const key = `${date}:${assignment.storeId}:${assignment.staffId}:${role}`;
    candidates.set(key, { key, requiredStatus: 'required', completionStatus: 'missing' });
  }
  const obligations = [...candidates.values()];
  return {
    candidateCount: candidates.size,
    requiredCount: obligations.filter(({ requiredStatus }) => requiredStatus === 'required').length,
    obligations,
  };
}

function randomAssignment(random, date) {
  const normalizedRole = random() < 0.4 ? 'sales' : (random() < 0.75 ? 'coach' : 'manager');
  const aliases = roleAliases[normalizedRole] ?? [normalizedRole];
  const lifecycleStatus = ['active', 'inactive', 'offboarded'][Math.floor(random() * 3)];
  const startOffset = Math.floor(random() * 9) - 5;
  const endOffset = random() < 0.3 ? null : Math.floor(random() * 9) - 3;
  return {
    storeId: 1 + Math.floor(random() * 6),
    staffId: 1 + Math.floor(random() * 24),
    role: aliases[Math.floor(random() * aliases.length)],
    startDate: addDays(date, startOffset),
    endDate: endOffset === null ? null : addDays(date, endOffset),
    accountEnabled: random() < 0.8,
    lifecycleStatus,
    offboardedAt: lifecycleStatus === 'offboarded'
      ? addDays(date, Math.floor(random() * 7) - 3)
      : null,
    storeActive: random() < 0.85,
    positionActive: random() < 0.85,
  };
}

test(`${validatesCriteria(['1.3', '2.3', 'Property 14'])} arbitrary business days have one required obligation per applicable candidate`, () => {
  for (let seed = 1; seed <= 128; seed += 1) {
    const random = seededRandom(seed);
    for (let step = 0; step < 256; step += 1) {
      const date = businessDate(random);
      const assignments = Array.from(
        { length: Math.floor(random() * 49) },
        () => randomAssignment(random, date),
      );
      if (assignments.length > 0 && random() < 0.4) {
        assignments.push(structuredClone(assignments[Math.floor(random() * assignments.length)]));
      }
      const result = generateBusinessDayModel(date, assignments);

      assert.notEqual(new Date(`${date}T00:00:00Z`).getUTCDay(), 1, `seed ${seed}, step ${step}`);
      assert.equal(result.requiredCount, result.candidateCount, `seed ${seed}, step ${step}`);
      assert.equal(new Set(result.obligations.map(({ key }) => key)).size, result.candidateCount);
    }
  }
});

test(`${validatesCriteria(['1.3', '2.3', 'Property 14'])} effective-date and lifecycle boundaries determine the exact required count`, () => {
  const date = '2026-07-28';
  const base = {
    storeId: 10,
    role: 'sales',
    startDate: date,
    endDate: date,
    accountEnabled: true,
    lifecycleStatus: 'active',
    offboardedAt: null,
    storeActive: true,
    positionActive: true,
  };
  const result = generateBusinessDayModel(date, [
    { ...base, staffId: 1 },
    { ...base, staffId: 2, lifecycleStatus: 'offboarded', offboardedAt: date, accountEnabled: false },
    { ...base, staffId: 3, startDate: addDays(date, 1) },
    { ...base, staffId: 4, endDate: addDays(date, -1) },
    { ...base, staffId: 5, accountEnabled: false },
    { ...base, staffId: 6, storeActive: false },
    { ...base, staffId: 7, positionActive: false },
    { ...base, staffId: 8, role: 'manager' },
  ]);

  assert.equal(result.candidateCount, 3);
  assert.equal(result.requiredCount, 3);
});

test(`${validatesCriteria(['1.3', '2.3', 'Property 14'])} aliases and duplicate assignments collapse while distinct duties remain required`, () => {
  const date = '2026-07-28';
  const base = {
    storeId: 10,
    staffId: 7,
    startDate: date,
    endDate: null,
    accountEnabled: true,
    lifecycleStatus: 'active',
    offboardedAt: null,
    storeActive: true,
    positionActive: true,
  };
  const result = generateBusinessDayModel(date, [
    ...roleAliases.sales.map((role) => ({ ...base, role })),
    { ...base, role: 'coach' },
    { ...base, storeId: 20, role: 'sales' },
  ]);

  assert.equal(result.candidateCount, 3);
  assert.equal(result.requiredCount, 3);
});

test(`${validatesCriteria(['1.3', '2.3', 'Property 14'])} production contracts derive required count from deduplicated eligible assignments`, () => {
  assert.match(service, /ELIGIBLE_ROLES = \['sales', 'coach', 'manager'\]/);
  assert.match(service, /assignment\.start_date <= \?/);
  assert.match(service, /assignment\.end_date IS NULL OR assignment\.end_date >= \?/);
  assert.match(service, /staff\.status = 1 AND staff\.lifecycle_status = 'active'/);
  assert.match(service, /staff\.lifecycle_status = 'offboarded'/);
  assert.match(service, /store\.status = 1/);
  assert.match(service, /position\.status = 1/);
  assert.match(service, /\$eligible\[\$key\] = \[/);
  assert.match(service, /'candidate_count' => count\(\$assignments\)/);
  assert.match(service, /'required_count' => \$isWeeklyRestDay \? 0 : count\(\$assignments\)/);
});
