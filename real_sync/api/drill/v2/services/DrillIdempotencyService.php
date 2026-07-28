<?php
declare(strict_types=1);

final class DrillIdempotencyException extends RuntimeException
{
    private int $statusCode;

    public function __construct(string $message, int $statusCode = 400)
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}

final class DrillIdempotencyService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function execute(
        int $userId,
        string $action,
        string $key,
        array $request,
        callable $operation
    ): array {
        $action = trim($action);
        $key = trim($key);
        if ($userId <= 0) {
            throw new DrillIdempotencyException('幂等请求缺少有效用户', 401);
        }
        if ($key === '' || strlen($key) > 128) {
            throw new DrillIdempotencyException('写请求必须提供有效的 Idempotency-Key');
        }
        if ($action === '' || strlen($action) > 80 || !preg_match('/^[a-z0-9._:-]+$/', $action)) {
            throw new DrillIdempotencyException('幂等请求动作无效');
        }
        if ($this->pdo->inTransaction()) {
            throw new LogicException('幂等服务必须拥有业务事务');
        }

        $hash = hash('sha256', json_encode(
            $this->canonicalize($request),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));

        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare(
                'INSERT IGNORE INTO drill_idempotency_keys '
                . '(user_id, action, idempotency_key, request_hash) VALUES (?, ?, ?, ?)'
            );
            $insert->execute([$userId, $action, $key, $hash]);

            if ($insert->rowCount() !== 1) {
                $response = $this->replay($userId, $action, $key, $hash);
                $this->pdo->commit();
                $response['idempotent'] = true;
                return $response;
            }

            $result = $operation();
            if (!is_array($result)) {
                throw new LogicException('幂等业务操作必须返回数组');
            }
            $encoded = json_encode(
                $result,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
            $update = $this->pdo->prepare(
                'UPDATE drill_idempotency_keys SET response_json = ? '
                . 'WHERE user_id = ? AND action = ? AND idempotency_key = ?'
            );
            $update->execute([$encoded, $userId, $action, $key]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('幂等响应保存失败');
            }

            $this->pdo->commit();
            $result['idempotent'] = false;
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function replay(int $userId, string $action, string $key, string $hash): array
    {
        $select = $this->pdo->prepare(
            'SELECT request_hash, response_json FROM drill_idempotency_keys '
            . 'WHERE user_id = ? AND action = ? AND idempotency_key = ? FOR UPDATE'
        );
        $select->execute([$userId, $action, $key]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new DrillIdempotencyException('幂等请求状态不可用', 409);
        }
        if (!hash_equals((string) $row['request_hash'], $hash)) {
            throw new DrillIdempotencyException('Idempotency-Key 已用于不同请求', 409);
        }

        $response = json_decode((string) ($row['response_json'] ?? ''), true);
        if (!is_array($response)) {
            throw new DrillIdempotencyException('同一写请求正在处理中', 409);
        }
        return $response;
    }

    private function canonicalize($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        if ($value === []) {
            return [];
        }

        $keys = array_keys($value);
        $isList = $keys === range(0, count($value) - 1);
        if (!$isList) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }
}
