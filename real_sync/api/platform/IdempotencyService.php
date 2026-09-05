<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/kernel/ApiException.php';
require_once dirname(__DIR__) . '/kernel/ApiResponse.php';
require_once dirname(__DIR__) . '/kernel/ExceptionMapper.php';
require_once dirname(__DIR__) . '/kernel/RequestContext.php';

final class PlatformIdempotencyResult
{
    public function __construct(
        private int $httpStatus,
        private array $payload,
        private bool $replayed
    ) {
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function payload(): array
    {
        return $this->payload;
    }

    public function replayed(): bool
    {
        return $this->replayed;
    }

    public function send(): never
    {
        http_response_code($this->httpStatus);
        header('Content-Type: application/json; charset=utf-8');
        if (isset($this->payload['request_id'])) {
            header('X-Request-ID: ' . $this->payload['request_id']);
        }
        echo json_encode($this->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }
}

final class PlatformIdempotencyService
{
    public function __construct(private PDO $db)
    {
    }

    public function execute(
        PlatformRequestContext $context,
        string $operation,
        string $businessScope,
        string $idempotencyKey,
        array $request,
        callable $callback,
        int $ttlSeconds = 86400
    ): PlatformIdempotencyResult {
        [$actorType, $actorId] = self::actor($context);
        $operation = self::label($operation, 80, 'operation');
        $businessScope = self::label($businessScope, 160, 'business scope');
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 256) {
            throw new PlatformApiException(400, 'idempotency_key_invalid', '写请求必须提供有效的 Idempotency-Key');
        }
        if ($ttlSeconds < 60 || $ttlSeconds > 2592000) {
            throw new InvalidArgumentException('幂等有效期必须在 60 秒至 30 天之间');
        }
        if ($this->db->inTransaction()) {
            throw new LogicException('幂等执行器必须拥有业务事务');
        }

        $keyHash = hash('sha256', $idempotencyKey);
        $fingerprint = self::fingerprint($request);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);
        $identity = [$actorType, $actorId, $operation, $businessScope, $keyHash];

        $this->db->beginTransaction();
        try {
            $insert = $this->db->prepare(
                'INSERT IGNORE INTO platform_idempotency_records '
                . '(actor_type, actor_id, operation, business_scope, idempotency_key_hash, request_fingerprint, request_id, status, expires_at) '
                . "VALUES (?, ?, ?, ?, ?, ?, ?, 'processing', ?)"
            );
            $insert->execute([...$identity, $fingerprint, $context->requestId(), $expiresAt]);

            if ($insert->rowCount() !== 1) {
                $existing = $this->lockRecord($identity);
                if ($existing === null) {
                    throw new PlatformApiException(409, 'idempotency_state_unavailable', '幂等请求状态暂时不可用');
                }
                if (strtotime((string) $existing['expires_at']) <= time()) {
                    $this->resetExpired($identity, $fingerprint, $context->requestId(), $expiresAt);
                } else {
                    $result = $this->replay($existing, $fingerprint);
                    $this->db->commit();
                    return $result;
                }
            }

            $this->db->exec('SAVEPOINT platform_idempotency_operation');
            try {
                $response = $callback();
                if (!$response instanceof PlatformApiResponse) {
                    throw new LogicException('幂等业务操作必须返回 PlatformApiResponse');
                }
            } catch (Throwable $error) {
                $this->db->exec('ROLLBACK TO SAVEPOINT platform_idempotency_operation');
                $response = PlatformExceptionMapper::response($error, $context);
                $this->complete($identity, $fingerprint, 'failed', $response);
                $this->db->commit();
                return new PlatformIdempotencyResult($response->httpStatus(), $response->payload(), false);
            }

            $this->complete($identity, $fingerprint, 'completed', $response);
            $this->db->commit();
            return new PlatformIdempotencyResult($response->httpStatus(), $response->payload(), false);
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public static function fingerprint(array $request): string
    {
        $json = json_encode(self::canonicalize($request), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return hash('sha256', $json);
    }

    private function lockRecord(array $identity): ?array
    {
        $select = $this->db->prepare(
            'SELECT request_fingerprint, request_id, status, http_status, response_json, expires_at '
            . 'FROM platform_idempotency_records '
            . 'WHERE actor_type = ? AND actor_id = ? AND operation = ? AND business_scope = ? AND idempotency_key_hash = ? FOR UPDATE'
        );
        $select->execute($identity);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function resetExpired(array $identity, string $fingerprint, string $requestId, string $expiresAt): void
    {
        $update = $this->db->prepare(
            "UPDATE platform_idempotency_records SET request_fingerprint = ?, request_id = ?, status = 'processing', "
            . 'http_status = NULL, response_json = NULL, created_at = CURRENT_TIMESTAMP(6), completed_at = NULL, expires_at = ? '
            . 'WHERE actor_type = ? AND actor_id = ? AND operation = ? AND business_scope = ? AND idempotency_key_hash = ?'
        );
        $update->execute([$fingerprint, $requestId, $expiresAt, ...$identity]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('过期幂等记录重置失败');
        }
    }

    private function replay(array $record, string $fingerprint): PlatformIdempotencyResult
    {
        if (!hash_equals((string) $record['request_fingerprint'], $fingerprint)) {
            throw new PlatformApiException(409, 'idempotency_fingerprint_conflict', 'Idempotency-Key 已用于不同请求');
        }
        if ($record['status'] === 'processing') {
            throw new PlatformApiException(409, 'idempotency_in_progress', '同一写请求正在处理中', [
                'request_id' => (string) $record['request_id'],
            ]);
        }
        $payload = json_decode((string) ($record['response_json'] ?? ''), true);
        if (!is_array($payload) || !is_int($record['http_status']) && !ctype_digit((string) $record['http_status'])) {
            throw new PlatformApiException(409, 'idempotency_snapshot_unavailable', '首次请求结果暂时不可用');
        }
        return new PlatformIdempotencyResult((int) $record['http_status'], $payload, true);
    }

    private function complete(array $identity, string $fingerprint, string $status, PlatformApiResponse $response): void
    {
        $update = $this->db->prepare(
            'UPDATE platform_idempotency_records SET status = ?, http_status = ?, response_json = ?, completed_at = CURRENT_TIMESTAMP(6) '
            . 'WHERE actor_type = ? AND actor_id = ? AND operation = ? AND business_scope = ? '
            . 'AND idempotency_key_hash = ? AND request_fingerprint = ?'
        );
        $update->execute([
            $status,
            $response->httpStatus(),
            $response->json(),
            ...$identity,
            $fingerprint,
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('幂等响应快照保存失败');
        }
    }

    private static function actor(PlatformRequestContext $context): array
    {
        $values = $context->toArray();
        if (($values['actor_staff_id'] ?? 0) > 0) {
            return ['staff', (int) $values['actor_staff_id']];
        }
        if (($values['actor_user_id'] ?? 0) > 0) {
            return ['user', (int) $values['actor_user_id']];
        }
        throw new PlatformApiException(401, 'idempotency_actor_required', '幂等请求缺少有效身份');
    }

    private static function label(string $value, int $maxLength, string $field): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > $maxLength || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/', $value) !== 1) {
            throw new InvalidArgumentException("Invalid idempotency {$field}");
        }
        return $value;
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }
        return $value;
    }
}
