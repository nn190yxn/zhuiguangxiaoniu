import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { test } from 'node:test';

const root = new URL('..', import.meta.url);
const hasPhp = spawnSync('php', ['-v'], { encoding: 'utf8' }).status === 0;
const runnerSource = readFileSync(new URL('../api/platform/JobRunner.php', import.meta.url), 'utf8');
const migrationSource = readFileSync(new URL('../database/migrations/202607310010_platform_jobs.sql', import.meta.url), 'utf8');

function runPhp(source) {
  const result = spawnSync('php', ['-d', 'display_errors=1', '-r', source], {
    cwd: root,
    encoding: 'utf8',
    timeout: 10_000,
  });
  assert.equal(result.status, 0, result.stderr);
  return JSON.parse(result.stdout);
}

test('[validates 9.3, 9.7, 9.8] expired leases reject late worker results', { skip: !hasPhp }, () => {
  const output = runPhp(String.raw`
    require 'api/platform/JobRunner.php';

    final class FakeJobStore implements PlatformJobStore {
      public array $job = [
        'id' => 1,
        'job_type' => 'platform.test',
        'payload_json' => '{"value":1}',
        'status' => 'pending',
        'attempt_count' => 0,
        'max_attempts' => 3,
        'fencing_token' => 0,
      ];
      public array $actions = [];
      public function claim(string $workerId, DateTimeImmutable $now, DateTimeImmutable $leaseExpiresAt): ?array {
        $eligible = in_array($this->job['status'], ['pending', 'retry_wait'], true)
          || ($this->job['status'] === 'running' && $this->job['lease_expires_at'] <= $now);
        if (!$eligible || $this->job['attempt_count'] >= $this->job['max_attempts']) return null;
        $this->job['status'] = 'running';
        $this->job['worker_id'] = $workerId;
        $this->job['attempt_count']++;
        $this->job['fencing_token']++;
        $this->job['lease_expires_at'] = $leaseExpiresAt;
        return $this->job + ['lease_expires_at' => $leaseExpiresAt->format('Y-m-d H:i:s.u')];
      }
      public function ownsActiveLease(PlatformJobLease $lease, DateTimeImmutable $now): bool { return $this->valid($lease, $now); }
      public function heartbeat(PlatformJobLease $lease, DateTimeImmutable $now, DateTimeImmutable $leaseExpiresAt): bool {
        if (!$this->valid($lease, $now)) return false;
        $this->job['lease_expires_at'] = $leaseExpiresAt;
        $this->actions[] = 'heartbeat';
        return true;
      }
      public function complete(PlatformJobLease $lease, DateTimeImmutable $now, array $result): bool {
        if (!$this->valid($lease, $now)) return false;
        $this->job['status'] = 'succeeded';
        $this->actions[] = 'complete:' . $lease->fencingToken;
        return true;
      }
      public function retry(PlatformJobLease $lease, DateTimeImmutable $now, DateTimeImmutable $availableAt, string $errorCode, string $errorSummary): bool {
        if (!$this->valid($lease, $now)) return false;
        $this->job['status'] = 'retry_wait';
        $this->job['available_at'] = $availableAt;
        $this->actions[] = 'retry:' . $lease->attemptCount;
        return true;
      }
      public function deadLetter(PlatformJobLease $lease, DateTimeImmutable $now, string $errorCode, string $errorSummary): bool {
        if (!$this->valid($lease, $now)) return false;
        $this->job['status'] = 'dead_letter';
        $this->actions[] = 'dead:' . $lease->attemptCount;
        return true;
      }
      private function valid(PlatformJobLease $lease, DateTimeImmutable $now): bool {
        return $this->job['status'] === 'running'
          && $this->job['worker_id'] === $lease->workerId
          && $this->job['fencing_token'] === $lease->fencingToken
          && $this->job['lease_expires_at'] > $now;
      }
    }

    $now = new DateTimeImmutable('2026-08-01T00:00:00Z');
    $clock = static function () use (&$now): DateTimeImmutable { return $now; };
    $store = new FakeJobStore();
    $firstRunner = new PlatformJobRunner($store, new PlatformRetryPolicy(), 'worker-a', 300, 60, $clock);
    $first = $firstRunner->claim();
    $now = $now->modify('+241 seconds');
    $heartbeatDue = $firstRunner->heartbeatDue($first);
    $first = $firstRunner->heartbeat($first);
    $now = $now->modify('+301 seconds');
    $secondRunner = new PlatformJobRunner($store, new PlatformRetryPolicy(), 'worker-b', 300, 60, $clock);
    $second = $secondRunner->claim();
    $lateError = null;
    try { $firstRunner->complete($first); } catch (PlatformJobLeaseLost $error) { $lateError = $error->getMessage(); }
    $secondRunner->complete($second, ['ok' => true]);

    echo json_encode([
      'first_token' => $first->fencingToken,
      'second_token' => $second->fencingToken,
      'heartbeat_due' => $heartbeatDue,
      'late_error' => $lateError,
      'status' => $store->job['status'],
      'actions' => $store->actions,
    ]);
  `);

  assert.equal(output.first_token, 1);
  assert.equal(output.second_token, 2);
  assert.equal(output.heartbeat_due, true);
  assert.equal(output.late_error, 'job_lease_lost');
  assert.equal(output.status, 'succeeded');
  assert.deepEqual(output.actions, ['heartbeat', 'complete:2']);
});

test('[validates 9.3, 9.4] retries are finite and end in dead letter', { skip: !hasPhp }, () => {
  const output = runPhp(String.raw`
    require 'api/platform/RetryPolicy.php';
    $policy = new PlatformRetryPolicy(30, 3600);
    echo json_encode([
      $policy->decision(1, 3),
      $policy->decision(2, 3),
      $policy->decision(3, 3),
      $policy->decision(20, 30),
    ]);
  `);

  assert.deepEqual(output, [
    { action: 'retry', delay_seconds: 30 },
    { action: 'retry', delay_seconds: 60 },
    { action: 'dead_letter', delay_seconds: null },
    { action: 'retry', delay_seconds: 3600 },
  ]);
});

test('[validates 9.7, 9.8] persistent transitions require the active fencing token and lease', () => {
  const fencedConditions = runnerSource.match(/worker_id = \? AND fencing_token = \? AND lease_expires_at > \?/g) ?? [];
  assert.ok(fencedConditions.length >= 3);
  assert.match(runnerSource, /SELECT \* FROM platform_jobs[\s\S]*LIMIT 1 FOR UPDATE/);
  assert.match(runnerSource, /\$fencingToken = \(int\)\$job\['fencing_token'\] \+ 1/);
  assert.match(runnerSource, /INSERT INTO platform_job_runs/);
  assert.match(runnerSource, /recovery_required' => 1/);
  assert.match(runnerSource, /lease_expired_after_final_attempt/);
});

test('[validates 9.3-9.5] migration preserves idempotency, leases, retries, and run summaries', () => {
  assert.match(migrationSource, /CREATE TABLE IF NOT EXISTS platform_jobs/);
  assert.match(migrationSource, /UNIQUE KEY uq_platform_jobs_idempotency \(job_type, idempotency_key\)/);
  assert.match(migrationSource, /status ENUM\('pending', 'running', 'retry_wait', 'succeeded', 'dead_letter', 'cancelled'\)/);
  assert.match(migrationSource, /fencing_token BIGINT UNSIGNED NOT NULL DEFAULT 0/);
  assert.match(migrationSource, /KEY idx_platform_jobs_claim \(status, available_at, priority, id\)/);
  assert.match(migrationSource, /CREATE TABLE IF NOT EXISTS platform_job_runs/);
  assert.match(migrationSource, /UNIQUE KEY uq_platform_job_runs_fence \(job_id, fencing_token\)/);
});
