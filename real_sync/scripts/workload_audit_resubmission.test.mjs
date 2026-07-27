import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const read = (relativePath) => readFileSync(new URL(relativePath, import.meta.url), 'utf8');
const service = read('../api/workload/services/WorkloadAuditTaskService.php');
const auditAction = read('../api/workload/audit-action.php');
const auditResubmit = read('../api/workload/audit-resubmit.php');
const evidenceUpload = read('../api/workload/evidence-upload.php');
const myReport = read('../api/workload/my-report.php');
const migration = read('../database/migrations/202607240006_workload_audit_resubmission.sql');
const manifest = read('../database/migration_manifest.php');

class ReviewLoopModel {
  constructor() {
    this.tasks = [{
      id: 1,
      staffId: 7,
      version: 1,
      previousId: null,
      status: 'pending',
      comment: '',
      superseded: false,
    }];
    this.evidences = [];
    this.nextId = 2;
  }

  audit(taskId, status, comment = '') {
    const task = this.tasks.find((item) => item.id === taskId);
    if (task.status !== 'pending') throw new Error('pending only');
    if (['rejected', 'needs_resubmit'].includes(status) && !comment.trim()) throw new Error('comment required');
    task.status = status;
    task.comment = comment;
  }

  upload(taskId, staffId) {
    const task = this.tasks.find((item) => item.id === taskId && !item.superseded);
    if (!task || task.staffId !== staffId) throw new Error('forbidden');
    if (task.status !== 'needs_resubmit') throw new Error('resubmit only');
    this.evidences.push({ taskId, staffId, afterReview: true });
  }

  resubmit(taskId, staffId) {
    const old = this.tasks.find((item) => item.id === taskId);
    const existing = this.tasks.find((item) => item.previousId === taskId && !item.superseded);
    if (existing) return { task: existing, idempotent: true };
    if (old.staffId !== staffId) throw new Error('forbidden');
    if (old.status !== 'needs_resubmit') throw new Error('resubmit only');
    if (!this.evidences.some((item) => item.taskId === taskId && item.afterReview)) throw new Error('evidence required');
    old.status = 'superseded';
    old.superseded = true;
    const next = {
      id: this.nextId++,
      staffId,
      version: old.version + 1,
      previousId: old.id,
      status: 'pending',
      comment: '',
      superseded: false,
    };
    this.tasks.push(next);
    return { task: next, idempotent: false };
  }
}

test('[validates 7.3] audit decisions only leave pending and rejection paths require a comment', () => {
  const model = new ReviewLoopModel();
  assert.throws(() => model.audit(1, 'rejected'), /comment required/);
  model.audit(1, 'rejected', '截图与填报项目不一致');
  assert.equal(model.tasks[0].status, 'rejected');
  assert.throws(() => model.audit(1, 'approved'), /pending only/);
  assert.match(service, /只有待审核任务可以执行审核操作/);
  assert.match(service, /驳回或要求补凭证时必须填写审核意见/);
  assert.match(service, /audit_status = 'pending'/);
  assert.match(auditAction, /\['approved', 'rejected', 'needs_resubmit'\]/);
});

test('[validates 7.3] rejected tasks remain terminal with their review comment', () => {
  const model = new ReviewLoopModel();
  model.audit(1, 'rejected', '无法识别有效凭证');
  assert.deepEqual(
    { status: model.tasks[0].status, comment: model.tasks[0].comment },
    { status: 'rejected', comment: '无法识别有效凭证' },
  );
  assert.match(service, /audit_comment = \?,[\s\S]*audited_at = NOW\(\), evidence_count_at_review = \?/);
  assert.match(service, /before_status, after_status, comment/);
});

test('[validates 7.3, 7.4] submitted reports accept evidence only for the owner current needs-resubmit task', () => {
  const model = new ReviewLoopModel();
  assert.throws(() => model.upload(1, 7), /resubmit only/);
  model.audit(1, 'needs_resubmit', '请补充清晰的业务截图');
  assert.throws(() => model.upload(1, 8), /forbidden/);
  model.upload(1, 7);
  assert.equal(model.evidences.length, 1);
  assert.match(service, /assertEvidenceUploadAllowed/);
  assert.match(service, /已提交日报仅可为待补凭证任务上传图片/);
  assert.match(service, /AND superseded_at IS NULL AND audit_status <> 'superseded'/);
  assert.match(evidenceUpload, /->assertEvidenceUploadAllowed\(/);
});

test('[validates 7.3, 7.4] evidence authorization and insert share one locked transaction', () => {
  const begin = evidenceUpload.indexOf('$pdo->beginTransaction()');
  const authorize = evidenceUpload.indexOf('->assertEvidenceUploadAllowed(');
  const lockEvidence = evidenceUpload.indexOf('ORDER BY id FOR UPDATE');
  const insert = evidenceUpload.indexOf('INSERT INTO workload_evidences');
  assert.ok(begin >= 0 && begin < authorize);
  assert.ok(authorize < lockEvidence && lockEvidence < insert);
  assert.match(evidenceUpload, /WorkloadAuditTaskException\|WorkloadRoleRuleVersionException/);
  assert.match(evidenceUpload, /count\(\$lockedEvidence->fetchAll/);
});

test('[validates 7.3, 7.4] re-review requires new evidence and creates a pending successor version', () => {
  const model = new ReviewLoopModel();
  model.audit(1, 'needs_resubmit', '补充凭证');
  assert.throws(() => model.resubmit(1, 7), /evidence required/);
  model.upload(1, 7);
  const result = model.resubmit(1, 7);
  assert.equal(result.idempotent, false);
  assert.deepEqual(
    { version: result.task.version, previousId: result.task.previousId, status: result.task.status },
    { version: 2, previousId: 1, status: 'pending' },
  );
  assert.equal(model.tasks[0].status, 'superseded');
  assert.match(service, /请先补充新的凭证图片再重新送审/);
  assert.match(service, /\(int\) \$task\['task_version'\] \+ 1/);
  assert.match(service, /previous_task_id/);
  assert.match(service, /\$evidenceCount > \(int\) \$evidenceBaseline/);
});

test('[validates 7.3, 7.4] migration records the evidence baseline without rewriting audit history', () => {
  assert.match(migration, /COLUMN_NAME = 'evidence_count_at_review'/);
  assert.match(migration, /ADD COLUMN evidence_count_at_review INT UNSIGNED NULL/);
  assert.doesNotMatch(migration, /\b(?:DROP|TRUNCATE|DELETE)\b/i);
  assert.match(manifest, /'202607240006'/);
  assert.match(manifest, /'workload_audit_tasks' => \['evidence_count_at_review'\]/);
  assert.match(service, /SELECT COUNT\(\*\) FROM workload_evidences/);
});

test('[validates 7.3, 7.4] duplicate re-review requests return the same current successor', () => {
  const model = new ReviewLoopModel();
  model.audit(1, 'needs_resubmit', '补充凭证');
  model.upload(1, 7);
  const first = model.resubmit(1, 7);
  const duplicate = model.resubmit(1, 7);
  assert.equal(duplicate.idempotent, true);
  assert.equal(duplicate.task.id, first.task.id);
  assert.equal(model.tasks.length, 2);
  assert.match(service, /currentReplacement/);
  assert.match(service, /previous_task_id = \? AND staff_id = \?/);
});

test('[validates 7.3, 8.2] employee re-review endpoint uses authenticated ownership and service transaction', () => {
  assert.match(auditResubmit, /\$context = appRequireStaffContext\(\)/);
  assert.match(auditResubmit, /appRequireInt\(\$input, 'task_id'/);
  assert.match(auditResubmit, /->requestReaudit\(\$taskId, \$staffId\)/);
  assert.match(auditResubmit, /catch \(WorkloadAuditTaskException\|WorkloadRoleRuleVersionException \$e\)/);
  assert.match(service, /无权重新提交该审核任务/);
  assert.match(service, /SELECT \* FROM workload_audit_tasks WHERE id = \? FOR UPDATE/);
});

test('[validates 7.3, 8.2] my-report exposes review comments, evidence state, and employee actions', () => {
  assert.match(myReport, /->employeeReviewState\(/);
  assert.match(myReport, /'audit_tasks' => \$auditState\['tasks'\]/);
  assert.match(myReport, /'needs_resubmit_count' => \$auditState\['needs_resubmit_count'\]/);
  assert.match(myReport, /array_merge\(\$state\['pending_items'\], \$auditState\['pending_items'\]\)/);
  for (const field of ['audit_comment', 'evidence_count', 'evidence_count_at_review', 'supplemented_after_review', 'required_action']) {
    assert.match(service, new RegExp(`'${field}'`));
  }
  for (const action of ['await_review', 'review_rejection', 'supplement_evidence', 'request_reaudit']) {
    assert.match(service, new RegExp(`'${action}'`));
  }
});
