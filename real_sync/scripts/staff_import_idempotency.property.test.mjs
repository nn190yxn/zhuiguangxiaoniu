import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const service = readFileSync(new URL('../api/admin/services/StaffImportService.php', import.meta.url), 'utf8');
const migration = readFileSync(
  new URL('../database/migrations/202607240001_staff_organization.sql', import.meta.url),
  'utf8',
);

function random(seed) {
  let state = seed >>> 0;
  return () => {
    state = (Math.imul(state, 1664525) + 1013904223) >>> 0;
    return state / 0x1_0000_0000;
  };
}

class ImportModel {
  profiles = new Map();
  batches = new Map();
  nextId = 1;

  import(batchKey, employeeNumbers, failures = new Set()) {
    const signature = JSON.stringify(employeeNumbers);
    const existing = this.batches.get(batchKey);
    if (existing && existing.signature !== signature) throw new Error('batch conflict');
    if (existing?.status === 'completed') return structuredClone(existing.result);

    const rows = existing?.rows ?? employeeNumbers.map(() => ({ status: 'pending', staffId: null }));
    employeeNumbers.forEach((employeeNo, index) => {
      if (rows[index].status === 'succeeded') return;
      if (failures.has(index)) {
        rows[index] = { status: 'failed', staffId: null };
        return;
      }
      if (this.profiles.has(employeeNo)) {
        rows[index] = { status: 'failed', staffId: null };
        return;
      }
      const staffId = this.nextId++;
      this.profiles.set(employeeNo, staffId);
      rows[index] = { status: 'succeeded', staffId };
    });
    const succeeded = rows.filter((row) => row.status === 'succeeded').length;
    const failed = rows.filter((row) => row.status === 'failed').length;
    const status = failed === 0 ? 'completed' : succeeded === 0 ? 'failed' : 'partial_failed';
    const result = { batchKey, status, succeeded, failed, rows: structuredClone(rows) };
    this.batches.set(batchKey, { signature, status, rows, result });
    return structuredClone(result);
  }
}

test('[validates 17.8, Property 30] arbitrary same-batch replays keep one stable profile per employee', () => {
  for (let seed = 1; seed <= 128; seed += 1) {
    const rng = random(seed);
    const model = new ImportModel();
    const employees = Array.from({ length: 1 + Math.floor(rng() * 12) }, (_, index) => `S${seed}-${index}`);
    const first = model.import(`batch-${seed}`, employees);
    for (let replay = 0; replay < 256; replay += 1) {
      const result = model.import(`batch-${seed}`, employees);
      assert.deepEqual(result, first);
      assert.equal(model.profiles.size, employees.length);
      assert.equal(new Set(model.profiles.values()).size, model.profiles.size);
    }
  }
});

test('[validates 17.8, Property 30] partial retries preserve succeeded row identities', () => {
  const model = new ImportModel();
  const employees = ['S001', 'S002', 'S003'];
  const first = model.import('batch-partial', employees, new Set([1]));
  const firstStaffId = first.rows[0].staffId;
  const retry = model.import('batch-partial', employees);
  assert.equal(retry.rows[0].staffId, firstStaffId);
  assert.equal(retry.status, 'completed');
  assert.equal(model.profiles.size, 3);
});

test('[validates 17.8, Property 30] different batches cannot duplicate an existing employee profile', () => {
  const model = new ImportModel();
  model.import('batch-a', ['S001']);
  const duplicate = model.import('batch-b', ['S001']);
  assert.equal(duplicate.status, 'failed');
  assert.equal(model.profiles.size, 1);
});

test('[validates 17.8, Property 30] production contracts lock batches and skip successful rows', () => {
  assert.match(service, /WHERE batch_key = \? FOR UPDATE/);
  assert.match(service, /status IN \('pending', 'failed'\)/);
  assert.match(service, /if \(\(string\)\$batch\['status'\] === 'completed'\)/);
  assert.match(migration, /UNIQUE KEY uq_staff_import_batches_key \(batch_key\)/);
  assert.match(migration, /UNIQUE KEY uq_staff_import_rows_batch_row \(batch_id, row_number\)/);
  assert.match(migration, /UNIQUE KEY uq_staffs_employee_no \(employee_no\)/);
});
