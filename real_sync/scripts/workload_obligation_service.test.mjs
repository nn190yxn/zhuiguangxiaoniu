import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const service = readFileSync(
  new URL('../api/workload/services/WorkloadObligationService.php', import.meta.url),
  'utf8',
);
const worker = readFileSync(
  new URL('../api/workload/obligation-worker.php', import.meta.url),
  'utf8',
);

const normalizeRole = (role) => {
  const value = String(role).trim().toLowerCase();
  if (['sales', 'sale', 'consultant', '销售', '实习销售'].includes(value)) return 'sales';
  if (['coach', '教练', '实习教练'].includes(value)) return 'coach';
  if (['manager', 'store_manager', 'shop_manager', '店长'].includes(value)) return 'manager';
  if (['teaching_supervisor', '教学主管'].includes(value)) return 'teaching_supervisor';
  if (['supervisor', '督导'].includes(value)) return 'supervisor';
  return value;
};

const isMonday = (date) => new Date(`${date}T00:00:00Z`).getUTCDay() === 1;

function generateModel({ date, assignments, existing = [] }) {
  const records = new Map(existing.map((record) => [record.key, { ...record }]));
  const candidates = new Map();
  for (const assignment of assignments) {
    const role = normalizeRole(assignment.role);
    const activeOnDate = assignment.startDate <= date
      && (assignment.endDate === null || assignment.endDate >= date);
    const staffEligible = assignment.staffStatus === 'active'
      || (assignment.staffStatus === 'offboarded' && assignment.offboardedAt >= date);
    if (!activeOnDate || !staffEligible || !assignment.storeActive || !assignment.positionActive) continue;
    if (!['sales', 'coach', 'manager', 'teaching_supervisor', 'supervisor'].includes(role)) continue;
    const key = `${date}:${assignment.storeId}:${assignment.staffId}:${role}`;
    candidates.set(key, { key, role });
  }

  for (const candidate of candidates.values()) {
    const next = isMonday(date)
      ? { requiredStatus: 'exempt', reasonCode: 'weekly_rest_day', completionStatus: 'exempt' }
      : { requiredStatus: 'required', reasonCode: 'scheduled', completionStatus: 'missing' };
    const current = records.get(candidate.key);
    if (
      current
      && (current.reportId || !['missing', 'exempt'].includes(current.completionStatus))
    ) continue;
    records.set(candidate.key, { ...current, ...candidate, ...next });
  }
  return { candidates: [...candidates.values()], records: [...records.values()] };
}

const assignment = (overrides = {}) => ({
  staffId: 1,
  storeId: 10,
  role: 'sales',
  startDate: '2026-01-01',
  endDate: null,
  staffStatus: 'active',
  offboardedAt: null,
  storeActive: true,
  positionActive: true,
  ...overrides,
});

test('service validates a Shanghai business date and distinguishes Monday', () => {
  assert.match(service, /BUSINESS_TIMEZONE = 'Asia\/Shanghai'/);
  assert.match(service, /createFromFormat\('!Y-m-d', \$businessDate, \$timezone\)/);
  assert.match(service, /\$date->format\('N'\) === '1'/);
  assert.match(service, /'weekly_rest_day'/);
  assert.match(service, /'business_day'/);
});

test('service reads effective organization assignments and normalized employee workload roles', () => {
  assert.match(service, /FROM staff_assignments assignment/);
  assert.match(service, /assignment\.start_date <= \?/);
  assert.match(service, /assignment\.end_date IS NULL OR assignment\.end_date >= \?/);
  assert.match(service, /staff\.lifecycle_status = 'active'/);
  assert.match(service, /staff\.lifecycle_status = 'offboarded'/);
  assert.match(service, /store\.status = 1/);
  assert.match(service, /position\.status = 1/);
  assert.match(service, /appRoleCode\(/);
  assert.match(service, /ELIGIBLE_ROLES = \['sales', 'coach', 'manager', 'teaching_supervisor', 'supervisor'\]/);
  assert.match(service, /position\.status = 1/);
});

test('service writes obligations transactionally and protects completed report state on rerun', () => {
  assert.match(service, /\$ownsTransaction = !\$this->pdo->inTransaction\(\)/);
  assert.match(service, /\$this->pdo->beginTransaction\(\)/);
  assert.ok(service.indexOf('$this->pdo->beginTransaction()') < service.indexOf('$this->eligibleAssignments($businessDate)'));
  assert.match(service, /ON DUPLICATE KEY UPDATE/);
  assert.match(service, /report_id IS NULL AND completion_status IN \('missing', 'exempt'\)/);
  assert.match(service, /ELSE completion_status END/);
  assert.match(service, /\$storedRoleCode = \$existingKeys\[\$key\]/);
  assert.match(service, /appRoleCode\(\$roleCode\)/);
  assert.match(service, /\$this->pdo->rollBack\(\)/);
});

test('CLI worker defaults to Shanghai today and returns a machine-readable summary', () => {
  assert.match(worker, /PHP_SAPI !== 'cli'/);
  assert.match(worker, /new DateTimeZone\('Asia\/Shanghai'\)/);
  assert.match(worker, /generateForDate\(\$businessDate\)/);
  assert.match(worker, /json_encode\(\$result, JSON_UNESCAPED_UNICODE\)/);
  assert.match(worker, /fwrite\(STDERR/);
});

test('Monday creates exempt markers while Tuesday through Sunday create required obligations', () => {
  const monday = generateModel({ date: '2026-07-27', assignments: [assignment()] });
  assert.equal(monday.records[0].requiredStatus, 'exempt');
  assert.equal(monday.records[0].reasonCode, 'weekly_rest_day');
  assert.equal(monday.records[0].completionStatus, 'exempt');

  for (const date of ['2026-07-28', '2026-07-29', '2026-07-30', '2026-07-31', '2026-08-01', '2026-08-02']) {
    const result = generateModel({ date, assignments: [assignment()] });
    assert.equal(result.records[0].requiredStatus, 'required', date);
    assert.equal(result.records[0].completionStatus, 'missing', date);
  }
});

test('assignment boundaries, organization state, lifecycle, and role determine eligibility', () => {
  const date = '2026-07-28';
  const result = generateModel({
    date,
    assignments: [
      assignment({ staffId: 1, startDate: date, endDate: date }),
      assignment({ staffId: 2, role: '实习教练' }),
      assignment({ staffId: 3, role: 'manager' }),
      assignment({ staffId: 11, role: '教学主管' }),
      assignment({ staffId: 12, role: '督导' }),
      assignment({ staffId: 4, startDate: '2026-07-29' }),
      assignment({ staffId: 5, endDate: '2026-07-27' }),
      assignment({ staffId: 6, staffStatus: 'inactive' }),
      assignment({ staffId: 7, storeActive: false }),
      assignment({ staffId: 8, positionActive: false }),
      assignment({ staffId: 9, staffStatus: 'offboarded', offboardedAt: date }),
      assignment({ staffId: 10, staffStatus: 'offboarded', offboardedAt: '2026-07-27' }),
    ],
  });
  assert.deepEqual(result.candidates.map(({ key }) => key), [
    `${date}:10:1:sales`,
    `${date}:10:2:coach`,
    `${date}:10:3:manager`,
    `${date}:10:11:teaching_supervisor`,
    `${date}:10:12:supervisor`,
    `${date}:10:9:sales`,
  ]);
});

test('duplicate duties collapse to one obligation and completed reports survive reruns', () => {
  const date = '2026-07-28';
  const key = `${date}:10:1:sales`;
  const completed = {
    key,
    reportId: 88,
    requiredStatus: 'required',
    reasonCode: 'historical_report',
    completionStatus: 'submitted',
  };
  const result = generateModel({
    date,
    assignments: [assignment(), assignment({ role: 'consultant' })],
    existing: [completed],
  });
  assert.equal(result.candidates.length, 1);
  assert.equal(result.records.length, 1);
  assert.deepEqual(result.records[0], completed);
});
