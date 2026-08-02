import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { test } from 'node:test';

const root = new URL('..', import.meta.url);
const hasPhp = spawnSync('php', ['-v'], { encoding: 'utf8' }).status === 0;
const serviceSource = readFileSync(new URL('../api/platform/OutboxService.php', import.meta.url), 'utf8');
const migrationSource = readFileSync(new URL('../database/migrations/202607310011_platform_outbox.sql', import.meta.url), 'utf8');
const verifierSource = readFileSync(new URL('../database/MigrationReplayVerifier.php', import.meta.url), 'utf8');

function runPhp(source) {
  const result = spawnSync('php', ['-d', 'display_errors=1', '-r', source], {
    cwd: root,
    encoding: 'utf8',
    timeout: 10_000,
  });
  assert.equal(result.status, 0, result.stderr);
  return JSON.parse(result.stdout);
}

test('[validates 9.7-9.9] outbox and side effects enforce transactions, idempotency, leases, replay, and compensation', { skip: !hasPhp }, () => {
  const output = runPhp(String.raw`
    require 'api/platform/OutboxService.php';

    final class FakeLeaseStore implements PlatformJobStore {
      public int $activeToken = 1;
      public function claim(string $workerId, DateTimeImmutable $now, DateTimeImmutable $leaseExpiresAt): ?array { return null; }
      public function ownsActiveLease(PlatformJobLease $lease, DateTimeImmutable $now): bool { return $lease->fencingToken === $this->activeToken; }
      public function heartbeat(PlatformJobLease $lease, DateTimeImmutable $now, DateTimeImmutable $leaseExpiresAt): bool { return false; }
      public function complete(PlatformJobLease $lease, DateTimeImmutable $now, array $result): bool { return false; }
      public function retry(PlatformJobLease $lease, DateTimeImmutable $now, DateTimeImmutable $availableAt, string $errorCode, string $errorSummary): bool { return false; }
      public function deadLetter(PlatformJobLease $lease, DateTimeImmutable $now, string $errorCode, string $errorSummary): bool { return false; }
    }

    final class FakeOutboxStore implements PlatformOutboxStore {
      public bool $transaction = false;
      public array $events = [];
      public array $receipts = [];
      public array $calls = [];
      public int $confirmedWrites = 0;
      public function inTransaction(): bool { return $this->transaction; }
      public function enqueue(array $event): array {
        $key = $event['idempotency_key'];
        if (isset($this->events[$key])) {
          if ($this->events[$key]['payload_hash'] !== $event['payload_hash']) throw new PlatformOutboxIdempotencyConflict();
          return $this->events[$key];
        }
        return $this->events[$key] = $event + ['status' => 'pending', 'replay_count' => 0];
      }
      public function beginSideEffect(array $receipt): array {
        $key = $receipt['effect_type'] . ':' . $receipt['idempotency_key'];
        $this->calls[] = 'begin:' . $receipt['fencing_token'];
        if (isset($this->receipts[$key])) {
          if ($this->receipts[$key]['payload_hash'] !== $receipt['payload_hash']) throw new PlatformOutboxIdempotencyConflict();
          if ($this->receipts[$key]['status'] === 'confirmed') return $this->receipts[$key];
        }
        return $this->receipts[$key] = $receipt + ['status' => 'processing', 'compensation_status' => null];
      }
      public function confirmSideEffect(PlatformJobLease $lease, string $effectType, string $idempotencyKey, string $payloadHash, string $resultJson): array {
        $key = $effectType . ':' . $idempotencyKey;
        $this->calls[] = 'confirm';
        if ($this->receipts[$key]['payload_hash'] !== $payloadHash) throw new PlatformOutboxIdempotencyConflict();
        if ($this->receipts[$key]['status'] === 'confirmed') return $this->receipts[$key];
        $this->confirmedWrites++;
        return $this->receipts[$key] = array_replace($this->receipts[$key], ['status' => 'confirmed', 'result_json' => $resultJson]);
      }
      public function failSideEffect(PlatformJobLease $lease, string $effectType, string $idempotencyKey, string $payloadHash, string $failureClass, string $errorCode, string $errorSummary, bool $recoveryRequired): array {
        $key = $effectType . ':' . $idempotencyKey;
        $this->calls[] = 'fail';
        if ($this->receipts[$key]['status'] === 'confirmed') return $this->receipts[$key];
        return $this->receipts[$key] = array_replace($this->receipts[$key], ['status' => 'failed', 'failure_class' => $failureClass, 'error_code' => $errorCode, 'recovery_required' => $recoveryRequired]);
      }
      public function replay(string $eventKey, string $operator, string $reason): array {
        foreach ($this->events as $key => $event) {
          if ($event['event_key'] !== $eventKey) continue;
          return $this->events[$key] = array_replace($event, ['status' => 'pending', 'replay_count' => $event['replay_count'] + 1, 'replay_operator' => $operator, 'replay_reason' => $reason]);
        }
        throw new PlatformOutboxRecordNotFound();
      }
      public function requestCompensation(string $effectType, string $idempotencyKey, string $operator, string $reason): array {
        return $this->setCompensation($effectType, $idempotencyKey, 'requested');
      }
      public function beginCompensation(string $effectType, string $idempotencyKey, PlatformJobLease $lease): array {
        $this->calls[] = 'compensate:' . $lease->fencingToken;
        return $this->setCompensation($effectType, $idempotencyKey, 'running');
      }
      public function completeCompensation(PlatformJobLease $lease, string $effectType, string $idempotencyKey, string $resultJson): array {
        return $this->setCompensation($effectType, $idempotencyKey, 'compensated', $resultJson);
      }
      public function failCompensation(PlatformJobLease $lease, string $effectType, string $idempotencyKey, string $errorCode, string $errorSummary): array {
        return $this->setCompensation($effectType, $idempotencyKey, 'failed');
      }
      private function setCompensation(string $effectType, string $idempotencyKey, string $status, ?string $result = null): array {
        $key = $effectType . ':' . $idempotencyKey;
        if ($this->receipts[$key]['status'] !== 'confirmed') throw new PlatformOutboxInvalidTransition();
        return $this->receipts[$key] = array_replace($this->receipts[$key], ['compensation_status' => $status, 'compensation_result_json' => $result]);
      }
    }

    $leaseStore = new FakeLeaseStore();
    $runner = new PlatformJobRunner($leaseStore, new PlatformRetryPolicy(), 'worker-a');
    $store = new FakeOutboxStore();
    $service = new PlatformOutboxService($store, $runner);
    $lease1 = new PlatformJobLease(7, 'outbox.dispatch', 'worker-a', 1, 1, 3, new DateTimeImmutable('+5 minutes'), []);
    $lease2 = new PlatformJobLease(7, 'outbox.dispatch', 'worker-b', 2, 2, 3, new DateTimeImmutable('+5 minutes'), []);

    try { $service->enqueue('evt-1', 'sync:1', 'tx-1', 'event-idem', 'member.updated', ['b' => 2, 'a' => 1]); } catch (Throwable $error) { $transactionError = $error->getMessage(); }
    $store->transaction = true;
    $first = $service->enqueue('evt-1', 'sync:1', 'tx-1', 'event-idem', 'member.updated', ['b' => 2, 'a' => 1]);
    $same = $service->enqueue('evt-1', 'sync:1', 'tx-1', 'event-idem', 'member.updated', ['b' => 2, 'a' => 1]);
    try { $service->enqueue('evt-2', 'sync:2', 'tx-1', 'event-idem', 'member.updated', ['a' => 2]); } catch (Throwable $error) { $conflictError = $error->getMessage(); }

    $service->beginSideEffect($lease1, 'evt-1', 'effect-idem', 'message.send', ['member' => 1]);
    $confirmed = $service->confirmSideEffect($lease1, 'message.send', 'effect-idem', ['member' => 1], ['external_id' => 'one']);
    $confirmedAgain = $service->confirmSideEffect($lease1, 'message.send', 'effect-idem', ['member' => 1], ['external_id' => 'two']);
    $leaseStore->activeToken = 2;
    try { $service->failSideEffect($lease1, 'message.send', 'effect-idem', ['member' => 1], 'ambiguous', 'late', 'late result'); } catch (Throwable $error) { $leaseError = $error->getMessage(); }

    $service->beginSideEffect($lease2, 'evt-1', 'effect-fail', 'ai.call', ['prompt' => 1]);
    $failed = $service->failSideEffect($lease2, 'ai.call', 'effect-fail', ['prompt' => 1], 'permanent', 'provider.rejected', 'rejected', true);
    $replayed = $service->replay('evt-1', 'operator-7', 'retry approved');
    $requested = $service->requestCompensation('message.send', 'effect-idem', 'operator-7', 'customer request');
    $running = $service->beginCompensation($lease2, 'message.send', 'effect-idem');
    $compensated = $service->completeCompensation($lease2, 'message.send', 'effect-idem', ['external_id' => 'undo-one']);

    echo json_encode([
      'transaction_error' => $transactionError,
      'same_hash' => $first['payload_hash'] === $same['payload_hash'],
      'conflict_error' => $conflictError,
      'lease_error' => $leaseError,
      'confirmed_writes' => $store->confirmedWrites,
      'confirmed_result' => json_decode($confirmed['result_json'], true),
      'confirmed_again_result' => json_decode($confirmedAgain['result_json'], true),
      'failed' => $failed,
      'replayed' => $replayed,
      'event_preserved' => $replayed['event_key'] === $first['event_key'] && $replayed['payload_json'] === $first['payload_json'] && $replayed['payload_hash'] === $first['payload_hash'],
      'compensation_states' => [$requested['compensation_status'], $running['compensation_status'], $compensated['compensation_status']],
      'receipt_preserved' => $compensated['status'] === 'confirmed' && json_decode($compensated['result_json'], true)['external_id'] === 'one',
      'calls' => $store->calls,
    ]);
  `);

  assert.equal(output.transaction_error, 'outbox_transaction_required');
  assert.equal(output.same_hash, true);
  assert.equal(output.conflict_error, 'outbox_idempotency_conflict');
  assert.equal(output.lease_error, 'job_lease_lost');
  assert.equal(output.confirmed_writes, 1);
  assert.deepEqual(output.confirmed_result, { external_id: 'one' });
  assert.deepEqual(output.confirmed_again_result, { external_id: 'one' });
  assert.equal(output.failed.failure_class, 'permanent');
  assert.equal(output.failed.recovery_required, true);
  assert.equal(output.replayed.replay_count, 1);
  assert.equal(output.replayed.replay_operator, 'operator-7');
  assert.equal(output.event_preserved, true);
  assert.deepEqual(output.compensation_states, ['requested', 'running', 'compensated']);
  assert.equal(output.receipt_preserved, true);
  assert.deepEqual(output.calls, ['begin:1', 'confirm', 'confirm', 'begin:2', 'fail', 'compensate:2']);
});

test('[validates 9.7-9.9] service checks lease authority before every side-effect mutation', () => {
  const serviceClass = serviceSource.slice(serviceSource.indexOf('final class PlatformOutboxService'));
  for (const method of ['beginSideEffect', 'confirmSideEffect', 'failSideEffect']) {
    const body = serviceClass.match(new RegExp(`public function ${method}\\([\\s\\S]*?\\n    }`))?.[0] ?? '';
    assert.ok(body.indexOf('$this->jobRunner->assertCurrent($lease)') >= 0, method);
    assert.ok(body.indexOf('$this->jobRunner->assertCurrent($lease)') < body.indexOf(`$this->store->${method}`), method);
  }
  assert.match(serviceSource, /WHERE id = \? AND status <> 'confirmed' AND job_id = \? AND worker_id = \? AND fencing_token = \?/);
  assert.match(serviceSource, /WHERE event_key = \? AND job_id = \? AND worker_id = \? AND fencing_token = \?/);
  assert.match(serviceSource, /SET status = 'dispatched'/);
});

test('[validates migration replay evidence] schema exposes verifier fields, lifecycle indexes, and independent compensation', () => {
  assert.match(migrationSource, /CREATE TABLE IF NOT EXISTS platform_outbox_events/);
  assert.match(migrationSource, /status ENUM\('pending', 'processing', 'dispatched', 'failed', 'recovery_required'\)/);
  assert.match(migrationSource, /UNIQUE KEY uq_platform_outbox_event_key \(event_key\)/);
  assert.match(migrationSource, /UNIQUE KEY uq_platform_outbox_idempotency \(idempotency_key\)/);
  assert.match(migrationSource, /KEY idx_platform_outbox_lease \(job_id, fencing_token\)/);
  assert.match(migrationSource, /CREATE TABLE IF NOT EXISTS platform_side_effect_receipts/);
  assert.match(migrationSource, /UNIQUE KEY uq_platform_side_effect_idempotency \(effect_type, idempotency_key\)/);
  assert.match(migrationSource, /compensation_status ENUM\('requested', 'running', 'compensated', 'failed'\)/);
  for (const field of ['event_key', 'source_change_key', 'idempotency_key', 'payload_hash', 'status', 'requires_side_effect', 'expected_side_effect_hash', 'occurred_at']) {
    assert.match(migrationSource, new RegExp(`\\b${field}\\b`));
    assert.match(verifierSource, new RegExp(`\\b${field}\\b`));
  }
  for (const field of ['outbox_event_key', 'effect_type']) {
    assert.match(migrationSource, new RegExp(`\\b${field}\\b`));
    assert.match(verifierSource, new RegExp(`\\b${field}\\b`));
  }
});
