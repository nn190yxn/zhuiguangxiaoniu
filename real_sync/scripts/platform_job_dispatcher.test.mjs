import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { test } from 'node:test';

const root = new URL('..', import.meta.url);
const queueSource = readFileSync(new URL('../api/platform/JobQueue.php', import.meta.url), 'utf8');
const dispatcherSource = readFileSync(new URL('../api/platform/JobDispatcher.php', import.meta.url), 'utf8');
const workerSource = readFileSync(new URL('./platform-job-worker.php', import.meta.url), 'utf8');
const healthSource = readFileSync(new URL('../api/platform/HealthService.php', import.meta.url), 'utf8');
const migrationSource = readFileSync(new URL('../database/migrations/202607310012_platform_job_dispatch.sql', import.meta.url), 'utf8');
const registrySource = readFileSync(new URL('../api/platform/jobs/registry.php', import.meta.url), 'utf8');
const reminderAdapterSource = readFileSync(new URL('../api/reminder/reminder-worker.php', import.meta.url), 'utf8');
const wecomAdapterSource = readFileSync(new URL('../api/wecom/sync-worker.php', import.meta.url), 'utf8');
const skillAdapterSource = readFileSync(new URL('../api/skill/skill-worker.php', import.meta.url), 'utf8');
const skillUploadSource = readFileSync(new URL('../api/skill/upload-recording.php', import.meta.url), 'utf8');
const drillAdapterSource = readFileSync(new URL('./drill-governance-worker.php', import.meta.url), 'utf8');
const hasPhp = spawnSync('php', ['-v'], { encoding: 'utf8' }).status === 0;

function runPhp(source) {
  const result = spawnSync('php', ['-d', 'display_errors=1', '-r', source], {
    cwd: root,
    env: { ...process.env, DB_PASSWORD: 'test-only', JWT_SECRET: 'test-only' },
    encoding: 'utf8',
    timeout: 10_000,
  });
  assert.equal(result.status, 0, result.stderr);
  return JSON.parse(result.stdout);
}

test('队列要求事务并以规范 JSON 摘要实现幂等', { skip: !hasPhp }, () => {
  const output = runPhp(String.raw`
    require 'api/platform/JobQueue.php';
    final class QueueFake implements PlatformJobQueueStore {
      public bool $transaction = false; public array $jobs = [];
      public function inTransaction(): bool { return $this->transaction; }
      public function enqueue(array $job): array {
        foreach ($this->jobs as $existing) {
          if ($existing['job_type'] === $job['job_type'] && $existing['idempotency_key'] === $job['idempotency_key']) {
            if ($existing['payload_hash'] !== $job['payload_hash']) throw new PlatformJobIdempotencyConflict();
            return $existing;
          }
        }
        $job['id'] = count($this->jobs) + 1; $this->jobs[] = $job; return $job;
      }
    }
    $store = new QueueFake(); $service = new PlatformJobQueueService($store); $outside = null;
    try { $service->enqueue('a', 'x', '1', 'key', ['b'=>2,'a'=>1]); } catch (Throwable $e) { $outside = $e->getMessage(); }
    $store->transaction = true;
    $first = $service->enqueue('a', 'x', '1', 'key', ['b'=>2,'a'=>1]);
    $same = $service->enqueue('a', 'x', '1', 'key', ['a'=>1,'b'=>2]);
    $conflict = null;
    try { $service->enqueue('a', 'x', '1', 'key', ['a'=>9]); } catch (Throwable $e) { $conflict = $e->getMessage(); }
    echo json_encode(['outside'=>$outside, 'same_id'=>$same['id'], 'count'=>count($store->jobs), 'hash'=>$first['payload_hash'], 'conflict'=>$conflict]);
  `);
  assert.equal(output.outside, 'platform_job_transaction_required');
  assert.equal(output.same_id, 1);
  assert.equal(output.count, 1);
  assert.equal(output.hash.length, 64);
  assert.equal(output.conflict, 'platform_job_idempotency_conflict');
});

test('dispatcher 按类型执行、暴露租约上下文并分类失败', { skip: !hasPhp }, () => {
  const output = runPhp(String.raw`
    require 'api/platform/JobDispatcher.php';
    final class DispatcherFake implements PlatformJobStore {
      public array $actions = []; public int $index = 0;
      public array $rows = [
        ['id'=>1,'job_type'=>'ok','payload_json'=>'{}','attempt_count'=>0,'max_attempts'=>3,'fencing_token'=>0,'status'=>'pending'],
        ['id'=>2,'job_type'=>'transient','payload_json'=>'{}','attempt_count'=>0,'max_attempts'=>3,'fencing_token'=>0,'status'=>'pending'],
        ['id'=>3,'job_type'=>'ambiguous','payload_json'=>'{}','attempt_count'=>0,'max_attempts'=>3,'fencing_token'=>0,'status'=>'pending'],
      ];
      public function claim(string $workerId, DateTimeImmutable $now, DateTimeImmutable $expires): ?array {
        foreach ($this->rows as &$row) if ($row['status'] === 'pending') { $row['status']='running'; $row['worker_id']=$workerId; $row['attempt_count']++; $row['fencing_token']++; $row['lease_expires_at']=$expires->format('c'); return $row; }
        return null;
      }
      public function ownsActiveLease(PlatformJobLease $lease, DateTimeImmutable $now): bool { return true; }
      public function heartbeat(PlatformJobLease $lease, DateTimeImmutable $now, DateTimeImmutable $expires): bool { $this->actions[]='heartbeat'; return true; }
      public function complete(PlatformJobLease $lease, DateTimeImmutable $now, array $result): bool { $this->actions[]='complete'; return true; }
      public function retry(PlatformJobLease $lease, DateTimeImmutable $now, DateTimeImmutable $at, string $code, string $summary): bool { $this->actions[]='retry'; return true; }
      public function deadLetter(PlatformJobLease $lease, DateTimeImmutable $now, string $code, string $summary): bool { $this->actions[]='dead:' . $code; return true; }
    }
    $store = new DispatcherFake(); $runner = new PlatformJobRunner($store, new PlatformRetryPolicy(), 'worker', 300, 60);
    $dispatcher = new PlatformJobDispatcher($runner, [
      'ok' => static function (PlatformJobExecutionContext $context, array $payload): array { $context->assertCurrent(); $context->heartbeatIfDue(); return ['ok'=>true]; },
      'transient' => static function (): array { throw new PlatformJobTransientFailure('temporary'); },
      'ambiguous' => static function (): array { throw new PlatformJobAmbiguousFailure('provider uncertain'); },
    ], static function (PlatformJobLease $lease, string $code, string $summary) use ($store): void { $store->deadLetter($lease, new DateTimeImmutable(), $code, $summary); });
    echo json_encode($dispatcher->run(3) + ['actions'=>$store->actions]);
  `);
  assert.equal(output.claimed, 3);
  assert.equal(output.succeeded, 1);
  assert.equal(output.retried, 1);
  assert.equal(output.dead_lettered, 1);
  assert.ok(output.actions.includes('retry'));
  assert.ok(output.actions.some((value) => value === 'dead:ambiguous_external_result'));
});

test('CLI 边界、迁移完整性和健康积压契约', () => {
  assert.match(workerSource, /PHP_SAPI !== 'cli'/);
  assert.match(workerSource, /preg_match\('\/\^--max-jobs=/);
  assert.match(dispatcherSource, /maxJobs > 100/);
  assert.match(dispatcherSource, /PlatformJobAmbiguousFailure/);
  assert.match(migrationSource, /ADD COLUMN payload_hash CHAR\(64\) NULL/);
  assert.match(migrationSource, /SHA2\(payload_json, 256\)/);
  assert.match(healthSource, /platform_jobs/);
  assert.match(healthSource, /status IN \('pending', 'retry_wait'\)/);
  assert.match(healthSource, /backlog_degraded/);
  assert.match(healthSource, /oldest_pending_age_seconds/);
  assert.match(healthSource, /age >= 300/);
  assert.doesNotMatch(healthSource, /platform_job_leases/);
});

test('平台基础批次未触碰冻结目录', () => {
  const allowed = [queueSource, dispatcherSource, workerSource, healthSource, migrationSource];
  assert.equal(allowed.some((source) => /api\/workload|api\/recruitment|recruitment_/.test(source)), false);
});

test('首批非冻结 Worker 通过事务 Adapter 进入统一队列', () => {
  assert.match(reminderAdapterSource, /reminder\.schedule\.tick/);
  assert.match(wecomAdapterSource, /wecom\.members\.sync/);
  assert.match(skillAdapterSource, /skill\.review\.process/);
  assert.match(drillAdapterSource, /drill\.governance\.expire_audio/);
  for (const source of [reminderAdapterSource, wecomAdapterSource, skillAdapterSource, drillAdapterSource]) {
    assert.match(source, /beginTransaction\(\)/);
    assert.match(source, /PlatformJobQueueService/);
  }
  assert.match(drillAdapterSource, /if \(\$dryRun\)/);
  assert.match(skillUploadSource, /skill\.review\.process/);
  assert.match(skillUploadSource, /beginTransaction\(\)/);
});

test('registry 注册七类 Handler 且技能路径与部署根目录解耦', { skip: !hasPhp }, () => {
  const output = runPhp(String.raw`
    final class RegistryFakePdo extends PDO { public function __construct() {} }
    $db = new RegistryFakePdo();
    $handlers = require 'api/platform/jobs/registry.php';
    echo json_encode(['types'=>array_keys($handlers), 'classes'=>array_map('get_class', $handlers)]);
  `);
  assert.deepEqual(output.types, [
    'reminder.schedule.tick',
    'wecom.members.sync',
    'skill.review.process',
    'drill.governance.expire_audio',
    'workload.export.process',
    'workload.alert.run',
    'recruitment.resume.process',
  ]);
  assert.deepEqual(output.classes, {
    'reminder.schedule.tick': 'ReminderJobHandler',
    'wecom.members.sync': 'WecomJobHandler',
    'skill.review.process': 'SkillReviewJobHandler',
    'drill.governance.expire_audio': 'DrillGovernanceJobHandler',
    'workload.export.process': 'WorkloadExportJobHandler',
    'workload.alert.run': 'WorkloadAlertJobHandler',
    'recruitment.resume.process': 'RecruitmentResumeJobHandler',
  });
  assert.doesNotMatch(skillAdapterSource + skillUploadSource + registrySource, /\/www\/wwwroot\/122\.51\.223\.46/);
});

test('租约丢失直接中止批次并保留 fencing 边界', () => {
  assert.match(dispatcherSource, /catch \(PlatformJobLeaseLost \$error\)/);
  assert.match(registrySource, /new ReminderJobHandler/);
  assert.match(wecomAdapterSource, /202607310012/);
  assert.match(skillAdapterSource, /202607310012/);
});
