<?php
declare(strict_types=1);

require_once __DIR__ . '/JobRunner.php';

final class PlatformJobQueueTransactionRequired extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('platform_job_transaction_required');
    }
}

final class PlatformJobIdempotencyConflict extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('platform_job_idempotency_conflict');
    }
}

interface PlatformJobQueueStore
{
    public function inTransaction(): bool;
    public function enqueue(array $job): array;
}

final class PlatformPdoJobQueueStore implements PlatformJobQueueStore
{
    public function __construct(private PDO $db)
    {
    }

    public function inTransaction(): bool
    {
        return $this->db->inTransaction();
    }

    public function enqueue(array $job): array
    {
        if (!$this->db->inTransaction()) {
            throw new PlatformJobQueueTransactionRequired();
        }

        $existing = $this->find((string)$job['job_type'], (string)$job['idempotency_key']);
        if ($existing !== null) {
            if (!hash_equals((string)$existing['payload_hash'], (string)$job['payload_hash'])) {
                throw new PlatformJobIdempotencyConflict();
            }
            return $existing;
        }

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO platform_jobs
                    (job_type, object_type, object_id, idempotency_key, payload_json, payload_hash,
                     status, priority, available_at, max_attempts)
                 VALUES (?, ?, ?, ?, ?, ?, \'pending\', ?, ?, ?)'
            );
            $stmt->execute([
                $job['job_type'], $job['object_type'], $job['object_id'], $job['idempotency_key'],
                $job['payload_json'], $job['payload_hash'], $job['priority'], $job['available_at'], $job['max_attempts'],
            ]);
        } catch (PDOException $error) {
            if ((string)$error->getCode() !== '23000') {
                throw $error;
            }
            $existing = $this->find((string)$job['job_type'], (string)$job['idempotency_key']);
            if ($existing === null || !hash_equals((string)$existing['payload_hash'], (string)$job['payload_hash'])) {
                throw new PlatformJobIdempotencyConflict();
            }
            return $existing;
        }

        return $job + ['id' => (int)$this->db->lastInsertId(), 'status' => 'pending'];
    }

    private function find(string $jobType, string $idempotencyKey): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM platform_jobs WHERE job_type = ? AND idempotency_key = ? LIMIT 1');
        $stmt->execute([$jobType, $idempotencyKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

final class PlatformJobQueueService
{
    public function __construct(private PlatformJobQueueStore $store)
    {
    }

    public function enqueue(
        string $jobType,
        string $objectType,
        string $objectId,
        string $idempotencyKey,
        array $payload,
        int $priority = 0,
        int $maxAttempts = 3,
        ?DateTimeImmutable $availableAt = null
    ): array {
        if (!$this->store->inTransaction()) {
            throw new PlatformJobQueueTransactionRequired();
        }
        if ($jobType === '' || $objectType === '' || $objectId === '' || $idempotencyKey === '') {
            throw new InvalidArgumentException('平台任务标识不能为空');
        }
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('任务最大尝试次数必须为正整数');
        }

        $payloadJson = self::canonicalJson($payload);
        $job = [
            'job_type' => $jobType,
            'object_type' => $objectType,
            'object_id' => $objectId,
            'idempotency_key' => $idempotencyKey,
            'payload_json' => $payloadJson,
            'payload_hash' => hash('sha256', $payloadJson),
            'priority' => $priority,
            'available_at' => ($availableAt ?? new DateTimeImmutable('now'))->format('Y-m-d H:i:s.u'),
            'max_attempts' => $maxAttempts,
        ];
        return $this->store->enqueue($job);
    }

    public static function canonicalJson(array $payload): string
    {
        $normalize = static function ($value) use (&$normalize) {
            if (!is_array($value)) {
                return $value;
            }
            if (array_is_list($value)) {
                return array_map($normalize, $value);
            }
            ksort($value, SORT_STRING);
            foreach ($value as $key => $item) {
                $value[$key] = $normalize($item);
            }
            return $value;
        };
        return json_encode($normalize($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
