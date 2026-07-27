import assert from 'node:assert/strict';
import { test } from 'node:test';

const SHANGHAI_OFFSET = '+08:00';

const normalizeRole = (role) => {
  const value = String(role).trim().toLowerCase();
  if (['sales', 'sale', 'consultant', '销售', '实习销售'].includes(value)) return 'sales';
  if (['coach', '教练', '实习教练'].includes(value)) return 'coach';
  return value;
};

const isMonday = (date) => new Date(`${date}T00:00:00${SHANGHAI_OFFSET}`).getUTCDay() === 0;
const deadlineAt = (date) => {
  const deadline = new Date(`${date}T00:00:00${SHANGHAI_OFFSET}`);
  deadline.setUTCDate(deadline.getUTCDate() + 1);
  return deadline.getTime();
};

function obligationState({ date, completionStatus, now }) {
  if (isMonday(date)) return { completionStatus: 'exempt', writable: false };
  const expired = now >= deadlineAt(date);
  const lockable = ['missing', 'draft'].includes(completionStatus);
  return {
    completionStatus: expired && lockable ? 'locked_missing' : completionStatus,
    writable: !expired && lockable,
  };
}

function obligationsForDate(date, assignments) {
  const obligations = new Map();
  for (const assignment of assignments) {
    const role = normalizeRole(assignment.role);
    const effective = assignment.startDate <= date
      && (assignment.endDate === null || assignment.endDate >= date);
    if (!effective || !['sales', 'coach'].includes(role)) continue;
    const key = `${date}:${assignment.storeId}:${assignment.staffId}:${role}`;
    obligations.set(key, {
      key,
      date,
      storeId: assignment.storeId,
      staffId: assignment.staffId,
      role,
      requiredStatus: isMonday(date) ? 'exempt' : 'required',
      completionStatus: isMonday(date) ? 'exempt' : 'missing',
    });
  }
  return [...obligations.values()];
}

class WorkloadTransactionModel {
  constructor() {
    this.reports = new Map();
    this.obligations = new Map();
    this.corrections = [];
  }

  transaction(operation) {
    const snapshot = structuredClone({
      reports: this.reports,
      obligations: this.obligations,
      corrections: this.corrections,
    });
    try {
      return operation();
    } catch (error) {
      this.reports = snapshot.reports;
      this.obligations = snapshot.obligations;
      this.corrections = snapshot.corrections;
      throw error;
    }
  }

  save({ key, values, status, failAfterReport = false }) {
    return this.transaction(() => {
      this.reports.set(key, { key, values: [...values], status });
      if (failAfterReport) throw new Error('obligation synchronization failed');
      this.obligations.set(key, { key, completionStatus: status });
    });
  }

  correct({ key, values, reason, operator, failAfterValues = false }) {
    return this.transaction(() => {
      const before = structuredClone(this.reports.get(key));
      this.reports.set(key, { ...before, values: [...values], status: 'submitted' });
      if (failAfterValues) throw new Error('correction audit failed');
      this.obligations.set(key, { key, completionStatus: 'corrected' });
      this.corrections.push({
        key,
        before,
        after: structuredClone(this.reports.get(key)),
        reason,
        operator,
      });
    });
  }
}

test('Monday is exempt while every Tuesday through Sunday is required', () => {
  const assignment = {
    staffId: 7,
    storeId: 10,
    role: 'sales',
    startDate: '2026-07-27',
    endDate: null,
  };
  const dates = ['2026-07-27', '2026-07-28', '2026-07-29', '2026-07-30', '2026-07-31', '2026-08-01', '2026-08-02'];
  const states = dates.map((date) => obligationsForDate(date, [assignment])[0]);
  assert.deepEqual(states.map(({ requiredStatus }) => requiredStatus), [
    'exempt',
    'required',
    'required',
    'required',
    'required',
    'required',
    'required',
  ]);
  assert.equal(states[0].completionStatus, 'exempt');
});

test('Shanghai 23:59:59 is writable and the exact UTC-equivalent midnight is locked', () => {
  const beforeDeadline = Date.parse('2026-07-28T15:59:59Z');
  const atDeadline = Date.parse('2026-07-28T16:00:00Z');
  assert.deepEqual(obligationState({
    date: '2026-07-28',
    completionStatus: 'draft',
    now: beforeDeadline,
  }), { completionStatus: 'draft', writable: true });
  assert.deepEqual(obligationState({
    date: '2026-07-28',
    completionStatus: 'draft',
    now: atDeadline,
  }), { completionStatus: 'locked_missing', writable: false });
  assert.equal(atDeadline, Date.parse(`2026-07-29T00:00:00${SHANGHAI_OFFSET}`));
});

test('store transfer preserves old snapshots and keeps a concurrent cross-store duty', () => {
  const assignments = [
    { staffId: 7, storeId: 10, role: 'sales', startDate: '2026-07-01', endDate: '2026-07-28' },
    { staffId: 7, storeId: 20, role: 'sales', startDate: '2026-07-29', endDate: null },
    { staffId: 7, storeId: 30, role: 'sales', startDate: '2026-07-01', endDate: null },
  ];
  const oldDay = obligationsForDate('2026-07-28', assignments);
  const newDay = obligationsForDate('2026-07-29', assignments);
  assert.deepEqual(oldDay.map(({ storeId }) => storeId), [10, 30]);
  assert.deepEqual(newDay.map(({ storeId }) => storeId), [20, 30]);
  assert.equal(oldDay[0].key, '2026-07-28:10:7:sales');
});

test('role transition uses the role effective on each business date', () => {
  const assignments = [
    { staffId: 7, storeId: 10, role: 'consultant', startDate: '2026-07-01', endDate: '2026-07-30' },
    { staffId: 7, storeId: 10, role: 'coach', startDate: '2026-07-31', endDate: null },
  ];
  assert.deepEqual(
    obligationsForDate('2026-07-30', assignments).map(({ role }) => role),
    ['sales'],
  );
  assert.deepEqual(
    obligationsForDate('2026-07-31', assignments).map(({ role }) => role),
    ['coach'],
  );
});

test('report persistence rolls back when obligation synchronization fails', () => {
  const model = new WorkloadTransactionModel();
  assert.throws(() => model.save({
    key: '2026-07-28:10:7:sales',
    values: [1, 2, 3, 4],
    status: 'submitted',
    failAfterReport: true,
  }), /obligation synchronization failed/);
  assert.equal(model.reports.size, 0);
  assert.equal(model.obligations.size, 0);
});

test('management correction rolls back atomically and records snapshots on success', () => {
  const key = '2026-07-28:10:7:sales';
  const model = new WorkloadTransactionModel();
  model.save({ key, values: [1, 2, 3, 4], status: 'draft' });
  model.obligations.set(key, { key, completionStatus: 'locked_missing' });

  assert.throws(() => model.correct({
    key,
    values: [5, 6, 7, 8],
    reason: '补录确认',
    operator: 99,
    failAfterValues: true,
  }), /correction audit failed/);
  assert.deepEqual(model.reports.get(key).values, [1, 2, 3, 4]);
  assert.equal(model.obligations.get(key).completionStatus, 'locked_missing');
  assert.equal(model.corrections.length, 0);

  model.correct({ key, values: [5, 6, 7, 8], reason: '补录确认', operator: 99 });
  assert.equal(model.obligations.get(key).completionStatus, 'corrected');
  assert.deepEqual(model.corrections[0], {
    key,
    before: { key, values: [1, 2, 3, 4], status: 'draft' },
    after: { key, values: [5, 6, 7, 8], status: 'submitted' },
    reason: '补录确认',
    operator: 99,
  });
});
