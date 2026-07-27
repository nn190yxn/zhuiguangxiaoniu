import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const read = (relativePath) => readFileSync(new URL(relativePath, import.meta.url), 'utf8');
const service = read('../api/workload/services/WorkloadReportStateService.php');
const saveReport = read('../api/workload/save-report.php');
const myReport = read('../api/workload/my-report.php');
const correction = read('../api/workload/correct-report.php');
const lockWorker = read('../api/workload/obligation-lock-worker.php');

const shanghaiInstant = (value) => new Date(`${value}+08:00`).getTime();

function employeeState({ businessDate, now, completionStatus = 'missing' }) {
  const date = new Date(`${businessDate}T00:00:00+08:00`);
  const monday = date.getUTCDay() === 0;
  const deadline = new Date(date);
  deadline.setUTCDate(deadline.getUTCDate() + 1);
  const locked = !monday
    && ['missing', 'draft'].includes(completionStatus)
    && shanghaiInstant(now) >= deadline.getTime();
  return {
    completionStatus: monday ? 'exempt' : (locked ? 'locked_missing' : completionStatus),
    writable: !monday
      && shanghaiInstant(now) < deadline.getTime()
      && ['missing', 'draft'].includes(completionStatus),
  };
}

function lockModel(obligations, now) {
  return obligations.map((obligation) => {
    if (
      obligation.requiredStatus === 'required'
      && ['missing', 'draft'].includes(obligation.completionStatus)
      && obligation.deadlineAt <= now
    ) {
      return { ...obligation, completionStatus: 'locked_missing' };
    }
    return { ...obligation };
  });
}

test('state service uses database UTC time and Shanghai midnight as the authority', () => {
  assert.match(service, /BUSINESS_TIMEZONE = 'Asia\/Shanghai'/);
  assert.match(service, /SELECT UTC_TIMESTAMP\(\)/);
  assert.match(service, /->setTimezone\(new DateTimeZone\(self::BUSINESS_TIMEZONE\)\)/);
  assert.match(service, /\$now >= \$deadline/);
  assert.match(service, /\$date->format\('Y-m-d'\) > \$now->format\('Y-m-d'\)/);
  assert.match(service, /日报已于次日 00:00 锁定/);
});

test('save-report locks the report and synchronizes its obligation in one transaction', () => {
  assert.match(saveReport, /new WorkloadReportStateService\(\$pdo\)/);
  assert.match(saveReport, /\$pdo->beginTransaction\(\)/);
  assert.match(saveReport, /ORDER BY id ASC FOR UPDATE/);
  assert.match(saveReport, /appRoleCode\(\(string\)\(\$candidate\['role_code'\]/);
  assert.match(saveReport, /->synchronizeReport\(\$reportId\)/);
  assert.equal((saveReport.match(/->assertEmployeeWritable\(\$date\)/g) ?? []).length, 2);
  assert.ok(saveReport.indexOf('$pdo->beginTransaction()') < saveReport.indexOf('ORDER BY id ASC FOR UPDATE'));
  assert.ok(saveReport.indexOf('->synchronizeReport($reportId)') < saveReport.indexOf('$pdo->commit()'));
  assert.match(saveReport, /WorkloadReportStateException/);
  assert.match(saveReport, /\$pdo->rollBack\(\)/);
});

test('report synchronization maps draft, submitted, and corrected to one obligation', () => {
  assert.match(service, /completionStatus = \$corrected \? 'corrected' : \$status/);
  assert.match(service, /SELECT \* FROM workload_daily_reports WHERE id = \? FOR UPDATE/);
  assert.match(service, /FROM workload_submission_obligations/);
  assert.match(service, /appRoleCode\(\(string\) \$row\['role_code'\]\)/);
  assert.match(service, /UPDATE workload_submission_obligations SET required_status = \?, report_id = \?/);
  assert.match(service, /INSERT INTO workload_submission_obligations/);
});

test('lock worker atomically locks only expired required missing and draft obligations', () => {
  assert.equal((service.match(/lockMissing->execute/g) ?? []).length, 2);
  assert.match(service, /required_status = \? AND completion_status = \?/);
  assert.match(service, /AND deadline_at <= \?/);
  assert.match(service, /'locked_missing'/);
  assert.match(lockWorker, /PHP_SAPI !== 'cli'/);
  assert.match(lockWorker, /->lockExpired\(\$now\)/);
  assert.match(lockWorker, /\[workload\.obligation-lock\]/);
});

test('my-report returns obligation state, deadline, writability, and pending items', () => {
  assert.match(myReport, /new WorkloadReportStateService\(\$pdo\)/);
  assert.match(myReport, /->stateForScope\(/);
  for (const field of [
    'obligation',
    'completion_status',
    'deadline_at',
    'is_writable',
    'pending_items',
    'is_weekly_rest_day',
  ]) {
    assert.match(myReport, new RegExp(`'${field}'`));
  }
  assert.match(myReport, /appRoleCode\(\(string\)\(\$candidate\['role_code'\]/);
});

test('management correction is scope-authorized and records atomic snapshots', () => {
  assert.match(correction, /appCanEditStore\(\$context, \$storeId\)/);
  assert.match(correction, /->correctReport\(/);
  assert.match(service, /\$this->pdo->beginTransaction\(\)/);
  assert.match(service, /before_snapshot_json/);
  assert.match(service, /after_snapshot_json/);
  assert.match(service, /correction_reason/);
  assert.match(service, /operated_by_staff_id/);
  assert.match(service, /synchronizeReport\(\$reportId, true\)/);
  assert.match(service, /completion_status' => 'corrected'/);
});

test('23:59 remains writable while 00:00 locks draft and missing states', () => {
  for (const completionStatus of ['missing', 'draft']) {
    const before = employeeState({
      businessDate: '2026-07-28',
      now: '2026-07-28T23:59:59',
      completionStatus,
    });
    const deadline = employeeState({
      businessDate: '2026-07-28',
      now: '2026-07-29T00:00:00',
      completionStatus,
    });
    assert.equal(before.writable, true);
    assert.equal(before.completionStatus, completionStatus);
    assert.equal(deadline.writable, false);
    assert.equal(deadline.completionStatus, 'locked_missing');
  }
  assert.deepEqual(employeeState({
    businessDate: '2026-07-27',
    now: '2026-07-27T12:00:00',
  }), { completionStatus: 'exempt', writable: false });
});

test('locking preserves submitted, corrected, future, and exempt obligations', () => {
  const now = '2026-07-29 00:00:00';
  const result = lockModel([
    { id: 1, requiredStatus: 'required', completionStatus: 'missing', deadlineAt: now },
    { id: 2, requiredStatus: 'required', completionStatus: 'draft', deadlineAt: now },
    { id: 3, requiredStatus: 'required', completionStatus: 'submitted', deadlineAt: now },
    { id: 4, requiredStatus: 'required', completionStatus: 'corrected', deadlineAt: now },
    { id: 5, requiredStatus: 'required', completionStatus: 'missing', deadlineAt: '2026-07-30 00:00:00' },
    { id: 6, requiredStatus: 'exempt', completionStatus: 'exempt', deadlineAt: now },
  ], now);
  assert.deepEqual(result.map(({ completionStatus }) => completionStatus), [
    'locked_missing',
    'locked_missing',
    'submitted',
    'corrected',
    'missing',
    'exempt',
  ]);
});
