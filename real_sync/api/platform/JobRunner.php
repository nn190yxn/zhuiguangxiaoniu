<?php
declare(strict_types=1);

require_once __DIR__ . '/JobLease.php';
require_once __DIR__ . '/RetryPolicy.php';

interface PlatformJobStore
{
    public function claim(string $workerId, DateTimeImmutable $now, DateTimeImmutable $leaseExpiresAt): ?array;
    public function ownsActiveLease(PlatformJobLease $lease, DateTimeImmutable $now): bool;
    public function heartbeat(PlatformJobLease $lease, DateTimeImmutable $now, DateTimeImmutable $leaseExpiresAt): bool;
    public function complete(PlatformJobLease $lease, DateTimeImmutable $now, array $result): bool;
    public function retry(PlatformJobLease $lease, DateTimeImmutable $now, DateTimeImmutable $availableAt, string $errorCode, string $errorSummary): bool;
    public function deadLetter(PlatformJobLease $lease, DateTimeImmutable $now, string $errorCode, string $errorSummary): bool;
}

final class PlatformPdoJobStore implements PlatformJobStore
{
    public function __construct(private PDO $db)
    {
    }

    public function claim(string $workerId, DateTimeImmutable $now, DateTimeImmutable $leaseExpiresAt): ?array
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM platform_jobs
                 WHERE (status IN ('pending', 'retry_wait') AND available_at <= ? AND attempt_count < max_attempts)
                    OR (status = 'running' AND lease_expires_at <= ?)
                 ORDER BY priority DESC, available_at ASC, id ASC
                 LIMIT 1 FOR UPDATE"
            );
            $nowSql = self::sqlTime($now);
            $stmt->execute([$nowSql, $nowSql]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$job) {
                $this->db->commit();
                return null;
            }

            if ($job['status'] === 'running' && (int)$job['attempt_count'] >= (int)$job['max_attempts']) {
                $errorCode = 'lease_expired_after_final_attempt';
                $run = $this->db->prepare(
                    "UPDATE platform_job_runs
                     SET status = 'dead_letter', error_code = ?, error_summary = ?, finished_at = ?, updated_at = ?
                     WHERE job_id = ? AND fencing_token = ? AND status = 'running'"
                );
                $run->execute([$errorCode, '最终尝试的租约已过期', $nowSql, $nowSql, (int)$job['id'], (int)$job['fencing_token']]);
                $deadLetter = $this->db->prepare(
                    "UPDATE platform_jobs
                     SET status = 'dead_letter', worker_id = NULL, lease_expires_at = NULL,
                         error_code = ?, error_summary = ?, recovery_required = 1, completed_at = ?, updated_at = ?
                     WHERE id = ? AND status = 'running' AND fencing_token = ?"
                );
                $deadLetter->execute([$errorCode, '最终尝试的租约已过期', $nowSql, $nowSql, (int)$job['id'], (int)$job['fencing_token']]);
                $this->db->commit();
                return null;
            }

            $fencingToken = (int)$job['fencing_token'] + 1;
            $attemptCount = (int)$job['attempt_count'] + 1;
            $update = $this->db->prepare(
                "UPDATE platform_jobs
                 SET status = 'running', worker_id = ?, fencing_token = ?, attempt_count = ?,
                     locked_at = ?, heartbeat_at = ?, lease_expires_at = ?, updated_at = ?
                 WHERE id = ?"
            );
            $update->execute([
                $workerId,
                $fencingToken,
                $attemptCount,
                $nowSql,
                $nowSql,
                self::sqlTime($leaseExpiresAt),
                $nowSql,
                (int)$job['id'],
            ]);

            $run = $this->db->prepare(
                "INSERT INTO platform_job_runs
                    (job_id, attempt_number, worker_id, fencing_token, status, started_at, heartbeat_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 'running', ?, ?, ?, ?)"
            );
            $run->execute([(int)$job['id'], $attemptCount, $workerId, $fencingToken, $nowSql, $nowSql, $nowSql, $nowSql]);
            $this->db->commit();

            $job['worker_id'] = $workerId;
            $job['fencing_token'] = $fencingToken;
            $job['attempt_count'] = $attemptCount;
            $job['lease_expires_at'] = self::sqlTime($leaseExpiresAt);
            return $job;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function ownsActiveLease(PlatformJobLease $lease, DateTimeImmutable $now): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM platform_jobs
             WHERE id = ? AND status = 'running' AND worker_id = ? AND fencing_token = ? AND lease_expires_at > ?"
        );
        $stmt->execute([$lease->jobId, $lease->workerId, $lease->fencingToken, self::sqlTime($now)]);
        return (int)$stmt->fetchColumn() === 1;
    }

    public function heartbeat(PlatformJobLease $lease, DateTimeImmutable $now, DateTimeImmutable $leaseExpiresAt): bool
    {
        $nowSql = self::sqlTime($now);
        $stmt = $this->db->prepare(
            "UPDATE platform_jobs SET heartbeat_at = ?, lease_expires_at = ?, updated_at = ?
             WHERE id = ? AND status = 'running' AND worker_id = ? AND fencing_token = ? AND lease_expires_at > ?"
        );
        $stmt->execute([$nowSql, self::sqlTime($leaseExpiresAt), $nowSql, $lease->jobId, $lease->workerId, $lease->fencingToken, $nowSql]);
        if ($stmt->rowCount() !== 1) {
            return false;
        }
        $run = $this->db->prepare(
            "UPDATE platform_job_runs SET heartbeat_at = ?, updated_at = ?
             WHERE job_id = ? AND worker_id = ? AND fencing_token = ? AND status = 'running'"
        );
        $run->execute([$nowSql, $nowSql, $lease->jobId, $lease->workerId, $lease->fencingToken]);
        return true;
    }

    public function complete(PlatformJobLease $lease, DateTimeImmutable $now, array $result): bool
    {
        return $this->transition($lease, $now, 'succeeded', [
            'result_json' => self::json($result),
            'completed_at' => self::sqlTime($now),
            'error_code' => null,
            'error_summary' => null,
            'recovery_required' => 0,
        ]);
    }

    public function retry(PlatformJobLease $lease, DateTimeImmutable $now, DateTimeImmutable $availableAt, string $errorCode, string $errorSummary): bool
    {
        return $this->transition($lease, $now, 'retry_wait', [
            'available_at' => self::sqlTime($availableAt),
            'error_code' => $errorCode,
            'error_summary' => $errorSummary,
            'recovery_required' => 0,
        ]);
    }

    public function deadLetter(PlatformJobLease $lease, DateTimeImmutable $now, string $errorCode, string $errorSummary): bool
    {
        return $this->transition($lease, $now, 'dead_letter', [
            'completed_at' => self::sqlTime($now),
            'error_code' => $errorCode,
            'error_summary' => $errorSummary,
            'recovery_required' => 1,
        ]);
    }

    private function transition(PlatformJobLease $lease, DateTimeImmutable $now, string $status, array $fields): bool
    {
        $assignments = ["status = ?", 'worker_id = NULL', 'lease_expires_at = NULL', 'updated_at = ?'];
        $params = [$status, self::sqlTime($now)];
        foreach ($fields as $field => $value) {
            $assignments[] = $field . ' = ?';
            $params[] = $value;
        }
        $params = array_merge($params, [$lease->jobId, $lease->workerId, $lease->fencingToken, self::sqlTime($now)]);

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'UPDATE platform_jobs SET ' . implode(', ', $assignments) .
                " WHERE id = ? AND status = 'running' AND worker_id = ? AND fencing_token = ? AND lease_expires_at > ?"
            );
            $stmt->execute($params);
            if ($stmt->rowCount() !== 1) {
                $this->db->rollBack();
                return false;
            }

            $runStatus = $status === 'retry_wait' ? 'failed_retryable' : $status;
            $run = $this->db->prepare(
                "UPDATE platform_job_runs
                 SET status = ?, result_json = ?, error_code = ?, error_summary = ?, finished_at = ?, updated_at = ?
                 WHERE job_id = ? AND worker_id = ? AND fencing_token = ? AND status = 'running'"
            );
            $run->execute([
                $runStatus,
                $fields['result_json'] ?? null,
                $fields['error_code'] ?? null,
                $fields['error_summary'] ?? null,
                self::sqlTime($now),
                self::sqlTime($now),
                $lease->jobId,
                $lease->workerId,
                $lease->fencingToken,
            ]);
            $this->db->commit();
            return true;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    private static function sqlTime(DateTimeImmutable $time): string
    {
        return $time->format('Y-m-d H:i:s.u');
    }

    private static function json(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return $json;
    }
}

final class PlatformJobRunner
{
    private Closure $clock;

    public function __construct(
        private PlatformJobStore $store,
        private PlatformRetryPolicy $retryPolicy,
        private string $workerId,
        private int $leaseSeconds = 300,
        private int $heartbeatSeconds = 60,
        ?callable $clock = null
    ) {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,119}$/', $workerId)) {
            throw new InvalidArgumentException('Worker ID 格式无效');
        }
        if ($leaseSeconds < 2 || $heartbeatSeconds < 1 || $heartbeatSeconds >= $leaseSeconds) {
            throw new InvalidArgumentException('租约与心跳周期配置无效');
        }
        $this->clock = $clock !== null ? Closure::fromCallable($clock) : static fn(): DateTimeImmutable => new DateTimeImmutable('now');
    }

    public static function defaultWorkerId(string $prefix = 'platform'): string
    {
        $host = gethostname() ?: 'unknown';
        return substr($prefix . ':' . $host . ':' . getmypid() . ':' . bin2hex(random_bytes(6)), 0, 120);
    }

    public function claim(): ?PlatformJobLease
    {
        $now = ($this->clock)();
        $row = $this->store->claim($this->workerId, $now, $now->modify('+' . $this->leaseSeconds . ' seconds'));
        return $row === null ? null : PlatformJobLease::fromRow($row);
    }

    public function assertCurrent(PlatformJobLease $lease): void
    {
        if (!$this->store->ownsActiveLease($lease, ($this->clock)())) {
            throw new PlatformJobLeaseLost();
        }
    }

    public function heartbeat(PlatformJobLease $lease): PlatformJobLease
    {
        $now = ($this->clock)();
        $expiresAt = $now->modify('+' . $this->leaseSeconds . ' seconds');
        if (!$this->store->heartbeat($lease, $now, $expiresAt)) {
            throw new PlatformJobLeaseLost();
        }
        return $lease->withExpiry($expiresAt);
    }

    public function complete(PlatformJobLease $lease, array $result = []): void
    {
        if (!$this->store->complete($lease, ($this->clock)(), $result)) {
            throw new PlatformJobLeaseLost();
        }
    }

    public function fail(PlatformJobLease $lease, string $errorCode, string $errorSummary): array
    {
        $errorCode = $this->errorCode($errorCode);
        $errorSummary = trim($errorSummary);
        $errorSummary = function_exists('mb_substr') ? mb_substr($errorSummary, 0, 1000) : substr($errorSummary, 0, 1000);
        $now = ($this->clock)();
        $decision = $this->retryPolicy->decision($lease->attemptCount, $lease->maxAttempts);
        if ($decision['action'] === 'retry') {
            $availableAt = $now->modify('+' . $decision['delay_seconds'] . ' seconds');
            if (!$this->store->retry($lease, $now, $availableAt, $errorCode, $errorSummary)) {
                throw new PlatformJobLeaseLost();
            }
            return $decision + ['available_at' => $availableAt->format(DATE_ATOM)];
        }
        if (!$this->store->deadLetter($lease, $now, $errorCode, $errorSummary)) {
            throw new PlatformJobLeaseLost();
        }
        return $decision + ['recovery_required' => true];
    }

    public function heartbeatDue(PlatformJobLease $lease): bool
    {
        return ($this->clock)() >= $lease->leaseExpiresAt->modify('-' . $this->heartbeatSeconds . ' seconds');
    }

    private function errorCode(string $errorCode): string
    {
        $errorCode = strtolower(trim($errorCode));
        if (!preg_match('/^[a-z][a-z0-9_.-]{0,99}$/', $errorCode)) {
            throw new InvalidArgumentException('任务错误码格式无效');
        }
        return $errorCode;
    }
}
