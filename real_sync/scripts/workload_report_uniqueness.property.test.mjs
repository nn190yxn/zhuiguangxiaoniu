import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const common = readFileSync(new URL('../api/workload/_common.php', import.meta.url), 'utf8');
const saveReport = readFileSync(new URL('../api/workload/save-report.php', import.meta.url), 'utf8');

const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;
const roleAliases = {
  sales: ['sales', 'sale', 'consultant', '销售', '实习销售'],
  coach: ['coach', '教练', '实习教练'],
};

function seededRandom(seed) {
  let state = seed >>> 0;
  return () => {
    state = (Math.imul(state, 22695477) + 1) >>> 0;
    return state / 0x1_0000_0000;
  };
}

function normalizeRole(role) {
  const value = String(role).trim().toLowerCase();
  if (roleAliases.sales.includes(value)) return 'sales';
  if (roleAliases.coach.includes(value)) return 'coach';
  return value;
}

function reportKey({ date, storeId, staffId, role }) {
  return `${date}:${storeId}:${staffId}:${normalizeRole(role)}`;
}

class ReportModel {
  rows = [];
  nextId = 1;

  save(report) {
    const key = reportKey(report);
    const existing = this.rows.find((row) => reportKey(row) === key);
    if (existing) {
      if (existing.status === 'submitted') return { status: 'locked', id: existing.id };
      existing.status = report.status;
      existing.revision += 1;
      return { status: 'updated', id: existing.id };
    }

    const row = { ...report, role: normalizeRole(report.role), id: this.nextId++, revision: 1 };
    this.rows.push(row);
    return { status: 'created', id: row.id };
  }
}

function assertProperty2(model, seed, step) {
  const counts = new Map();
  for (const row of model.rows) {
    const key = reportKey(row);
    counts.set(key, (counts.get(key) ?? 0) + 1);
  }
  for (const [key, count] of counts) {
    assert.ok(count <= 1, `seed ${seed}, step ${step}: ${key} has ${count} reports`);
  }
}

function randomReport(random) {
  const normalizedRole = random() < 0.65 ? 'sales' : 'coach';
  const aliases = roleAliases[normalizedRole];
  return {
    date: `2026-08-${String(1 + Math.floor(random() * 14)).padStart(2, '0')}`,
    storeId: 1 + Math.floor(random() * 5),
    staffId: 1 + Math.floor(random() * 24),
    role: aliases[Math.floor(random() * aliases.length)],
    status: random() < 0.7 ? 'draft' : 'submitted',
  };
}

test(`${validatesCriteria(['1.1', 'Property 2'])} arbitrary saves preserve one report per date, store, staff, and role`, () => {
  for (let seed = 1; seed <= 128; seed += 1) {
    const random = seededRandom(seed);
    const model = new ReportModel();
    const history = [];

    for (let step = 0; step < 256; step += 1) {
      const replay = history.length > 0 && random() < 0.4;
      const report = replay
        ? structuredClone(history[Math.floor(random() * history.length)])
        : randomReport(random);
      if (!replay) history.push(structuredClone(report));

      const aliases = roleAliases[normalizeRole(report.role)];
      report.role = aliases[Math.floor(random() * aliases.length)];
      if (random() < 0.5) report.status = random() < 0.6 ? 'draft' : 'submitted';
      model.save(report);
      assertProperty2(model, seed, step);
    }
  }
});

test(`${validatesCriteria(['1.1', 'Property 2'])} drafts update in place and submitted reports keep a stable identity`, () => {
  const model = new ReportModel();
  const base = { date: '2026-08-04', storeId: 3, staffId: 9, role: 'sales', status: 'draft' };

  const created = model.save(base);
  const updated = model.save({ ...base, role: 'consultant' });
  const submitted = model.save({ ...base, role: '销售', status: 'submitted' });
  const locked = model.save({ ...base, role: 'sale', status: 'draft' });

  assert.deepEqual([created.id, updated.id, submitted.id, locked.id], [1, 1, 1, 1]);
  assert.deepEqual([created.status, updated.status, submitted.status, locked.status], [
    'created',
    'updated',
    'updated',
    'locked',
  ]);
  assert.equal(model.rows.length, 1);
  assertProperty2(model, 'lifecycle', 'complete');
});

test(`${validatesCriteria(['1.1', 'Property 2'])} each uniqueness dimension creates an independent report`, () => {
  const model = new ReportModel();
  const base = { date: '2026-08-04', storeId: 3, staffId: 9, role: 'sales', status: 'draft' };
  const reports = [
    base,
    { ...base, date: '2026-08-05' },
    { ...base, storeId: 4 },
    { ...base, staffId: 10 },
    { ...base, role: 'coach' },
  ];

  for (const report of reports) model.save(report);

  assert.equal(model.rows.length, reports.length);
  assertProperty2(model, 'dimensions', 'complete');
});

test(`${validatesCriteria(['1.1', 'Property 2'])} production contracts serialize and constrain report identity`, () => {
  assert.match(
    common,
    /UNIQUE KEY uk_report_unique \(report_date, store_id, staff_id, role_code\)/,
  );
  assert.match(saveReport, /\$role = appRoleCode\(appRequireString\(\$input, 'role_code', '岗位'\)\)/);
  assert.match(saveReport, /\$pdo->beginTransaction\(\)/);
  assert.match(
    saveReport,
    /WHERE report_date=\? AND store_id=\? AND staff_id=\? ORDER BY id ASC FOR UPDATE/,
  );
  assert.match(
    saveReport,
    /appRoleCode\(\(string\)\(\$candidate\['role_code'\] \?\? ''\)\) === \$role/,
  );
  assert.match(saveReport, /if \(\$existing\) \{/);
  assert.match(saveReport, /UPDATE workload_daily_reports SET/);
  assert.match(saveReport, /INSERT INTO workload_daily_reports/);
});
