import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const service = readFileSync(
  new URL('../api/admin/services/OrganizationService.php', import.meta.url),
  'utf8',
);

const previousDate = (date) => {
  const value = new Date(`${date}T00:00:00Z`);
  value.setUTCDate(value.getUTCDate() - 1);
  return value.toISOString().slice(0, 10);
};

const overlaps = (left, right) => (
  (left.endDate === null || right.startDate <= left.endDate)
  && (right.endDate === null || left.startDate <= right.endDate)
);

class AssignmentModel {
  #assignments = [];
  #nextId = 1;

  add(input) {
    const assignment = { id: this.#nextId++, endDate: null, ...input };
    this.#assignments.push(assignment);
    return structuredClone(assignment);
  }

  changePrimary(staffId, input) {
    const effective = this.#assignments.filter((assignment) => (
      assignment.staffId === staffId
      && assignment.type === 'primary'
      && assignment.startDate <= input.startDate
      && (assignment.endDate === null || assignment.endDate >= input.startDate)
    ));
    assert.ok(effective.length <= 1, 'one primary assignment can cover an effective date');
    const current = effective[0] ?? null;
    if (current && this.#sameDuty(current, input)) return structuredClone(current);
    assert.ok(!current || current.startDate !== input.startDate, 'different same-day primary conflicts');

    if (current) current.endDate = previousDate(input.startDate);
    const next = this.#assignments
      .filter((assignment) => (
        assignment.staffId === staffId
        && assignment.type === 'primary'
        && assignment.startDate > input.startDate
      ))
      .sort((left, right) => left.startDate.localeCompare(right.startDate))[0];

    return this.add({
      staffId,
      type: 'primary',
      ...input,
      endDate: next ? previousDate(next.startDate) : null,
    });
  }

  addSecondary(staffId, input) {
    const exactOrOverlapping = this.#assignments.filter((assignment) => (
      assignment.staffId === staffId
      && assignment.type === 'secondary'
      && this.#sameDuty(assignment, input)
      && overlaps(assignment, input)
    ));
    const exact = exactOrOverlapping.find((assignment) => (
      assignment.startDate === input.startDate && assignment.endDate === input.endDate
    ));
    if (exact) return structuredClone(exact);
    assert.equal(exactOrOverlapping.length, 0, 'matching secondary ranges cannot overlap');
    return this.add({ staffId, type: 'secondary', ...input });
  }

  endSecondary(id, effectiveDate, today) {
    const assignment = this.#assignments.find((item) => item.id === id);
    assert.equal(assignment?.type, 'secondary');
    assert.ok(assignment.endDate === null || assignment.endDate >= today, 'historical assignments are immutable');
    assert.ok(effectiveDate > assignment.startDate, 'end effective date follows start date');
    const endDate = previousDate(effectiveDate);
    assert.ok(assignment.endDate === null || endDate <= assignment.endDate, 'ending cannot extend a range');
    assignment.endDate = endDate;
    return structuredClone(assignment);
  }

  all() {
    return structuredClone(this.#assignments);
  }

  #sameDuty(left, right) {
    return left.storeId === right.storeId
      && left.positionId === right.positionId
      && left.role === right.role;
  }
}

test('assignment service exposes primary change and secondary lifecycle operations', () => {
  assert.match(service, /function changePrimaryAssignment\(/);
  assert.match(service, /function createSecondaryAssignment\(/);
  assert.match(service, /function endSecondaryAssignment\(/);
  assert.match(service, /function getAssignment\(/);
});

test('assignment writes lock staff, organization references, and all staff assignments', () => {
  assert.match(service, /FROM staffs WHERE id = \? LIMIT 1 FOR UPDATE/);
  assert.match(service, /FROM stores WHERE id = \? AND status = 1 LIMIT 1 FOR UPDATE/);
  assert.match(service, /WHERE id = \? AND status = 1 LIMIT 1 FOR UPDATE/);
  assert.match(service, /FROM staff_assignments WHERE staff_id = \?[\s\S]*FOR UPDATE/);
  assert.match(service, /系统角色不属于岗位适用角色/);
});

test('primary changes use closed date ranges and synchronize current fast fields', () => {
  assert.match(service, /modify\('-1 day'\)/);
  assert.match(service, /\['assignment_type'\] === 'primary'/);
  assert.match(service, /start_date.*<=.*\$data\['start_date'\]/);
  assert.match(service, /end_date.*>=.*\$data\['start_date'\]/);
  assert.match(service, /UPDATE staffs SET store_id = \?, primary_position_id = \?, role = \?, job_title = \?/);
  assert.match(service, /a\.start_date <= CURDATE\(\)/);
  assert.match(service, /a\.end_date IS NULL OR a\.end_date >= CURDATE\(\)/);
});

test('same-day primary requests are idempotent for equal duties and conflict for different duties', () => {
  assert.match(service, /assignmentMatches\(\$current, \$data\)/);
  assert.match(service, /commitIdempotentAssignment\(\$current, \$ownsTransaction\)/);
  assert.match(service, /同一生效日期已存在不同的主岗任职/);
  assert.match(service, /\$current\['end_date'\].*< date\('Y-m-d'\)/);

  const model = new AssignmentModel();
  const original = model.add({
    staffId: 1,
    type: 'primary',
    storeId: 1,
    positionId: 10,
    role: 'sales',
    startDate: '2026-01-01',
  });
  const idempotent = model.changePrimary(1, {
    storeId: 1,
    positionId: 10,
    role: 'sales',
    startDate: '2026-01-01',
  });
  assert.equal(idempotent.id, original.id);
  assert.throws(() => model.changePrimary(1, {
    storeId: 2,
    positionId: 10,
    role: 'sales',
    startDate: '2026-01-01',
  }), /different same-day primary conflicts/);
});

test('backdated primary changes preserve ended history and planned future assignments', () => {
  const model = new AssignmentModel();
  const history = model.add({
    staffId: 4,
    type: 'primary',
    storeId: 1,
    positionId: 10,
    role: 'sales',
    startDate: '2026-01-01',
    endDate: '2026-02-28',
  });
  model.add({
    staffId: 4,
    type: 'primary',
    storeId: 2,
    positionId: 10,
    role: 'sales',
    startDate: '2026-03-01',
    endDate: '2026-12-31',
  });
  const planned = model.add({
    staffId: 4,
    type: 'primary',
    storeId: 3,
    positionId: 20,
    role: 'manager',
    startDate: '2027-01-01',
  });

  const changed = model.changePrimary(4, {
    storeId: 2,
    positionId: 20,
    role: 'manager',
    startDate: '2026-07-01',
  });
  const rows = model.all();
  assert.deepEqual(rows.find((row) => row.id === history.id), history);
  assert.equal(rows.find((row) => row.startDate === '2026-03-01').endDate, '2026-06-30');
  assert.equal(changed.endDate, '2026-12-31');
  assert.equal(rows.find((row) => row.id === planned.id).startDate, '2027-01-01');
});

test('multiple secondary duties coexist while matching duty ranges reject overlap', () => {
  assert.match(service, /dateRangesOverlap/);
  assert.match(service, /相同职责的兼岗任职区间发生重叠/);

  const model = new AssignmentModel();
  model.addSecondary(2, {
    storeId: 1,
    positionId: 20,
    role: 'coach',
    startDate: '2026-03-01',
    endDate: null,
  });
  model.addSecondary(2, {
    storeId: 2,
    positionId: 20,
    role: 'coach',
    startDate: '2026-03-01',
    endDate: null,
  });
  assert.equal(model.all().length, 2);
  assert.throws(() => model.addSecondary(2, {
    storeId: 1,
    positionId: 20,
    role: 'coach',
    startDate: '2026-04-01',
    endDate: null,
  }), /matching secondary ranges cannot overlap/);
});

test('secondary ending applies the day before effective date and protects ended history', () => {
  assert.match(service, /已结束的历史任职不可修改/);
  assert.match(service, /结束操作不能延长原任职区间/);

  const model = new AssignmentModel();
  const active = model.add({
    staffId: 3,
    type: 'secondary',
    storeId: 1,
    positionId: 20,
    role: 'coach',
    startDate: '2026-05-01',
  });
  const ended = model.endSecondary(active.id, '2026-08-01', '2026-07-24');
  assert.equal(ended.endDate, '2026-07-31');

  const history = model.add({
    staffId: 3,
    type: 'secondary',
    storeId: 2,
    positionId: 20,
    role: 'coach',
    startDate: '2025-01-01',
    endDate: '2025-12-31',
  });
  assert.throws(() => model.endSecondary(history.id, '2026-01-01', '2026-07-24'), /historical assignments/);
});

test('assignment changes record operator, reason, snapshots, and transaction rollback', () => {
  for (const action of [
    'assignment.primary.change',
    'assignment.secondary.create',
    'assignment.secondary.end',
  ]) {
    assert.match(service, new RegExp(`'action' => '${action.replaceAll('.', '\\.')}'`));
  }
  assert.match(service, /'change_reason' => \$this->normalizeChangeReason/);
  assert.match(service, /'before' =>/);
  assert.match(service, /'after' =>/);
  assert.match(service, /operator_staff_id/);
  assert.match(service, /rollBackIfNeeded\(\)/);
  assert.doesNotMatch(service, /DELETE\s+FROM\s+staff_assignments/i);
});
