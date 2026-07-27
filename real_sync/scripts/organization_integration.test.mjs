import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const serviceSource = readFileSync(
  new URL('../api/admin/services/OrganizationService.php', import.meta.url),
  'utf8',
);
const treeEndpointSource = readFileSync(
  new URL('../api/admin/organization/tree.php', import.meta.url),
  'utf8',
);

function previousDate(date) {
  const value = new Date(`${date}T00:00:00Z`);
  value.setUTCDate(value.getUTCDate() - 1);
  return value.toISOString().slice(0, 10);
}

function includesDate(assignment, date) {
  return assignment.startDate <= date && (assignment.endDate === null || assignment.endDate >= date);
}

function overlaps(leftStart, leftEnd, rightStart, rightEnd) {
  return (leftEnd === null || leftEnd >= rightStart) && (rightEnd === null || rightEnd >= leftStart);
}

class OrganizationModel {
  #today;
  #nextStoreId = 1;
  #nextPositionId = 1;
  #nextAssignmentId = 1;
  #stores = new Map();
  #positions = new Map();
  #staff = new Map();
  #assignments = [];

  constructor(today) {
    this.#today = today;
  }

  createStore(code, name) {
    assert.equal([...this.#stores.values()].some((store) => store.code === code), false);
    const store = { id: this.#nextStoreId++, code, name, status: 1 };
    this.#stores.set(store.id, store);
    return structuredClone(store);
  }

  createPosition(code, name, roles) {
    assert.equal([...this.#positions.values()].some((position) => position.code === code), false);
    const position = { id: this.#nextPositionId++, code, name, roles, status: 1 };
    this.#positions.set(position.id, position);
    return structuredClone(position);
  }

  addStaff(id, name) {
    this.#staff.set(id, {
      id,
      name,
      active: true,
      storeId: null,
      positionId: null,
      role: null,
    });
  }

  setStoreStatus(storeId, status) {
    const store = this.#stores.get(storeId);
    assert.ok(store);
    if (status === 0) {
      assert.equal(this.#hasCurrentReference('storeId', storeId), false, 'current assignment blocks store deactivation');
    }
    store.status = status;
  }

  setPositionStatus(positionId, status) {
    const position = this.#positions.get(positionId);
    assert.ok(position);
    if (status === 0) {
      assert.equal(this.#hasCurrentReference('positionId', positionId), false, 'current assignment blocks position deactivation');
    }
    position.status = status;
  }

  changePrimary(staffId, { storeId, positionId, role, effectiveDate }) {
    this.#assertReferences(staffId, storeId, positionId, role);
    const primaries = this.#assignments.filter(
      (assignment) => assignment.staffId === staffId && assignment.type === 'primary',
    );
    const sameDay = primaries.find((assignment) => assignment.startDate === effectiveDate);
    if (sameDay) {
      if (sameDay.storeId === storeId && sameDay.positionId === positionId && sameDay.role === role) {
        return { ...structuredClone(sameDay), idempotent: true };
      }
      throw new Error('same-day-primary-conflict');
    }

    const current = primaries.find((assignment) => includesDate(assignment, effectiveDate));
    if (current) current.endDate = previousDate(effectiveDate);
    const next = primaries
      .filter((assignment) => assignment.startDate > effectiveDate)
      .sort((left, right) => left.startDate.localeCompare(right.startDate))[0];
    const created = {
      id: this.#nextAssignmentId++,
      staffId,
      storeId,
      positionId,
      role,
      type: 'primary',
      startDate: effectiveDate,
      endDate: next ? previousDate(next.startDate) : null,
    };
    this.#assignments.push(created);
    if (effectiveDate <= this.#today) this.#synchronizeStaff(staffId);
    return { ...structuredClone(created), idempotent: false };
  }

  addSecondary(staffId, { storeId, positionId, role, startDate, endDate = null }) {
    this.#assertReferences(staffId, storeId, positionId, role);
    const conflicts = this.#assignments.filter((assignment) =>
      assignment.staffId === staffId
      && assignment.type === 'secondary'
      && assignment.storeId === storeId
      && assignment.positionId === positionId
      && assignment.role === role
      && overlaps(assignment.startDate, assignment.endDate, startDate, endDate));
    if (conflicts.length > 0) {
      const same = conflicts.find(
        (assignment) => assignment.startDate === startDate && assignment.endDate === endDate,
      );
      if (same) return { ...structuredClone(same), idempotent: true };
      throw new Error('secondary-overlap-conflict');
    }
    const created = {
      id: this.#nextAssignmentId++,
      staffId,
      storeId,
      positionId,
      role,
      type: 'secondary',
      startDate,
      endDate,
    };
    this.#assignments.push(created);
    return { ...structuredClone(created), idempotent: false };
  }

  tree(date = this.#today) {
    const stores = [...this.#stores.values()]
      .filter((store) => store.status === 1)
      .map((store) => ({ id: store.id, name: store.name, positions: new Map() }));
    const storeMap = new Map(stores.map((store) => [store.id, store]));
    const uniqueStaff = new Set();
    const activeAssignments = this.#assignments.filter((assignment) => {
      const staff = this.#staff.get(assignment.staffId);
      return staff?.active
        && this.#stores.get(assignment.storeId)?.status === 1
        && this.#positions.get(assignment.positionId)?.status === 1
        && includesDate(assignment, date);
    });
    for (const assignment of activeAssignments) {
      const store = storeMap.get(assignment.storeId);
      if (!store.positions.has(assignment.positionId)) {
        store.positions.set(assignment.positionId, {
          id: assignment.positionId,
          name: this.#positions.get(assignment.positionId).name,
          assignments: [],
        });
      }
      store.positions.get(assignment.positionId).assignments.push(structuredClone(assignment));
      uniqueStaff.add(assignment.staffId);
    }
    return {
      staffCount: uniqueStaff.size,
      assignmentCount: activeAssignments.length,
      stores: stores.map((store) => ({ ...store, positions: [...store.positions.values()] })),
    };
  }

  assignments(staffId) {
    return structuredClone(this.#assignments.filter((assignment) => assignment.staffId === staffId));
  }

  staff(staffId) {
    return structuredClone(this.#staff.get(staffId));
  }

  #assertReferences(staffId, storeId, positionId, role) {
    assert.equal(this.#staff.get(staffId)?.active, true, 'staff must be active');
    assert.equal(this.#stores.get(storeId)?.status, 1, 'store must be active');
    const position = this.#positions.get(positionId);
    assert.equal(position?.status, 1, 'position must be active');
    assert.equal(position.roles.includes(role), true, 'role must be applicable to position');
  }

  #hasCurrentReference(field, id) {
    return this.#assignments.some((assignment) =>
      assignment[field] === id
      && this.#staff.get(assignment.staffId)?.active
      && includesDate(assignment, this.#today));
  }

  #synchronizeStaff(staffId) {
    const current = this.#assignments
      .filter((assignment) => assignment.staffId === staffId && assignment.type === 'primary' && includesDate(assignment, this.#today))
      .sort((left, right) => right.startDate.localeCompare(left.startDate))[0];
    const staff = this.#staff.get(staffId);
    staff.storeId = current?.storeId ?? null;
    staff.positionId = current?.positionId ?? null;
    staff.role = current?.role ?? null;
  }
}

function fixture() {
  const model = new OrganizationModel('2026-07-24');
  const north = model.createStore('NORTH', 'North');
  const south = model.createStore('SOUTH', 'South');
  const consultant = model.createPosition('consultant', 'Consultant', ['sales']);
  const coach = model.createPosition('coach', 'Coach', ['coach']);
  model.addStaff(1, 'Alice');
  return { model, north, south, consultant, coach };
}

test('organization implementation joins dictionaries, assignments, and tree endpoint', () => {
  for (const method of ['listPositions', 'listStores', 'changePrimaryAssignment', 'createSecondaryAssignment', 'getOrganizationTree']) {
    assert.match(serviceSource, new RegExp(`public function ${method}\\(`));
  }
  assert.match(treeEndpointSource, /new OrganizationService\(getDB\(\)\)/);
  assert.match(treeEndpointSource, /getOrganizationTree\(\)/);
});

test('active references block dictionary deactivation and ended history remains', () => {
  const { model, north, south, consultant } = fixture();
  model.changePrimary(1, {
    storeId: north.id,
    positionId: consultant.id,
    role: 'sales',
    effectiveDate: '2026-01-01',
  });
  assert.throws(() => model.setStoreStatus(north.id, 0), /current assignment/);
  assert.throws(() => model.setPositionStatus(consultant.id, 0), /current assignment/);

  model.changePrimary(1, {
    storeId: south.id,
    positionId: consultant.id,
    role: 'sales',
    effectiveDate: '2026-07-24',
  });
  model.setStoreStatus(north.id, 0);
  assert.equal(model.assignments(1)[0].endDate, '2026-07-23');
});

test('store transfer closes the old range and moves current tree and fast fields', () => {
  const { model, north, south, consultant } = fixture();
  model.changePrimary(1, {
    storeId: north.id,
    positionId: consultant.id,
    role: 'sales',
    effectiveDate: '2026-01-01',
  });
  model.changePrimary(1, {
    storeId: south.id,
    positionId: consultant.id,
    role: 'sales',
    effectiveDate: '2026-07-24',
  });

  assert.equal(model.tree('2026-07-23').stores.find((store) => store.id === north.id).positions.length, 1);
  assert.equal(model.tree().stores.find((store) => store.id === north.id).positions.length, 0);
  assert.equal(model.tree().stores.find((store) => store.id === south.id).positions.length, 1);
  assert.equal(model.staff(1).storeId, south.id);
});

test('position transfer is idempotent for equal input and rejects different same-day input', () => {
  const { model, north, consultant, coach } = fixture();
  model.changePrimary(1, {
    storeId: north.id,
    positionId: consultant.id,
    role: 'sales',
    effectiveDate: '2026-01-01',
  });
  const changed = model.changePrimary(1, {
    storeId: north.id,
    positionId: coach.id,
    role: 'coach',
    effectiveDate: '2026-07-24',
  });
  const repeated = model.changePrimary(1, {
    storeId: north.id,
    positionId: coach.id,
    role: 'coach',
    effectiveDate: '2026-07-24',
  });

  assert.equal(changed.idempotent, false);
  assert.equal(repeated.idempotent, true);
  assert.throws(() => model.changePrimary(1, {
    storeId: north.id,
    positionId: consultant.id,
    role: 'sales',
    effectiveDate: '2026-07-24',
  }), /same-day-primary-conflict/);
  assert.equal(model.staff(1).positionId, coach.id);
});

test('secondary duties coexist across positions and reject matching range overlap', () => {
  const { model, north, consultant, coach } = fixture();
  model.changePrimary(1, {
    storeId: north.id,
    positionId: consultant.id,
    role: 'sales',
    effectiveDate: '2026-01-01',
  });
  model.addSecondary(1, {
    storeId: north.id,
    positionId: coach.id,
    role: 'coach',
    startDate: '2026-07-01',
  });
  const repeated = model.addSecondary(1, {
    storeId: north.id,
    positionId: coach.id,
    role: 'coach',
    startDate: '2026-07-01',
  });
  assert.equal(repeated.idempotent, true);
  assert.throws(() => model.addSecondary(1, {
    storeId: north.id,
    positionId: coach.id,
    role: 'coach',
    startDate: '2026-07-20',
  }), /secondary-overlap-conflict/);

  const tree = model.tree();
  assert.equal(tree.staffCount, 1);
  assert.equal(tree.assignmentCount, 2);
  assert.equal(tree.stores.find((store) => store.id === north.id).positions.length, 2);
});
