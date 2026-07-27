import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const read = (relativePath) => readFileSync(new URL(relativePath, import.meta.url), 'utf8');
const migration = read('../database/migrations/202607240005_workload_audit_task_history.sql');
const service = read('../api/workload/services/WorkloadAuditTaskService.php');
const saveReport = read('../api/workload/save-report.php');
const auditAction = read('../api/workload/audit-action.php');
const auditList = read('../api/workload/audit-list.php');
const dashboard = read('../api/workload/dashboard.php');
const hqSummary = read('../api/workload/hq-summary.php');
const storeSummary = read('../api/workload/store-summary.php');

class AuditHistoryModel {
  constructor() {
    this.tasks = [];
    this.logs = [];
    this.nextId = 1;
  }

  submit(values) {
    for (const task of this.tasks.filter((item) => !item.supersededAt)) {
      const before = task.status;
      task.status = 'superseded';
      task.supersededAt = 'now';
      this.logs.push({ taskId: task.id, before, after: 'superseded' });
    }
    for (const [metric, value] of Object.entries(values)) {
      if (value <= 0) continue;
      const history = this.tasks.filter((task) => task.metric === metric);
      const previous = history.at(-1) ?? null;
      this.tasks.push({
        id: this.nextId++,
        metric,
        value,
        version: previous ? previous.version + 1 : 1,
        previousId: previous?.id ?? null,
        status: 'pending',
        comment: null,
        supersededAt: null,
      });
    }
  }

  audit(id, status, comment) {
    const task = this.tasks.find((item) => item.id === id);
    if (task.supersededAt) throw new Error('historical task is read only');
    const before = task.status;
    task.status = status;
    task.comment = comment;
    this.logs.push({ taskId: id, before, after: status, comment });
  }
}

test('[validates 7.1] migration adds task versions, predecessor links, and supersession metadata additively', () => {
  for (const column of ['task_version', 'previous_task_id', 'superseded_at']) {
    assert.match(migration, new RegExp(`COLUMN_NAME = '${column}'`));
    assert.match(migration, new RegExp(`ADD COLUMN ${column}`));
  }
  for (const index of ['idx_workload_audit_version_history', 'idx_workload_audit_previous_task', 'idx_workload_audit_current_backlog']) {
    assert.match(migration, new RegExp(`INDEX_NAME = '${index}'`));
  }
  assert.doesNotMatch(migration, /\b(?:DROP|TRUNCATE|DELETE)\b/i);
});

test('[validates 7.1] first submission creates pending version one tasks', () => {
  const model = new AuditHistoryModel();
  model.submit({ calls: 3, moments: 1 });
  assert.deepEqual(model.tasks.map(({ metric, version, previousId, status }) => ({ metric, version, previousId, status })), [
    { metric: 'calls', version: 1, previousId: null, status: 'pending' },
    { metric: 'moments', version: 1, previousId: null, status: 'pending' },
  ]);
  assert.match(service, /audit_status\) [\s\S]*VALUES \(\?, \?, \?, \?, \?, \?, \?, \?, 'pending'\)/);
});

test('[validates 7.1-7.4] resubmission preserves the old decision and comment through a superseded transition', () => {
  const model = new AuditHistoryModel();
  model.submit({ calls: 3 });
  model.audit(1, 'rejected', '凭证内容不完整');
  model.submit({ calls: 5 });
  assert.equal(model.tasks[0].status, 'superseded');
  assert.equal(model.tasks[0].comment, '凭证内容不完整');
  assert.deepEqual(model.tasks[1], {
    id: 2,
    metric: 'calls',
    value: 5,
    version: 2,
    previousId: 1,
    status: 'pending',
    comment: null,
    supersededAt: null,
  });
  assert.deepEqual(model.logs.map(({ before, after }) => ({ before, after })), [
    { before: 'pending', after: 'rejected' },
    { before: 'rejected', after: 'superseded' },
  ]);
  assert.match(service, /before_status, after_status, comment/);
});

test('[validates 7.1] metrics removed on resubmission retain history without creating a replacement', () => {
  const model = new AuditHistoryModel();
  model.submit({ calls: 3, moments: 2 });
  model.submit({ calls: 4, moments: 0 });
  assert.equal(model.tasks.filter((task) => task.metric === 'moments').length, 1);
  assert.equal(model.tasks.find((task) => task.metric === 'moments').status, 'superseded');
  assert.equal(model.tasks.filter((task) => task.metric === 'calls').at(-1).version, 2);
});

test('[validates 7.1-7.3] historical tasks are read only and audit actions use authenticated service transitions', () => {
  const model = new AuditHistoryModel();
  model.submit({ calls: 3 });
  model.submit({ calls: 4 });
  assert.throws(() => model.audit(1, 'approved', ''), /historical task is read only/);
  assert.match(service, /历史审核任务只读/);
  assert.match(service, /SELECT \* FROM workload_audit_tasks WHERE id = \? FOR UPDATE/);
  assert.match(auditAction, /\$context = appRequireStaffContext\(\)/);
  assert.match(auditAction, /WorkloadAuditTaskService\(\$pdo\)\)->transition/);
  assert.doesNotMatch(auditAction, /staff_id'\s*=>\s*45/);
});

test('[validates 7.4-7.6] current views exclude superseded task versions while history remains queryable', () => {
  assert.doesNotMatch(saveReport, /DELETE FROM workload_audit_tasks/);
  assert.match(saveReport, /replaceForSubmission/);
  assert.match(auditList, /include_history/);
  assert.match(auditList, /t\.task_version, t\.previous_task_id/);
  for (const source of [dashboard, hqSummary, storeSummary]) {
    assert.match(source, /t\.superseded_at IS NULL/);
    assert.match(source, /t\.audit_status <> 'superseded'/);
  }
});
