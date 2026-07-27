import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const read = (relativePath) => readFileSync(new URL(relativePath, import.meta.url), 'utf8');
const effectiveValueService = read('../api/workload/services/WorkloadEffectiveValueService.php');
const auditTaskService = read('../api/workload/services/WorkloadAuditTaskService.php');
const reportStateService = read('../api/workload/services/WorkloadReportStateService.php');
const correctionEndpoint = read('../api/workload/correct-report.php');

class AuditValueModel {
  constructor(rawValue = 12.5) {
    this.rawValue = rawValue;
    this.tasks = [];
    this.nextId = 1;
    this.replace(rawValue, 'employee');
  }

  get currentTask() {
    return this.tasks.find((task) => task.supersededAt === null) ?? null;
  }

  values() {
    const status = this.currentTask?.status ?? null;
    return {
      rawValue: this.rawValue,
      pendingValue: status === 'pending' ? this.rawValue : 0,
      effectiveValue: status === 'approved' ? this.rawValue : 0,
    };
  }

  audit(status, comment = '') {
    if (!['approved', 'rejected', 'needs_resubmit'].includes(status)) throw new Error('invalid status');
    if (!this.currentTask || this.currentTask.status !== 'pending') throw new Error('pending only');
    if (['rejected', 'needs_resubmit'].includes(status) && !comment.trim()) throw new Error('comment required');
    this.currentTask.status = status;
    this.currentTask.comment = comment;
  }

  replace(rawValue, operator) {
    const previous = this.currentTask;
    if (previous) {
      previous.status = 'superseded';
      previous.supersededAt = 'now';
      previous.supersededBy = operator;
    }
    this.rawValue = rawValue;
    this.tasks.push({
      id: this.nextId++,
      version: previous ? previous.version + 1 : 1,
      previousId: previous?.id ?? null,
      status: 'pending',
      comment: '',
      supersededAt: null,
    });
  }

  requestReaudit() {
    if (this.currentTask?.status !== 'needs_resubmit') throw new Error('needs resubmit only');
    this.replace(this.rawValue, 'employee');
  }

  resubmit(rawValue) {
    this.replace(rawValue, 'employee');
  }

  correct(rawValue) {
    this.replace(rawValue, 'manager');
  }
}

test('[validates 7.1, 7.4, 7.5] pending keeps raw value separate from effective value', () => {
  const model = new AuditValueModel(12.5);
  assert.equal(model.currentTask.status, 'pending');
  assert.deepEqual(model.values(), { rawValue: 12.5, pendingValue: 12.5, effectiveValue: 0 });
});

test('[validates 7.2, 7.5] approval moves the raw value into effective value', () => {
  const model = new AuditValueModel(12.5);
  model.audit('approved');
  assert.deepEqual(model.values(), { rawValue: 12.5, pendingValue: 0, effectiveValue: 12.5 });
});

test('[validates 7.3, 7.5] rejection keeps its comment and contributes zero effective value', () => {
  const model = new AuditValueModel(12.5);
  model.audit('rejected', '凭证与填报值不一致');
  assert.equal(model.currentTask.comment, '凭证与填报值不一致');
  assert.deepEqual(model.values(), { rawValue: 12.5, pendingValue: 0, effectiveValue: 0 });
});

test('[validates 7.1-7.5] re-review supersedes needs-resubmit and restores a pending successor', () => {
  const model = new AuditValueModel(12.5);
  model.audit('needs_resubmit', '请补充凭证');
  assert.deepEqual(model.values(), { rawValue: 12.5, pendingValue: 0, effectiveValue: 0 });
  model.requestReaudit();
  assert.deepEqual(
    { status: model.currentTask.status, version: model.currentTask.version, previousId: model.currentTask.previousId },
    { status: 'pending', version: 2, previousId: 1 },
  );
  assert.deepEqual(model.values(), { rawValue: 12.5, pendingValue: 12.5, effectiveValue: 0 });
});

test('[validates 7.1-7.5] report resubmission replaces an approved task and recalculates all three values', () => {
  const model = new AuditValueModel(12.5);
  model.audit('approved');
  model.resubmit(18);
  assert.equal(model.tasks[0].status, 'superseded');
  assert.deepEqual(
    { status: model.currentTask.status, version: model.currentTask.version, previousId: model.currentTask.previousId },
    { status: 'pending', version: 2, previousId: 1 },
  );
  assert.deepEqual(model.values(), { rawValue: 18, pendingValue: 18, effectiveValue: 0 });
});

test('[validates 7.1-7.5] management correction preserves history and creates a pending task for corrected value', () => {
  const model = new AuditValueModel(12.5);
  model.audit('approved');
  model.correct(20);
  assert.equal(model.tasks[0].supersededBy, 'manager');
  assert.deepEqual(
    { status: model.currentTask.status, version: model.currentTask.version, previousId: model.currentTask.previousId },
    { status: 'pending', version: 2, previousId: 1 },
  );
  assert.deepEqual(model.values(), { rawValue: 20, pendingValue: 20, effectiveValue: 0 });
});

test('PHP services implement the tested effective-value and management-correction contracts', () => {
  assert.match(effectiveValueService, /pending_value' => \$isPending \? \$rawValue : 0\.0/);
  assert.match(effectiveValueService, /effective_value' => \$isFullAudit \? \(\$isApproved \? \$rawValue : 0\.0\) : \$rawValue/);
  assert.match(auditTaskService, /\?int \$operatorStaffId = null/);
  assert.match(auditTaskService, /\$operatorStaffId = \$operatorStaffId \?\? \$staffId/);
  assert.match(reportStateService, /管理更正后，审核任务已由新版本替代/);
  assert.match(reportStateService, /new WorkloadRoleRuleVersionService\(\$this->pdo\)\)->forReport\(\$reportId\)/);
  assert.match(reportStateService, /new WorkloadAuditTaskService\(\$this->pdo\)\)->replaceForSubmission\(/);
  const replacement = reportStateService.indexOf('->replaceForSubmission(');
  assert.ok(replacement >= 0 && replacement < reportStateService.indexOf('$this->pdo->commit()', replacement));
  assert.match(correctionEndpoint, /WorkloadReportStateException\|WorkloadAuditTaskException\|WorkloadRoleRuleVersionException/);
});
