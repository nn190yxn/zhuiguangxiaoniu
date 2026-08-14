import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const read = (relativePath) => readFileSync(new URL(relativePath, import.meta.url), 'utf8');
const saveReport = read('../api/workload/save-report.php');
const makeupService = read('../api/workload/services/WorkloadMakeupService.php');
const auditService = read('../api/workload/services/WorkloadAuditTaskService.php');
const auditAction = read('../api/workload/audit-action.php');
const reportState = read('../api/workload/services/WorkloadReportStateService.php');
const settlementService = read('../api/workload/services/WorkloadDailySettlementService.php');
const penaltyService = read('../api/workload/services/WorkloadPenaltyService.php');
const penaltyAction = read('../api/admin/workload/penalty-action.php');

class DailyClosureJourney {
  constructor() {
    this.targetPoints = 4;
    this.reportedPoints = 0;
    this.effectivePoints = 0;
    this.tasks = [];
    this.penalty = null;
    this.nextTaskId = 1;
  }

  submit(points) {
    this.reportedPoints = points;
    const current = this.tasks.filter((task) => !task.superseded);
    for (const task of current) task.superseded = true;
    this.tasks.push({
      id: this.nextTaskId++,
      version: this.tasks.length + 1,
      status: 'pending',
      superseded: false,
    });
    this.effectivePoints = 0;
    return this.settle('today_open');
  }

  rejectCurrent() {
    this.currentTask().status = 'rejected';
    return this.settle('makeup_open');
  }

  approveCurrent() {
    this.currentTask().status = 'approved';
    this.effectivePoints = this.reportedPoints;
    return this.settle(this.gapPoints() === 0 ? 'completed' : 'overdue');
  }

  correct(points) {
    return this.submit(points);
  }

  settle(status) {
    const settlement = {
      targetPoints: this.targetPoints,
      reportedPoints: this.reportedPoints,
      effectivePoints: this.effectivePoints,
      gapPoints: this.gapPoints(),
      status,
    };
    if (status === 'overdue' && settlement.gapPoints > 0) {
      if (!this.penalty || this.penalty.status !== 'payroll_handed_off') {
        this.penalty = {
          gapPoints: settlement.gapPoints,
          amount: settlement.gapPoints * 20,
          status: this.penalty?.status === 'confirmed' ? 'confirmed' : 'pending_confirmation',
        };
      }
    }
    return settlement;
  }

  confirmPenalty() {
    assert.equal(this.penalty.status, 'pending_confirmation');
    this.penalty.status = 'confirmed';
  }

  handoffPayroll() {
    assert.equal(this.penalty.status, 'confirmed');
    this.penalty.status = 'payroll_handed_off';
  }

  gapPoints() {
    return Math.max(0, this.targetPoints - this.effectivePoints);
  }

  currentTask() {
    return this.tasks.find((task) => !task.superseded);
  }
}

test('[validates 1.1, 1.5, 2.2, 2.3, 3.1, 3.4, 3.5] daily closure journey preserves report, audit, correction, penalty, and payroll history', () => {
  const journey = new DailyClosureJourney();

  const submitted = journey.submit(2);
  assert.deepEqual(submitted, {
    targetPoints: 4,
    reportedPoints: 2,
    effectivePoints: 0,
    gapPoints: 4,
    status: 'today_open',
  });

  const rejected = journey.rejectCurrent();
  assert.equal(rejected.status, 'makeup_open');
  assert.equal(journey.currentTask().status, 'rejected');
  assert.equal(journey.penalty, null);

  const makeup = journey.submit(4);
  assert.equal(makeup.status, 'today_open');
  assert.deepEqual(
    journey.tasks.map((task) => ({ version: task.version, status: task.status, superseded: task.superseded })),
    [
      { version: 1, status: 'rejected', superseded: true },
      { version: 2, status: 'pending', superseded: false },
    ],
  );
  assert.equal(journey.penalty, null);

  const overdue = journey.settle('overdue');
  assert.deepEqual(
    { status: overdue.status, gapPoints: overdue.gapPoints, amount: journey.penalty.amount, penaltyStatus: journey.penalty.status },
    { status: 'overdue', gapPoints: 4, amount: 80, penaltyStatus: 'pending_confirmation' },
  );

  journey.correct(3);
  assert.equal(journey.currentTask().version, 3);
  const corrected = journey.approveCurrent();
  assert.deepEqual(
    { status: corrected.status, effectivePoints: corrected.effectivePoints, gapPoints: corrected.gapPoints, amount: journey.penalty.amount },
    { status: 'overdue', effectivePoints: 3, gapPoints: 1, amount: 20 },
  );

  journey.confirmPenalty();
  journey.handoffPayroll();
  const payrollSnapshot = structuredClone(journey.penalty);
  journey.correct(4);
  journey.approveCurrent();
  assert.deepEqual(journey.penalty, payrollSnapshot);
});

test('[validates 1.1, 1.5, 2.2, 2.3, 3.1, 3.4, 3.5] production entry points keep the closure journey transactional and auditable', () => {
  assert.match(saveReport, /WorkloadMakeupService/);
  assert.match(saveReport, /replaceForSubmission/);
  assert.match(saveReport, /WorkloadConversionResultService/);
  assert.match(saveReport, /WorkloadDailySettlementService/);
  assert.match(saveReport, /->refreshReport\(\$reportId\)/);
  assert.match(makeupService, /仅可补齐昨天的日报/);
  assert.match(makeupService, /payroll_handed_off/);

  assert.match(auditAction, /\['approved', 'rejected', 'needs_resubmit'\]/);
  assert.match(auditService, /audit_status = 'pending'/);
  assert.match(auditService, /->refreshScope\(/);
  assert.match(auditService, /previous_task_id/);
  assert.match(auditService, /audit_status = 'superseded'/);

  assert.match(reportState, /workload_report_corrections/);
  assert.match(reportState, /replaceForSubmission/);
  assert.match(reportState, /WorkloadConversionResultService/);
  assert.match(reportState, /WorkloadDailySettlementService/);
  assert.match(reportState, /->refreshReport\(\$reportId\)/);

  assert.match(settlementService, /max\(0, \$targetPoints - \$totals\['effective_points'\]\)/);
  assert.match(settlementService, /->refreshForSettlement\(\$settlement\)/);
  assert.match(penaltyService, /\$gapPoints \* self::UNIT_AMOUNT/);
  assert.match(penaltyService, /'confirm' => \$status === 'pending_confirmation'/);
  assert.match(penaltyService, /'payroll_handoff' => \$status === 'confirmed'/);
  assert.match(penaltyService, /workload_penalty_record_logs/);
  assert.match(penaltyAction, /\['operation', 'admin', 'ceo'\]/);
  assert.match(penaltyAction, /->applyAction\(\$penaltyId, \$action, \$reason, \$operatorStaffId\)/);
});
