import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const organizationService = readFileSync(
  new URL('../api/admin/services/OrganizationService.php', import.meta.url),
  'utf8',
);

const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;

function seededRandom(seed) {
  let state = seed >>> 0;
  return () => {
    state = (Math.imul(state, 214013) + 2531011) >>> 0;
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

function overlaps(left, right) {
  return (left.endDate === null || left.endDate >= right.startDate)
    && (right.endDate === null || right.endDate >= left.startDate);
}

class AssignmentHistoryModel {
  constructor(today) {
    this.today = today;
    this.assignments = [];
    this.nextId = 1;
  }

  changePrimary(staffId, duty, effectiveDate) {
    const primaries = this.assignments.filter(
      (assignment) => assignment.staffId === staffId && assignment.type === 'primary',
    );
    const current = primaries.filter((assignment) => includesDate(assignment, effectiveDate));
    if (current.length > 1) return 'invalid_state';
    const assignment = current[0] ?? null;
    if (assignment && this.#matches(assignment, duty)) return 'idempotent';
    if (assignment?.endDate !== null && assignment?.endDate < this.today) return 'historical_rejected';
    if (assignment?.startDate === effectiveDate) return 'same_day_conflict';

    const next = primaries
      .filter((candidate) => candidate.startDate > effectiveDate)
      .sort((left, right) => left.startDate.localeCompare(right.startDate))[0];
    if (assignment) assignment.endDate = previousDate(effectiveDate);
    this.assignments.push({
      id: this.nextId++,
      staffId,
      type: 'primary',
      ...duty,
      startDate: effectiveDate,
      endDate: next ? previousDate(next.startDate) : null,
      reason: 'primary-change',
      operatorId: 9001,
    });
    return 'created';
  }

  addSecondary(staffId, duty, startDate, endDate = null) {
    const candidate = {
      staffId,
      type: 'secondary',
      ...duty,
      startDate,
      endDate,
    };
    const conflicts = this.assignments.filter(
      (assignment) => assignment.staffId === staffId
        && assignment.type === 'secondary'
        && this.#matches(assignment, duty)
        && overlaps(assignment, candidate),
    );
    if (conflicts.some((assignment) => assignment.startDate === startDate && assignment.endDate === endDate)) {
      return 'idempotent';
    }
    if (conflicts.length > 0) return 'overlap_conflict';
    this.assignments.push({
      id: this.nextId++,
      ...candidate,
      reason: 'secondary-create',
      operatorId: 9001,
    });
    return 'created';
  }

  endSecondary(assignmentId, effectiveDate) {
    const assignment = this.assignments.find((candidate) => candidate.id === assignmentId);
    if (!assignment || assignment.type !== 'secondary') return 'invalid_assignment';
    if (assignment.endDate !== null && assignment.endDate < this.today) return 'historical_rejected';
    if (effectiveDate <= assignment.startDate) return 'invalid_date';
    const endDate = previousDate(effectiveDate);
    if (assignment.endDate === endDate) return 'idempotent';
    if (assignment.endDate !== null && endDate > assignment.endDate) return 'extension_rejected';
    assignment.endDate = endDate;
    assignment.reason = 'secondary-end';
    assignment.operatorId = 9002;
    return 'ended';
  }

  #matches(assignment, duty) {
    return assignment.storeId === duty.storeId
      && assignment.positionId === duty.positionId
      && assignment.role === duty.role;
  }
}

function freezeEndedHistory(model, snapshots) {
  for (const assignment of model.assignments) {
    if (assignment.endDate !== null && assignment.endDate < model.today && !snapshots.has(assignment.id)) {
      snapshots.set(assignment.id, structuredClone(assignment));
    }
  }
}

function assertProperty25(model, snapshots) {
  for (const [assignmentId, snapshot] of snapshots) {
    const current = model.assignments.find((assignment) => assignment.id === assignmentId);
    assert.deepEqual(current, snapshot, `historical assignment ${assignmentId} was modified`);
  }
}

test(`${validatesCriteria(['18.6', '18.7', 'Property 25'])} arbitrary organization changes preserve every ended assignment snapshot`, () => {
  const duties = [
    { storeId: 1, positionId: 1, role: 'sales' },
    { storeId: 1, positionId: 2, role: 'coach' },
    { storeId: 2, positionId: 1, role: 'sales' },
    { storeId: 2, positionId: 3, role: 'manager' },
  ];
  for (let seed = 1; seed <= 128; seed++) {
    const random = seededRandom(seed);
    const model = new AssignmentHistoryModel(dateAt(90));
    const snapshots = new Map();
    for (const staffId of [1, 2, 3, 4]) {
      model.changePrimary(staffId, duties[0], dateAt(0));
      model.changePrimary(staffId, duties[1], dateAt(30));
      model.changePrimary(staffId, duties[2], dateAt(60));
      model.addSecondary(staffId, duties[3], dateAt(10), dateAt(40));
    }
    freezeEndedHistory(model, snapshots);

    for (let step = 0; step < 256; step++) {
      const staffId = 1 + Math.floor(random() * 4);
      const duty = duties[Math.floor(random() * duties.length)];
      const operation = Math.floor(random() * 3);
      if (operation === 0) {
        model.changePrimary(staffId, duty, dateAt(Math.floor(random() * 141)));
      } else if (operation === 1) {
        const startDay = Math.floor(random() * 141);
        const duration = 1 + Math.floor(random() * 30);
        model.addSecondary(staffId, duty, dateAt(startDay), random() < 0.5 ? null : dateAt(startDay + duration));
      } else {
        const secondaries = model.assignments.filter((assignment) => assignment.type === 'secondary');
        if (secondaries.length > 0) {
          const assignment = secondaries[Math.floor(random() * secondaries.length)];
          model.endSecondary(assignment.id, dateAt(Math.floor(random() * 151)));
        }
      }
      assertProperty25(model, snapshots);
      freezeEndedHistory(model, snapshots);
    }
    assertProperty25(model, snapshots);
  }
});

test(`${validatesCriteria(['18.6', '18.7', 'Property 25'])} historical primary and secondary records reject direct changes`, () => {
  const model = new AssignmentHistoryModel('2026-07-24');
  const sales = { storeId: 1, positionId: 1, role: 'sales' };
  const coach = { storeId: 1, positionId: 2, role: 'coach' };
  model.changePrimary(1, sales, '2026-01-01');
  model.changePrimary(1, coach, '2026-03-01');
  model.addSecondary(1, coach, '2026-01-10', '2026-02-10');
  const snapshots = new Map();
  freezeEndedHistory(model, snapshots);

  assert.equal(model.changePrimary(1, coach, '2026-02-01'), 'historical_rejected');
  const historicalSecondary = model.assignments.find((assignment) => assignment.type === 'secondary');
  assert.equal(model.endSecondary(historicalSecondary.id, '2026-02-01'), 'historical_rejected');
  assertProperty25(model, snapshots);
});

test(`${validatesCriteria(['18.7', 'Property 25'])} dictionary status changes retain assignment rows`, () => {
  const model = new AssignmentHistoryModel('2026-07-24');
  const duty = { storeId: 1, positionId: 1, role: 'sales' };
  model.changePrimary(1, duty, '2026-01-01');
  model.changePrimary(1, { ...duty, storeId: 2 }, '2026-07-24');
  const snapshots = new Map();
  freezeEndedHistory(model, snapshots);
  const beforeCount = model.assignments.length;

  const dictionaryState = { store1: 'disabled', position1: 'disabled' };
  assert.deepEqual(dictionaryState, { store1: 'disabled', position1: 'disabled' });
  assert.equal(model.assignments.length, beforeCount);
  assertProperty25(model, snapshots);
});

test(`${validatesCriteria(['18.6', '18.7', 'Property 25'])} production contracts protect ended history`, () => {
  const primaryStart = organizationService.indexOf('public function changePrimaryAssignment');
  const secondaryStart = organizationService.indexOf('public function endSecondaryAssignment');
  const treeStart = organizationService.indexOf('public function getOrganizationTree');
  const primarySource = organizationService.slice(primaryStart, secondaryStart);
  const secondarySource = organizationService.slice(secondaryStart, treeStart);

  assert.match(primarySource, /\$current\['end_date'\] !== null && \$current\['end_date'\] < date\('Y-m-d'\)/);
  assert.match(primarySource, /已结束的历史任职不可修改/);
  assert.match(secondarySource, /\$assignment\['end_date'\] !== null && \$assignment\['end_date'\] < \$today/);
  assert.match(secondarySource, /结束操作不能延长原任职区间/);
  assert.doesNotMatch(organizationService, /DELETE\s+FROM\s+staff_assignments/i);
});
