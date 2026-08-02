<?php
declare(strict_types=1);

final class PlatformJobLeaseLost extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('job_lease_lost');
    }
}

final class PlatformJobLease
{
    public function __construct(
        public readonly int $jobId,
        public readonly string $jobType,
        public readonly string $workerId,
        public readonly int $fencingToken,
        public readonly int $attemptCount,
        public readonly int $maxAttempts,
        public readonly DateTimeImmutable $leaseExpiresAt,
        public readonly array $payload
    ) {
        if ($jobId < 1 || $fencingToken < 1 || $attemptCount < 1 || $maxAttempts < 1) {
            throw new InvalidArgumentException('任务租约标识和计数必须为正整数');
        }
        if ($jobType === '' || $workerId === '') {
            throw new InvalidArgumentException('任务类型和 Worker ID 不能为空');
        }
    }

    public static function fromRow(array $row): self
    {
        $payload = json_decode((string)($row['payload_json'] ?? '{}'), true);
        if (!is_array($payload)) {
            throw new UnexpectedValueException('任务载荷必须为 JSON 对象');
        }

        $leaseExpiresAt = $row['lease_expires_at'] ?? '';
        if ($leaseExpiresAt instanceof DateTimeInterface) {
            $leaseExpiresAt = DateTimeImmutable::createFromInterface($leaseExpiresAt);
        } else {
            $leaseExpiresAt = new DateTimeImmutable((string)$leaseExpiresAt);
        }

        return new self(
            (int)($row['id'] ?? 0),
            (string)($row['job_type'] ?? ''),
            (string)($row['worker_id'] ?? ''),
            (int)($row['fencing_token'] ?? 0),
            (int)($row['attempt_count'] ?? 0),
            (int)($row['max_attempts'] ?? 0),
            $leaseExpiresAt,
            $payload
        );
    }

    public function withExpiry(DateTimeImmutable $leaseExpiresAt): self
    {
        return new self(
            $this->jobId,
            $this->jobType,
            $this->workerId,
            $this->fencingToken,
            $this->attemptCount,
            $this->maxAttempts,
            $leaseExpiresAt,
            $this->payload
        );
    }
}
