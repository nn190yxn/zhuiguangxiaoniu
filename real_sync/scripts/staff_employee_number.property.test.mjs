import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const lifecycleService = readFileSync(new URL('../api/admin/services/StaffLifecycleService.php', import.meta.url), 'utf8');
const organizationMigration = readFileSync(new URL('../database/migrations/202607240001_staff_organization.sql', import.meta.url), 'utf8');
const sequenceMigration = readFileSync(new URL('../database/migrations/202607240004_staff_employee_number_sequence.sql', import.meta.url), 'utf8');

const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;

function seededRandom(seed) {
  let state = seed >>> 0;
  return () => {
    state = (Math.imul(state, 1664525) + 1013904223) >>> 0;
    return state / 0x100000000;
  };
}

class EmployeeNumberModel {
  constructor(prefix, width, start) {
    this.prefix = prefix;
    this.width = width;
    this.start = start;
    this.currentValue = start - 1;
    this.profiles = [];
  }

  create(requestedEmployeeNo, commit = true) {
    const sequenceSnapshot = this.currentValue;
    const employeeNo = requestedEmployeeNo || this.allocate();
    if (this.profiles.some((profile) => profile.employeeNo === employeeNo)) {
      this.currentValue = sequenceSnapshot;
      return { status: 'conflict', employeeNo };
    }
    if (!commit) {
      this.currentValue = sequenceSnapshot;
      return { status: 'rolled_back', employeeNo };
    }
    this.profiles.push({ id: this.profiles.length + 1, employeeNo });
    return { status: 'created', employeeNo };
  }

  allocate() {
    let employeeNo;
    do {
      this.currentValue++;
      employeeNo = this.prefix + String(this.currentValue).padStart(this.width, '0');
    } while (this.profiles.some((profile) => profile.employeeNo === employeeNo));
    return employeeNo;
  }
}

function assertProperty19(model) {
  const counts = new Map();
  for (const profile of model.profiles) {
    counts.set(profile.employeeNo, (counts.get(profile.employeeNo) ?? 0) + 1);
  }
  for (const [employeeNo, count] of counts) {
    assert.ok(count <= 1, `${employeeNo} belongs to ${count} staff profiles`);
  }
  assert.equal(counts.size, model.profiles.length);
}

test(`${validatesCriteria(["17.5", "17.7", "Property 19"])} arbitrary create sequences keep employee numbers unique`, () => {
  for (let seed = 1; seed <= 128; seed++) {
    const random = seededRandom(seed);
    const prefix = `S${seed % 11}-`;
    const model = new EmployeeNumberModel(prefix, 5, 1 + (seed % 7));
    const explicitPool = Array.from({ length: 24 }, (_, index) => `LEGACY-${String(index).padStart(3, '0')}`);

    for (let step = 0; step < 256; step++) {
      const choice = random();
      let requestedEmployeeNo = '';
      if (choice >= 0.4) {
        requestedEmployeeNo = explicitPool[Math.floor(random() * explicitPool.length)];
      }
      const shouldCommit = random() >= 0.12;
      const beforeCount = model.profiles.length;
      const result = model.create(requestedEmployeeNo, shouldCommit);

      if (result.status !== 'created') {
        assert.equal(model.profiles.length, beforeCount);
      }
      assertProperty19(model);
    }
  }
});

test(`${validatesCriteria(["17.5", "Property 19"])} generated numbers skip occupied historical values and reuse rolled-back allocations safely`, () => {
  const model = new EmployeeNumberModel('EMP', 6, 1);
  assert.equal(model.create('EMP000001').status, 'created');
  assert.equal(model.create('EMP000003').status, 'created');
  assert.deepEqual(model.create(''), { status: 'created', employeeNo: 'EMP000002' });
  assert.deepEqual(model.create('', false), { status: 'rolled_back', employeeNo: 'EMP000004' });
  assert.deepEqual(model.create(''), { status: 'created', employeeNo: 'EMP000004' });
  assertProperty19(model);
});

test(`${validatesCriteria(["17.7", "Property 19"])} duplicate explicit numbers report conflicts without mutating profiles`, () => {
  const model = new EmployeeNumberModel('EMP', 6, 1);
  assert.equal(model.create('CUSTOM-42').status, 'created');
  for (let attempt = 0; attempt < 100; attempt++) {
    assert.deepEqual(model.create('CUSTOM-42'), { status: 'conflict', employeeNo: 'CUSTOM-42' });
    assertProperty19(model);
  }
  assert.equal(model.profiles.length, 1);
});

test(`${validatesCriteria(["17.5", "17.7", "Property 19"])} production contracts enforce the modeled uniqueness property`, () => {
  assert.match(organizationMigration, /UNIQUE KEY uq_staffs_employee_no \(employee_no\)/);
  assert.match(sequenceMigration, /PRIMARY KEY \(sequence_key\)/);
  assert.match(lifecycleService, /staff_employee_number_sequences WHERE sequence_key = \? FOR UPDATE/);
  assert.match(lifecycleService, /SELECT 1 FROM staffs WHERE employee_no = \? LIMIT 1/);
  assert.match(lifecycleService, /s\.employee_no = \?/);
  assert.match(lifecycleService, /StaffIdentityConflictException/);
});
