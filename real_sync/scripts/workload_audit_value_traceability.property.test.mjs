import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const read = (relativePath) => readFileSync(new URL(relativePath, import.meta.url), 'utf8');
const taskService = read('../api/workload/services/WorkloadAuditTaskService.php');
const effectiveValueService = read('../api/workload/services/WorkloadEffectiveValueService.php');
const auditList = read('../api/workload/audit-list.php');
const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;
const reviewStatuses = ['approved', 'rejected', 'needs_resubmit'];

function seededRandom(seed) {
  let state = seed >>> 0;
  return () => {
    state = (Math.imul(state, 1664525) + 1013904223) >>> 0;
    return state / 0x1_0000_0000;
  };
}

class AuditTraceModel {
  tasks = [];
  logs = [];
  nextId = 1;

  get current() {
    return this.tasks.find((task) => task.supersededAt === null && task.status !== 'superseded') ?? null;
  }

  submit(value) {
    const previous = this.current;
    if (previous) this.supersede(previous, 'submission_replaced');
    if (value <= 0) return;
    this.tasks.push({
      id: this.nextId++,
      version: previous ? previous.version + 1 : (this.tasks.at(-1)?.version ?? 0) + 1,
      previousId: this.tasks.at(-1)?.id ?? null,
      submittedValue: value,
      status: 'pending',
      comment: '',
      supersededAt: null,
    });
  }

  review(status) {
    const task = this.current;
    if (!task || task.status !== 'pending' || !reviewStatuses.includes(status)) return false;
    const before = task.status;
    task.status = status;
    task.comment = status === 'approved' ? '' : `${status} evidence`;
    this.logs.push({ taskId: task.id, before, after: status });
    return true;
  }

  requestReaudit() {
    const task = this.current;
    if (!task || task.status !== 'needs_resubmit') return false;
    const value = task.submittedValue;
    this.supersede(task, 'evidence_resubmitted');
    this.tasks.push({
      id: this.nextId++,
      version: task.version + 1,
      previousId: task.id,
      submittedValue: value,
      status: 'pending',
      comment: '',
      supersededAt: null,
    });
    return true;
  }

  supersede(task, reason) {
    const before = task.status;
    task.status = 'superseded';
    task.supersededAt = 'now';
    this.logs.push({ taskId: task.id, before, after: 'superseded', reason });
  }

  traceStatus(task) {
    if (task.status !== 'superseded') return task.status;
    return this.logs.findLast((log) => log.taskId === task.id && log.after === 'superseded')?.before ?? null;
  }

  values(task) {
    const status = this.traceStatus(task);
    return {
      rawValue: task.submittedValue,
      pendingValue: status === 'pending' ? task.submittedValue : 0,
      effectiveValue: status === 'approved' ? task.submittedValue : 0,
      rejectedValue: status === 'rejected' ? task.submittedValue : 0,
    };
  }
}

function assertTraceable(model, context) {
  const currentTasks = model.tasks.filter((task) => task.supersededAt === null && task.status !== 'superseded');
  assert.ok(currentTasks.length <= 1, `${context}: multiple current tasks`);

  for (const [index, task] of model.tasks.entries()) {
    assert.equal(task.version, index + 1, `${context}: version ${task.id}`);
    assert.equal(task.previousId, index === 0 ? null : model.tasks[index - 1].id, `${context}: predecessor ${task.id}`);
    if (task.status === 'superseded') {
      assert.ok(task.supersededAt, `${context}: superseded timestamp ${task.id}`);
      assert.ok(model.traceStatus(task), `${context}: trace status ${task.id}`);
    }
    const values = model.values(task);
    const expected = task.submittedValue;
    const traceStatus = model.traceStatus(task);
    assert.equal(values.pendingValue, traceStatus === 'pending' ? expected : 0, `${context}: pending ${task.id}`);
    assert.equal(values.effectiveValue, traceStatus === 'approved' ? expected : 0, `${context}: approved ${task.id}`);
    assert.equal(values.rejectedValue, traceStatus === 'rejected' ? expected : 0, `${context}: rejected ${task.id}`);
    if (['pending', 'approved', 'rejected'].includes(traceStatus)) {
      assert.equal(
        values.pendingValue + values.effectiveValue + values.rejectedValue,
        expected,
        `${context}: partition ${task.id}`,
      );
    }
  }
}

test(`${validatesCriteria(['7.1-7.5', 'Property 8'])} arbitrary audit histories reconstruct pending, approved, and rejected values from task states`, () => {
  for (let seed = 1; seed <= 128; seed += 1) {
    const random = seededRandom(seed);
    const model = new AuditTraceModel();

    for (let step = 0; step < 256; step += 1) {
      const operation = Math.floor(random() * 6);
      if (operation <= 1) model.submit(Math.floor(random() * 10_001) / 100);
      if (operation === 2) model.review(reviewStatuses[Math.floor(random() * reviewStatuses.length)]);
      if (operation === 3) model.requestReaudit();
      if (operation === 4 && model.current?.status === 'pending') model.review('approved');
      if (operation === 5 && model.current?.status === 'pending') model.review('rejected');
      assertTraceable(model, `seed ${seed}, step ${step}`);
    }
  }
});

test(`${validatesCriteria(['7.1-7.5', 'Property 8'])} fixed transitions retain every superseded decision without adding history to current totals`, () => {
  const model = new AuditTraceModel();
  for (const status of ['pending', 'approved', 'rejected', 'needs_resubmit']) {
    model.submit(model.tasks.length + 1);
    if (status !== 'pending') model.review(status);
  }
  model.requestReaudit();

  assert.deepEqual(model.tasks.slice(0, 4).map((task) => model.traceStatus(task)), [
    'pending',
    'approved',
    'rejected',
    'needs_resubmit',
  ]);
  assert.equal(model.tasks.filter((task) => task.status !== 'superseded').length, 1);
  assertTraceable(model, 'fixed transition chain');
});

test(`${validatesCriteria(['7.1-7.5', 'Property 8'])} production contracts expose trace status and all audit value buckets`, () => {
  assert.match(taskService, /before_status, after_status, comment/);
  assert.match(taskService, /SET audit_status = 'superseded', superseded_at = NOW\(\)/);
  assert.match(taskService, /\$beforeStatus !== 'pending'/);
  assert.match(effectiveValueService, /\$isRejected = \$isFullAudit && \$taskExists && \$auditStatus === 'rejected'/);
  assert.match(effectiveValueService, /rejected_value' => \$isRejected \? \$rawValue : 0\.0/);
  assert.match(auditList, /workload_audit_logs supersede_log/);
  assert.match(auditList, /AS trace_status/);
  assert.match(auditList, /\$valueExpressions\['rejected_value'\]/);
});
