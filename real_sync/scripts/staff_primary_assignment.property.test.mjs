import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const organizationService = readFileSync(
  new URL('../api/admin/services/OrganizationService.php', import.meta.url),
  'utf8',
);
const organizationMigration = readFileSync(
  new URL('../database/migrations/202607240001_staff_organization.sql', import.meta.url),
  'utf8',
);

const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;

function seededRandom(seed) {
  let state = seed >>> 0;
  return () => {
    state = (Math.imul(state, 22695477) + 1) >>> 0;
    return state / 0x100000000;
  };
}

function dateAt(day) {
  const value = new Date('2026-01-01T00:00:00Z');
  value.setUTCDate(value.getUTCDate() + day);
  return value.toISOString().slice(0, 10);
}

function previousDate(date) {
  const value = new Date(`${date}T00:00:00Z`);
  value.setUTCDate(value.getUTCDate() - 1);
  return value.toISOString().slice(0, 10);
}

function includesDate(assignment, date) {
  return assignment.startDate <= date && (assignment.endDate === null || assignment.endDate >= date);
}

class PrimaryAssignmentModel {
  constructor(today) {
    this.today = today;
    this.assignments = [];
    this.nextId = 1;
  }

  change(staffId, duty, effectiveDate) {
    const staffAssignments = this.assignments.filter((assignment) => assignment.staffId === staffId);
    const effective = staffAssignments.filter((assignment) => includesDate(assignment, effectiveDate));
    assert.ok(effective.length <= 1, 'model entered an invalid primary state');
    const current = effective[0] ?? null;

    if (current && current.storeId === duty.storeId && current.positionId === duty.positionId && current.role === duty.role) {
      return { status: 'idempotent', id: current.id };
    }
    if (current?.endDate !== null && current?.endDate < this.today) {
      return { status: 'historical_rejected' };
    }
    if (current?.startDate === effectiveDate) {
      return { status: 'same_day_conflict' };
    }

    const next = staffAssignments
      .filter((assignment) => assignment.startDate > effectiveDate)
      .sort((left, right) => left.startDate.localeCompare(right.startDate))[0];
    if (current) current.endDate = previousDate(effectiveDate);
    const assignment = {
      id: this.nextId++,
      staffId,
      ...duty,
      startDate: effectiveDate,
      endDate: next ? previousDate(next.startDate) : null,
    };
    this.assignments.push(assignment);
    return { status: 'created', id: assignment.id };
  }
}

function assertProperty21(model, staffIds, firstDay = -10, lastDay = 140) {
  for (const staffId of staffIds) {
    const assignments = model.assignments.filter((assignment) => assignment.staffId === staffId);
    for (let day = firstDay; day <= lastDay; day++) {
      const date = dateAt(day);
      const effective = assignments.filter((assignment) => includesDate(assignment, date));
      assert.ok(
        effective.length <= 1,
        `staff ${staffId} has ${effective.length} primary assignments on ${date}`,
      );
    }
  }
}

test(`${validatesCriteria(['18.6', '18.8', 'Property 21'])} arbitrary primary changes preserve one effective primary per business date`, () => {
  const staffIds = [1, 2, 3, 4];
  const duties = [
    { storeId: 1, positionId: 1, role: 'sales' },
    { storeId: 1, positionId: 2, role: 'coach' },
    { storeId: 2, positionId: 1, role: 'sales' },
    { storeId: 2, positionId: 3, role: 'manager' },
  ];

  for (let seed = 1; seed <= 128; seed++) {
    const random = seededRandom(seed);
    const model = new PrimaryAssignmentModel(dateAt(60));
    for (let step = 0; step < 256; step++) {
      const staffId = staffIds[Math.floor(random() * staffIds.length)];
      const duty = duties[Math.floor(random() * duties.length)];
      const effectiveDate = dateAt(Math.floor(random() * 121));
      model.change(staffId, duty, effectiveDate);
      assertProperty21(model, [staffId]);
    }
    assertProperty21(model, staffIds);
  }
});

test(`${validatesCriteria(['18.6', '18.8', 'Property 21'])} closed boundaries keep adjacent primary ranges disjoint`, () => {
  const model = new PrimaryAssignmentModel('2026-07-24');
  model.change(7, { storeId: 1, positionId: 1, role: 'sales' }, '2026-07-01');
  model.change(7, { storeId: 2, positionId: 2, role: 'coach' }, '2026-07-24');

  assert.deepEqual(model.assignments.map(({ startDate, endDate }) => ({ startDate, endDate })), [
    { startDate: '2026-07-01', endDate: '2026-07-23' },
    { startDate: '2026-07-24', endDate: null },
  ]);
  assertProperty21(model, [7], 170, 230);
});

test(`${validatesCriteria(['18.6', 'Property 21'])} same-duty repeats and same-day conflicts leave ranges unchanged`, () => {
  const model = new PrimaryAssignmentModel('2026-07-24');
  const sales = { storeId: 1, positionId: 1, role: 'sales' };
  const coach = { storeId: 1, positionId: 2, role: 'coach' };
  assert.equal(model.change(9, sales, '2026-07-24').status, 'created');
  const snapshot = structuredClone(model.assignments);
  assert.equal(model.change(9, sales, '2026-07-24').status, 'idempotent');
  assert.equal(model.change(9, coach, '2026-07-24').status, 'same_day_conflict');
  assert.deepEqual(model.assignments, snapshot);
  assertProperty21(model, [9]);
});

test(`${validatesCriteria(['18.6', '18.8', 'Property 21'])} production contracts serialize and bound primary changes`, () => {
  assert.match(organizationMigration, /idx_staff_assignments_staff_effective \(staff_id, start_date, end_date, assignment_type\)/);
  assert.match(organizationService, /function lockStaffAssignments\(int \$staffId\): array/);
  assert.match(organizationService, /FROM staff_assignments WHERE staff_id = \? ORDER BY start_date ASC, id ASC FOR UPDATE/);
  assert.match(organizationService, /count\(\$effectiveAssignments\) > 1/);
  assert.match(organizationService, /\$this->previousDate\(\$data\['start_date'\]\)/);
  assert.match(organizationService, /\$newEndDate = \$nextPrimary === null \? null : \$this->previousDate\(\$nextPrimary\['start_date'\]\)/);
});
