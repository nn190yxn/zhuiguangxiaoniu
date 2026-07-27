import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const service = readFileSync(
  new URL('../api/admin/services/StaffImportService.php', import.meta.url),
  'utf8',
);
const lifecycle = readFileSync(
  new URL('../api/admin/services/StaffLifecycleService.php', import.meta.url),
  'utf8',
);
const endpoint = readFileSync(new URL('../api/admin/staff-import.php', import.meta.url), 'utf8');
const cli = readFileSync(new URL('./import_staff_cli.php', import.meta.url), 'utf8');

class ImportBatchModel {
  #batches = new Map();
  #nextStaffId = 1;

  run(batchKey, records) {
    let batch = this.#batches.get(batchKey);
    if (batch?.status === 'processing') throw new Error('processing');
    if (batch?.status === 'completed') return structuredClone(batch);
    if (batch && batch.rows.length !== records.length) throw new Error('row count changed');
    if (!batch) {
      batch = {
        batchKey,
        status: 'pending',
        rows: records.map((record, index) => ({
          line: index + 1,
          status: 'pending',
          retries: 0,
          staffId: null,
          summary: this.#summary(record),
        })),
      };
      this.#batches.set(batchKey, batch);
    }

    batch.status = 'processing';
    for (const row of batch.rows.filter(({ status }) => status === 'pending' || status === 'failed')) {
      const record = records[row.line - 1];
      if (row.status === 'failed') row.retries += 1;
      row.summary = this.#summary(record);
      if (record.valid) {
        row.status = 'succeeded';
        row.staffId = this.#nextStaffId++;
      } else {
        row.status = 'failed';
        row.staffId = null;
      }
    }
    const succeeded = batch.rows.filter(({ status }) => status === 'succeeded').length;
    const failed = batch.rows.filter(({ status }) => status === 'failed').length;
    batch.status = failed === 0 ? 'completed' : succeeded === 0 ? 'failed' : 'partial_failed';
    batch.succeeded = succeeded;
    batch.failed = failed;
    batch.retryableBatchKey = failed > 0 ? batchKey : null;
    return structuredClone(batch);
  }

  #summary(record) {
    return { employeeNo: record.employeeNo, name: record.name };
  }
}

test('import service persists UUID batches and delegates every row to the staff creation transaction', () => {
  assert.match(service, /class StaffImportService/);
  assert.match(service, /class StaffImportBatchConflictException/);
  assert.match(service, /random_bytes\(16\)/);
  assert.match(service, /INSERT INTO staff_import_batches/);
  assert.match(service, /INSERT INTO staff_import_rows/);
  assert.match(service, /new StaffLifecycleService\(\$this->db\)/);
  assert.match(service, /\$lifecycle->create\(\$record, \$operatorUser, \$operatorStaff\)/);
  assert.match(lifecycle, /public function create\(array \$input, array \$operatorUser, array \$operatorStaff\): array/);
});

test('batch acquisition locks the idempotency key and rejects concurrent or mismatched retries', () => {
  assert.match(service, /SELECT \* FROM staff_import_batches WHERE batch_key = \? FOR UPDATE/);
  assert.match(service, /ON DUPLICATE KEY UPDATE batch_key = VALUES\(batch_key\)/);
  assert.match(service, /\(string\)\$batch\['status'\] === 'processing'/);
  assert.match(service, /重试批次的行数必须与首次请求一致/);
  assert.match(service, /requested_by_staff_id/);
});

test('only pending and failed rows are processed and failed retries increment their counter', () => {
  assert.match(service, /status IN \('pending', 'failed'\)/);
  assert.match(service, /retry_count = retry_count \+ \?/);
  assert.match(service, /\$retry \? 1 : 0/);
  assert.match(service, /'partial_failed'/);
  assert.match(service, /'retryable_batch_key' => \$failed > 0/);
});

test('batch response preserves legacy import counters and errors', () => {
  for (const field of ['created', 'updated', 'linked', 'skipped', 'errors']) {
    assert.match(service, new RegExp(`['"]${field}['"]`));
  }
  assert.match(service, /'created' => \$succeeded/);
  assert.match(service, /'skipped' => \$failed/);
  assert.match(service, /'employee_no' => \(string\)\(\$summary\['employee_no'\]/);
});

test('stored row summaries exclude credentials and mask contact and account fields', () => {
  const summaryMethod = service.match(/private function summarizeRecord[\s\S]*?\n    }/)?.[0] ?? '';
  assert.doesNotMatch(summaryMethod, /password|initial_password|openid/i);
  for (const field of ['phone', 'username', 'email']) {
    assert.match(summaryMethod, new RegExp(`adminMaskSensitiveValue\\(\\$record\\['${field}'\\]\\)`));
  }
});

test('HTTP and CLI entry points use the same import service without direct identity writes', () => {
  for (const source of [endpoint, cli]) {
    assert.match(source, /new StaffImportService\(/);
    assert.doesNotMatch(source, /INSERT INTO staffs|UPDATE staffs SET|INSERT INTO wp_users/);
  }
  assert.match(endpoint, /adminRequirePermission\('staff\.create'\)/);
  assert.match(endpoint, /StaffImportBatchConflictException[\s\S]*?jsonResponse\(409/);
  assert.match(endpoint, /StaffImportValidationException[\s\S]*?jsonResponse\(400/);
  assert.match(endpoint, /hash_file\('sha256', \$temporaryPath\)/);
});

test('partial failures can be corrected without recreating successful rows', () => {
  const model = new ImportBatchModel();
  const key = '8406f44f-66c4-4c19-a9de-cf159b337595';
  const first = model.run(key, [
    { employeeNo: 'EMP001', name: '甲', valid: true },
    { employeeNo: 'EMP002', name: '乙', valid: false },
  ]);
  assert.equal(first.status, 'partial_failed');
  assert.equal(first.succeeded, 1);
  assert.equal(first.failed, 1);
  const firstStaffId = first.rows[0].staffId;

  const retried = model.run(key, [
    { employeeNo: 'CHANGED', name: '忽略', valid: true },
    { employeeNo: 'EMP002', name: '乙', valid: true },
  ]);
  assert.equal(retried.status, 'completed');
  assert.equal(retried.rows[0].staffId, firstStaffId);
  assert.equal(retried.rows[0].summary.employeeNo, 'EMP001');
  assert.equal(retried.rows[1].retries, 1);
  assert.equal(retried.retryableBatchKey, null);
});

test('completed batch replay returns the original result', () => {
  const model = new ImportBatchModel();
  const key = '6a4473c7-da13-46ac-8877-0ab53df3e03c';
  const first = model.run(key, [{ employeeNo: 'EMP003', name: '丙', valid: true }]);
  const replay = model.run(key, [{ employeeNo: 'EMP999', name: '变更', valid: true }]);
  assert.deepEqual(replay, first);
});
