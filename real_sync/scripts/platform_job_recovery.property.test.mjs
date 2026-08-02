import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const runnerSource = readFileSync(new URL('../api/platform/JobRunner.php', import.meta.url), 'utf8');
const outboxSource = readFileSync(new URL('../api/platform/OutboxService.php', import.meta.url), 'utf8');

function createRandom(seed) {
  let state = seed >>> 0;
  return () => {
    state = (Math.imul(state, 1664525) + 1013904223) >>> 0;
    return state / 0x100000000;
  };
}

class JobRecoveryModel {
  constructor({ leaseSeconds = 30, maxAttempts = 4, baseDelaySeconds = 5, maxDelaySeconds = 20 } = {}) {
    this.leaseSeconds = leaseSeconds;
    this.maxAttempts = maxAttempts;
    this.baseDelaySeconds = baseDelaySeconds;
    this.maxDelaySeconds = maxDelaySeconds;
    this.now = 0;
    this.status = 'pending';
    this.availableAt = 0;
    this.attemptCount = 0;
    this.fencingToken = 0;
    this.workerId = null;
    this.leaseExpiresAt = null;
    this.commits = [];
    this.rejectedCommits = [];
  }

  claim(workerId) {
    const ready = ['pending', 'retry_wait'].includes(this.status) && this.availableAt <= this.now;
    const expired = this.status === 'running' && this.leaseExpiresAt <= this.now;
    if (!ready && !expired) return null;
    if (this.attemptCount >= this.maxAttempts) {
      if (expired) this.status = 'dead_letter';
      return null;
    }
    this.status = 'running';
    this.workerId = workerId;
    this.attemptCount += 1;
    this.fencingToken += 1;
    this.leaseExpiresAt = this.now + this.leaseSeconds;
    return this.currentLease();
  }

  currentLease() {
    if (this.status !== 'running') return null;
    return {
      workerId: this.workerId,
      fencingToken: this.fencingToken,
      attemptCount: this.attemptCount,
      leaseExpiresAt: this.leaseExpiresAt,
    };
  }

  isCurrent(lease) {
    return lease !== null
      && this.status === 'running'
      && lease.workerId === this.workerId
      && lease.fencingToken === this.fencingToken
      && this.now < this.leaseExpiresAt;
  }

  heartbeat(lease) {
    if (!this.isCurrent(lease)) return false;
    this.leaseExpiresAt = this.now + this.leaseSeconds;
    return true;
  }

  complete(lease, result) {
    if (!this.isCurrent(lease)) {
      this.rejectedCommits.push({
        lease,
        result,
        status: this.status,
        latestWorkerId: this.workerId,
        latestToken: this.fencingToken,
        rejectedAt: this.now,
      });
      return false;
    }
    this.commits.push({ fencingToken: lease.fencingToken, result });
    this.status = 'succeeded';
    this.workerId = null;
    this.leaseExpiresAt = null;
    return true;
  }

  fail(lease, errorCode) {
    if (!this.isCurrent(lease)) return null;
    if (this.attemptCount >= this.maxAttempts) {
      this.status = 'dead_letter';
      this.workerId = null;
      this.leaseExpiresAt = null;
      return { action: 'dead_letter', delaySeconds: null, errorCode };
    }
    const delaySeconds = Math.min(
      this.maxDelaySeconds,
      this.baseDelaySeconds * (2 ** (this.attemptCount - 1)),
    );
    this.status = 'retry_wait';
    this.availableAt = this.now + delaySeconds;
    this.workerId = null;
    this.leaseExpiresAt = null;
    return { action: 'retry', delaySeconds, errorCode };
  }

  advance(seconds) {
    this.now += seconds;
  }
}

class SideEffectModel {
  constructor(job) {
    this.job = job;
    this.receipts = new Map();
    this.externalResults = new Map();
    this.externalExecutionCount = new Map();
  }

  begin(lease, key, payloadHash) {
    if (!this.job.isCurrent(lease)) return null;
    const existing = this.receipts.get(key);
    if (existing && existing.payloadHash !== payloadHash) throw new Error('idempotency_conflict');
    if (existing?.status === 'confirmed') return existing;
    const receipt = { key, payloadHash, status: 'processing', fencingToken: lease.fencingToken };
    this.receipts.set(key, receipt);
    return receipt;
  }

  execute(key) {
    if (!this.externalResults.has(key)) {
      this.externalResults.set(key, { externalId: `external:${key}` });
      this.externalExecutionCount.set(key, 1);
    }
    return this.externalResults.get(key);
  }

  confirm(lease, key, payloadHash, result) {
    if (!this.job.isCurrent(lease)) return null;
    const receipt = this.receipts.get(key);
    if (!receipt) return null;
    if (receipt.payloadHash !== payloadHash) throw new Error('idempotency_conflict');
    if (receipt.status === 'confirmed') return receipt;
    if (receipt.fencingToken !== lease.fencingToken) return null;
    const confirmed = { ...receipt, status: 'confirmed', result };
    this.receipts.set(key, confirmed);
    return confirmed;
  }
}

class OutboxReplayModel {
  constructor(event) {
    this.event = { ...event, status: 'failed', replayCount: 0 };
  }

  replay(operator, reason) {
    this.event = {
      ...this.event,
      status: 'pending',
      replayCount: this.event.replayCount + 1,
      replayOperator: operator,
      replayReason: reason,
    };
    return this.event;
  }
}

test('property 9: only the latest fencing token can commit after random lease competition', () => {
  const operations = ['advance', 'claim-a', 'claim-b', 'heartbeat-current', 'heartbeat-stale', 'complete-current', 'complete-stale', 'fail-current'];

  for (let run = 1; run <= 128; run += 1) {
    const random = createRandom(0x09000000 + run);
    const job = new JobRecoveryModel({ maxAttempts: 64 });
    const leases = [];

    const interrupted = job.claim('worker-a');
    leases.push(interrupted);
    job.advance(job.leaseSeconds);
    const recovered = job.claim('worker-b');
    leases.push(recovered);
    assert.ok(recovered.fencingToken > interrupted.fencingToken);
    assert.equal(job.complete(interrupted, 'late-interrupted-result'), false);

    for (let step = 0; step < 256; step += 1) {
      if (['succeeded', 'dead_letter'].includes(job.status)) {
        const replacement = new JobRecoveryModel({ maxAttempts: 64 });
        Object.assign(job, replacement);
        leases.length = 0;
      }
      const operation = operations[Math.floor(random() * operations.length)];
      const current = job.currentLease();
      const stale = leases.find((lease) => lease && !job.isCurrent(lease)) ?? null;
      if (operation === 'advance') job.advance(1 + Math.floor(random() * 45));
      if (operation === 'claim-a' || operation === 'claim-b') {
        const lease = job.claim(operation === 'claim-a' ? 'worker-a' : 'worker-b');
        if (lease) leases.push(lease);
      }
      if (operation === 'heartbeat-current' && current) job.heartbeat(current);
      if (operation === 'heartbeat-stale' && stale) assert.equal(job.heartbeat(stale), false);
      if (operation === 'complete-current' && current) job.complete(current, `run-${run}-step-${step}`);
      if (operation === 'complete-stale' && stale) assert.equal(job.complete(stale, 'stale'), false);
      if (operation === 'fail-current' && current) job.fail(current, 'transient.failure');

      assert.ok(job.commits.length <= 1);
      for (const commit of job.commits) assert.equal(commit.fencingToken, job.fencingToken);
      for (const rejection of job.rejectedCommits) {
        assert.ok(
          rejection.status !== 'running'
            || rejection.lease.workerId !== rejection.latestWorkerId
            || rejection.lease.fencingToken !== rejection.latestToken
            || rejection.rejectedAt >= rejection.lease.leaseExpiresAt,
        );
      }
    }
  }
});

test('property 10: one side-effect idempotency key has one external execution and confirmed result', () => {
  for (let run = 1; run <= 128; run += 1) {
    const random = createRandom(0x10000000 + run);
    const job = new JobRecoveryModel({ maxAttempts: 64 });
    const effects = new SideEffectModel(job);
    const key = `message:${run}`;
    const hash = `payload:${run}`;
    let lease = job.claim('worker-a');
    const seenConfirmedResults = [];

    for (let step = 0; step < 256; step += 1) {
      if (!lease || !job.isCurrent(lease)) {
        if (job.status === 'running') job.advance(job.leaseSeconds);
        lease = job.claim(step % 2 === 0 ? 'worker-a' : 'worker-b');
        if (!lease) continue;
      }

      const receipt = effects.begin(lease, key, hash);
      assert.ok(receipt);
      const externalResult = effects.execute(key);
      if (random() < 0.2 && receipt.status !== 'confirmed') {
        job.advance(job.leaseSeconds);
        const replacement = job.claim(lease.workerId === 'worker-a' ? 'worker-b' : 'worker-a');
        assert.equal(effects.confirm(lease, key, hash, externalResult), null);
        lease = replacement;
        continue;
      }
      const confirmed = effects.confirm(lease, key, hash, externalResult);
      if (confirmed) seenConfirmedResults.push(confirmed.result);

      assert.equal(effects.externalExecutionCount.get(key), 1);
      for (const result of seenConfirmedResults) assert.deepEqual(result, externalResult);
    }

    assert.equal(effects.externalExecutionCount.get(key), 1);
    assert.equal(effects.receipts.get(key).status, 'confirmed');
  }
});

test('interruption recovery reaches dead letter with bounded backoff and preserves manual replay identity', () => {
  const backoff = new JobRecoveryModel({ maxAttempts: 4, baseDelaySeconds: 5, maxDelaySeconds: 20 });
  const decisions = [];
  for (let attempt = 1; attempt <= 4; attempt += 1) {
    const lease = backoff.claim(`retry-worker-${attempt}`);
    const decision = backoff.fail(lease, 'provider.timeout');
    decisions.push([decision.action, decision.delaySeconds]);
    if (decision.delaySeconds !== null) backoff.advance(decision.delaySeconds);
  }
  assert.deepEqual(decisions, [
    ['retry', 5],
    ['retry', 10],
    ['retry', 20],
    ['dead_letter', null],
  ]);

  const job = new JobRecoveryModel({ leaseSeconds: 30, maxAttempts: 4, baseDelaySeconds: 5, maxDelaySeconds: 20 });
  const first = job.claim('worker-a');
  job.advance(30);
  const second = job.claim('worker-b');
  assert.equal(job.complete(first, 'late'), false);
  assert.deepEqual(job.fail(second, 'provider.timeout'), { action: 'retry', delaySeconds: 10, errorCode: 'provider.timeout' });
  job.advance(10);
  const third = job.claim('worker-c');
  assert.deepEqual(job.fail(third, 'provider.timeout'), { action: 'retry', delaySeconds: 20, errorCode: 'provider.timeout' });
  job.advance(20);
  const fourth = job.claim('worker-d');
  assert.deepEqual(job.fail(fourth, 'provider.timeout'), { action: 'dead_letter', delaySeconds: null, errorCode: 'provider.timeout' });
  assert.equal(job.status, 'dead_letter');

  const original = {
    eventKey: 'event-7',
    idempotencyKey: 'event-idem-7',
    payloadHash: 'sha256-7',
    payload: { staffId: 7 },
  };
  const outbox = new OutboxReplayModel(original);
  const replayed = outbox.replay('operator-7', 'approved recovery');
  assert.equal(replayed.status, 'pending');
  assert.equal(replayed.replayCount, 1);
  assert.equal(replayed.replayOperator, 'operator-7');
  assert.deepEqual(
    { eventKey: replayed.eventKey, idempotencyKey: replayed.idempotencyKey, payloadHash: replayed.payloadHash, payload: replayed.payload },
    original,
  );
});

test('production contracts fence task and side-effect transitions and preserve replay payloads', () => {
  assert.match(runnerSource, /status = 'running' AND worker_id = \? AND fencing_token = \? AND lease_expires_at > \?/);
  assert.match(runnerSource, /\$fencingToken = \(int\)\$job\['fencing_token'\] \+ 1/);
  assert.match(runnerSource, /\$this->retryPolicy->decision\(\$lease->attemptCount, \$lease->maxAttempts\)/);
  assert.match(outboxSource, /UNIQUE KEY|idempotency_key/);
  assert.match(outboxSource, /status <> 'confirmed' AND job_id = \? AND worker_id = \? AND fencing_token = \?/);
  assert.match(outboxSource, /SET status = 'pending', replay_count = replay_count \+ 1/);
  assert.doesNotMatch(outboxSource.match(/public function replay\([\s\S]*?\n    }/)?.[0] ?? '', /payload_json\s*=/);
});
