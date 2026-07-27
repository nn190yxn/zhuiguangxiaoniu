import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const lifecycleSource = readFileSync(new URL('../api/admin/services/StaffLifecycleService.php', import.meta.url), 'utf8');
const associationSource = readFileSync(new URL('../api/admin/services/StaffAssociationService.php', import.meta.url), 'utf8');

class LifecycleModel {
  constructor(status = 'active') {
    this.staff = { id: 10, userId: 20, status, sessionVersion: 1 };
    this.account = { id: 20, active: status === 'active' };
    this.assignments = [{ id: 30, type: 'primary', endDate: null }];
    this.associations = new Map();
    this.audit = [];
    this.queue = Promise.resolve();
  }

  offboard() {
    if (!this.staff || this.staff.status === 'offboarded') throw new Error('invalid lifecycle');
    this.staff.status = 'offboarded';
    this.staff.sessionVersion += 1;
    this.account.active = false;
    this.assignments.forEach((assignment) => { assignment.endDate = '2026-07-24'; });
    this.audit.push('offboard');
  }

  restore() {
    if (!this.staff || this.staff.status !== 'offboarded') throw new Error('invalid lifecycle');
    this.staff.status = 'active';
    this.staff.sessionVersion += 1;
    this.account.active = true;
    this.assignments.push({ id: 31, type: 'primary', endDate: null });
    this.audit.push('restore');
  }

  associationDigest() {
    const associations = [...this.associations.entries()].sort(([left], [right]) => left.localeCompare(right));
    return createHash('sha256').update(JSON.stringify({
      staffId: this.staff?.id,
      userId: this.staff?.userId,
      sessionVersion: this.staff?.sessionVersion,
      associations,
    })).digest('hex');
  }

  inspect(now = 1_000, operatorId = 90) {
    const blockingTotal = [...this.associations.values()].reduce((sum, count) => sum + count, 0);
    if (blockingTotal > 0) return { eligible: false, recommendation: 'offboard', blockingTotal };
    return {
      eligible: true,
      recommendation: 'purge',
      token: {
        staffId: this.staff.id,
        userId: this.staff.userId,
        sessionVersion: this.staff.sessionVersion,
        digest: this.associationDigest(),
        operatorId,
        expiresAt: now + 300,
      },
    };
  }

  purge(token, now = 1_001, operatorId = 90) {
    if (!this.staff || !this.account) throw new Error('identity missing');
    const check = this.inspect(now, operatorId);
    if (!check.eligible) throw new Error('offboard recommended');
    if (token.expiresAt <= now
      || token.operatorId !== operatorId
      || token.staffId !== this.staff.id
      || token.userId !== this.staff.userId
      || token.sessionVersion !== this.staff.sessionVersion
      || token.digest !== this.associationDigest()) {
      throw new Error('token state mismatch');
    }
    const snapshot = structuredClone({ staff: this.staff, account: this.account, assignments: this.assignments });
    this.assignments = [];
    this.staff = null;
    this.account = null;
    this.audit.push({ action: 'purge', snapshot });
  }

  runExclusive(operation) {
    const result = this.queue.then(operation);
    this.queue = result.catch(() => undefined);
    return result;
  }
}

test('production services expose the complete lifecycle and purge integration chain', () => {
  for (const method of ['offboard', 'restore', 'purgeMiscreated']) {
    assert.match(lifecycleSource, new RegExp(`public function ${method}\\(`));
  }
  assert.match(associationSource, /public function inspectForPurge\(/);
  assert.match(associationSource, /public function validateConfirmationToken\(/);
  assert.match(lifecycleSource, /new StaffAssociationService\(\$this->db\)/);
});

test('active and inactive staff both archive into a revoked immutable lifecycle', () => {
  for (const initialStatus of ['active', 'inactive']) {
    const model = new LifecycleModel(initialStatus);
    model.offboard();
    assert.equal(model.staff.status, 'offboarded');
    assert.equal(model.staff.sessionVersion, 2);
    assert.equal(model.account.active, false);
    assert.equal(model.assignments[0].endDate, '2026-07-24');
  }
});

test('restoring an offboarded staff member preserves history and creates a new assignment', () => {
  const model = new LifecycleModel();
  model.offboard();
  const archivedAssignment = structuredClone(model.assignments[0]);
  model.restore();
  assert.equal(model.staff.status, 'active');
  assert.equal(model.staff.sessionVersion, 3);
  assert.equal(model.account.active, true);
  assert.deepEqual(model.assignments[0], archivedAssignment);
  assert.equal(model.assignments[1].endDate, null);
});

test('any business association converts purge into an offboard recommendation', () => {
  for (const category of ['login', 'device', 'workload', 'learning', 'review', 'message']) {
    const model = new LifecycleModel();
    model.associations.set(category, 1);
    const result = model.inspect();
    assert.deepEqual(result, { eligible: false, recommendation: 'offboard', blockingTotal: 1 });
    assert.throws(() => model.purge({}), /offboard recommended/);
  }
});

test('zero-association purge removes the identity chain and retains an audit snapshot', () => {
  const model = new LifecycleModel();
  const { token } = model.inspect();
  model.purge(token);
  assert.equal(model.staff, null);
  assert.equal(model.account, null);
  assert.deepEqual(model.assignments, []);
  assert.equal(model.audit.length, 1);
  assert.equal(model.audit[0].action, 'purge');
  assert.equal(model.audit[0].snapshot.staff.id, 10);
});

test('expired tokens and lifecycle changes invalidate a previous zero-association decision', () => {
  const expiredModel = new LifecycleModel();
  const expiredToken = expiredModel.inspect(1_000).token;
  assert.throws(() => expiredModel.purge(expiredToken, 1_300), /token state mismatch/);

  const changedModel = new LifecycleModel();
  const changedToken = changedModel.inspect().token;
  changedModel.offboard();
  assert.throws(() => changedModel.purge(changedToken), /token state mismatch/);
});

test('an association created after token issuance blocks the queued purge', async () => {
  const model = new LifecycleModel();
  const token = model.inspect().token;
  await model.runExclusive(async () => { model.associations.set('workload', 1); });
  await assert.rejects(model.runExclusive(async () => model.purge(token)), /offboard recommended/);
  assert.equal(model.staff.id, 10);
  assert.equal(model.audit.length, 0);
});

test('two concurrent purge attempts with one token commit the identity deletion once', async () => {
  const model = new LifecycleModel();
  const token = model.inspect().token;
  const attempts = await Promise.allSettled([
    model.runExclusive(async () => model.purge(token)),
    model.runExclusive(async () => model.purge(token)),
  ]);
  assert.equal(attempts.filter(({ status }) => status === 'fulfilled').length, 1);
  assert.equal(attempts.filter(({ status }) => status === 'rejected').length, 1);
  assert.equal(model.audit.filter(({ action }) => action === 'purge').length, 1);
});
