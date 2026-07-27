import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const obligationService = readFileSync(
  new URL('../api/workload/services/WorkloadObligationService.php', import.meta.url),
  'utf8',
);
const reportStateService = readFileSync(
  new URL('../api/workload/services/WorkloadReportStateService.php', import.meta.url),
  'utf8',
);
const migration = readFileSync(
  new URL('../database/migrations/202607240002_workload_governance.sql', import.meta.url),
  'utf8',
);

const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;
const roleAliases = {
  sales: ['sales', 'sale', 'consultant', '销售', '实习销售'],
  coach: ['coach', '教练', '实习教练'],
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
  return value;
}

function obligationKey({ date, storeId, staffId, role }) {
  return `${date}:${storeId}:${staffId}:${normalizeRole(role)}`;
}

class ObligationModel {
  rows = [];

  generate(date, assignments) {
    const candidates = new Map();
    for (const assignment of assignments) {
      const role = normalizeRole(assignment.role);
      if (!['sales', 'coach'].includes(role)) continue;
      const candidate = { date, ...assignment, role };
      candidates.set(obligationKey(candidate), candidate);
    }

    for (const candidate of candidates.values()) this.upsert(candidate, 'generated');
  }

  synchronizeReport(report) {
    this.upsert(report, 'report');
  }

  upsert(candidate, source) {
    const key = obligationKey(candidate);
    const existing = this.rows.find((row) => obligationKey(row) === key);
    if (existing) {
      existing.source = source;
      return;
    }
    this.rows.push({ ...candidate, source });
  }
}

function assertProperty1(model, seed, step) {
  const counts = new Map();
  for (const row of model.rows) {
    const key = obligationKey(row);
    counts.set(key, (counts.get(key) ?? 0) + 1);
  }
  for (const [key, count] of counts) {
    assert.ok(count <= 1, `seed ${seed}, step ${step}: ${key} has ${count} obligations`);
  }
}

function randomObligation(random) {
  const normalizedRole = random() < 0.65 ? 'sales' : 'coach';
  const aliases = roleAliases[normalizedRole];
  return {
    date: `2026-08-${String(1 + Math.floor(random() * 14)).padStart(2, '0')}`,
    storeId: 1 + Math.floor(random() * 5),
    staffId: 1 + Math.floor(random() * 24),
    role: aliases[Math.floor(random() * aliases.length)],
  };
}

test(`${validatesCriteria(['1.1', 'Property 1'])} arbitrary obligation writes preserve one row per date, store, staff, and role`, () => {
  for (let seed = 1; seed <= 128; seed += 1) {
    const random = seededRandom(seed);
    const model = new ObligationModel();
    const history = [];

    for (let step = 0; step < 256; step += 1) {
      const replay = history.length > 0 && random() < 0.35;
      const obligation = replay
        ? structuredClone(history[Math.floor(random() * history.length)])
        : randomObligation(random);
      if (!replay) history.push(structuredClone(obligation));

      if (random() < 0.6) {
        const aliases = roleAliases[normalizeRole(obligation.role)];
        model.generate(obligation.date, [
          obligation,
          { ...obligation, role: aliases[Math.floor(random() * aliases.length)] },
        ]);
      } else {
        model.synchronizeReport(obligation);
      }
      assertProperty1(model, seed, step);
    }
  }
});

test(`${validatesCriteria(['1.1', 'Property 1'])} role aliases and repeated generation collapse into one obligation`, () => {
  const model = new ObligationModel();
  const base = { date: '2026-08-04', storeId: 3, staffId: 9 };

  for (const role of roleAliases.sales) model.generate(base.date, [{ ...base, role }]);
  for (const role of roleAliases.coach) model.synchronizeReport({ ...base, role });
  model.generate(base.date, roleAliases.sales.map((role) => ({ ...base, role })));

  assert.equal(model.rows.length, 2);
  assert.deepEqual(model.rows.map((row) => normalizeRole(row.role)).sort(), ['coach', 'sales']);
  assertProperty1(model, 'aliases', 'complete');
});

test(`${validatesCriteria(['1.1', 'Property 1'])} each uniqueness dimension creates an independent obligation`, () => {
  const model = new ObligationModel();
  const base = { date: '2026-08-04', storeId: 3, staffId: 9, role: 'sales' };
  const obligations = [
    base,
    { ...base, date: '2026-08-05' },
    { ...base, storeId: 4 },
    { ...base, staffId: 10 },
    { ...base, role: 'coach' },
  ];

  for (const obligation of obligations) model.synchronizeReport(obligation);

  assert.equal(model.rows.length, obligations.length);
  assertProperty1(model, 'dimensions', 'complete');
});

test(`${validatesCriteria(['1.1', 'Property 1'])} production contracts enforce normalized and database uniqueness`, () => {
  assert.match(
    migration,
    /UNIQUE KEY uq_workload_submission_obligation \(obligation_date, store_id, staff_id, role_code\)/,
  );
  assert.match(obligationService, /\$eligible\[\$key\] = \[/);
  assert.match(obligationService, /appRoleCode\(\$roleCode\)/);
  assert.match(obligationService, /\$storedRoleCode = \$existingKeys\[\$key\]/);
  assert.match(obligationService, /ON DUPLICATE KEY UPDATE/);
  assert.match(reportStateService, /WHERE obligation_date = \? AND store_id = \? AND staff_id = \? ORDER BY id ASC/);
  assert.match(reportStateService, /appRoleCode\(\(string\) \$row\['role_code'\]\) === \$normalizedRole/);
  assert.match(reportStateService, /\$sql \.= ' FOR UPDATE'/);
});
