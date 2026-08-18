import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const read = (relativePath) => readFileSync(new URL(relativePath, import.meta.url), 'utf8');
const makeupService = read('../api/workload/services/WorkloadMakeupService.php');
const saveReport = read('../api/workload/save-report.php');
const evidenceUpload = read('../api/workload/evidence-upload.php');
const auditTasks = read('../api/workload/services/WorkloadAuditTaskService.php');

function canMakeUp({ businessDate, now }) {
  const date = new Date(`${businessDate}T00:00:00+08:00`);
  const instant = new Date(`${now}+08:00`);
  const opensAt = new Date(date);
  const businessDay = new Date(`${businessDate}T12:00:00+08:00`).getUTCDay();
  const openDays = businessDay === 0 ? 2 : 1;
  opensAt.setUTCDate(opensAt.getUTCDate() + openDays);
  const deadline = new Date(opensAt);
  deadline.setUTCDate(deadline.getUTCDate() + 1);
  const shanghaiDay = (value) => new Date(value.getTime() + (8 * 60 * 60 * 1000)).toISOString().slice(0, 10);
  return instant >= opensAt
    && instant < deadline
    && shanghaiDay(instant) === shanghaiDay(opensAt)
    && businessDay !== 1;
}

test('[validates 2.2, 2.3] makeup is available only for yesterday through the next midnight', () => {
  assert.equal(canMakeUp({ businessDate: '2026-08-11', now: '2026-08-12T00:00:00' }), true);
  assert.equal(canMakeUp({ businessDate: '2026-08-11', now: '2026-08-12T23:59:59' }), true);
  assert.equal(canMakeUp({ businessDate: '2026-08-10', now: '2026-08-11T12:00:00' }), false);
  assert.equal(canMakeUp({ businessDate: '2026-08-16', now: '2026-08-17T12:00:00' }), false);
  assert.equal(canMakeUp({ businessDate: '2026-08-16', now: '2026-08-18T00:00:00' }), true);
  assert.match(makeupService, /private const BUSINESS_TIMEZONE = 'Asia\/Shanghai'/);
  assert.match(makeupService, /SELECT UTC_TIMESTAMP\(\)/);
  assert.match(makeupService, /public function isMakeupDate\(string \$reportDate\)/);
   assert.match(makeupService, /previousWorkday/);
   assert.match(makeupService, /nextWorkday/);
});

test('[validates 2.2, 2.3] yesterday draft, missing, and submitted reports use one owner-only makeup gate', () => {
  assert.match(saveReport, /\$makeupService->isMakeupDate\(\$date\)/);
  assert.match(saveReport, /'report_date' => \$date/);
  assert.match(saveReport, /assertReportWritable\(\$reportForMakeup, \$staffId\)/);
  assert.match(makeupService, /无权补齐该日报/);
  assert.match(makeupService, /仅可补齐最近一个工作日的日报/);
  assert.match(makeupService, /payroll_handed_off/);
  assert.match(makeupService, /FOR UPDATE/);
});

test('[validates 2.3] makeup evidence and current resubmission histories remain versioned', () => {
  assert.match(evidenceUpload, /WorkloadMakeupService/);
  assert.match(auditTasks, /makeup_open/);
  assert.match(auditTasks, /previous_task_id/);
  assert.match(auditTasks, /audit_status = 'superseded'/);
});
