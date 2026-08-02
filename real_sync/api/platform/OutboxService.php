<?php
declare(strict_types=1);

require_once __DIR__ . '/JobRunner.php';

final class PlatformOutboxTransactionRequired extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('outbox_transaction_required');
    }
}

final class PlatformOutboxIdempotencyConflict extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('outbox_idempotency_conflict');
    }
}

final class PlatformOutboxRecordNotFound extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('outbox_record_not_found');
    }
}

final class PlatformOutboxInvalidTransition extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('outbox_invalid_transition');
    }
}

interface PlatformOutboxStore
{
    public function inTransaction(): bool;
    public function enqueue(array $event): array;
    public function beginSideEffect(array $receipt): array;
    public function confirmSideEffect(PlatformJobLease $lease, string $effectType, string $idempotencyKey, string $payloadHash, string $resultJson): array;
    public function failSideEffect(PlatformJobLease $lease, string $effectType, string $idempotencyKey, string $payloadHash, string $failureClass, string $errorCode, string $errorSummary, bool $recoveryRequired): array;
    public function replay(string $eventKey, string $operator, string $reason): array;
    public function requestCompensation(string $effectType, string $idempotencyKey, string $operator, string $reason): array;
    public function beginCompensation(string $effectType, string $idempotencyKey, PlatformJobLease $lease): array;
    public function completeCompensation(PlatformJobLease $lease, string $effectType, string $idempotencyKey, string $resultJson): array;
    public function failCompensation(PlatformJobLease $lease, string $effectType, string $idempotencyKey, string $errorCode, string $errorSummary): array;
}

final class PlatformPdoOutboxStore implements PlatformOutboxStore
{
    public function __construct(private PDO $db)
    {
    }

    public function inTransaction(): bool
    {
        return $this->db->inTransaction();
    }

    public function enqueue(array $event): array
    {
        $existing = $this->eventByIdempotency((string)$event['idempotency_key']);
        if ($existing !== null) {
            $this->assertHash($existing, (string)$event['payload_hash']);
            return $existing;
        }

        $stmt = $this->db->prepare(
            "INSERT INTO platform_outbox_events
                (event_key, source_change_key, business_transaction_key, idempotency_key, event_type,
                 payload_json, payload_hash, requires_side_effect, expected_side_effect_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        try {
            $stmt->execute([
                $event['event_key'], $event['source_change_key'], $event['business_transaction_key'],
                $event['idempotency_key'], $event['event_type'], $event['payload_json'], $event['payload_hash'],
                $event['requires_side_effect'] ? 1 : 0, $event['expected_side_effect_hash'],
            ]);
        } catch (PDOException $error) {
            if ((string)$error->getCode() !== '23000') {
                throw $error;
            }
            $existing = $this->eventByIdempotency((string)$event['idempotency_key']);
            if ($existing === null) {
                throw new PlatformOutboxIdempotencyConflict();
            }
            $this->assertHash($existing, (string)$event['payload_hash']);
            return $existing;
        }
        return $event + ['id' => (int)$this->db->lastInsertId(), 'status' => 'pending', 'replay_count' => 0];
    }

    public function beginSideEffect(array $receipt): array
    {
        return $this->atomic(function () use ($receipt): array {
            $event = $this->event((string)$receipt['outbox_event_key']);
            $eventStatus = $event['status'] === 'dispatched' ? 'dispatched' : 'processing';
            $eventUpdate = $this->db->prepare(
                'UPDATE platform_outbox_events SET status = ?, job_id = ?, worker_id = ?, fencing_token = ?, updated_at = NOW(6) WHERE id = ?'
            );
            $eventUpdate->execute([$eventStatus, $receipt['job_id'], $receipt['worker_id'], $receipt['fencing_token'], $event['id']]);
            $existing = $this->receipt((string)$receipt['effect_type'], (string)$receipt['idempotency_key']);
            if ($existing !== null) {
                $this->assertHash($existing, (string)$receipt['payload_hash']);
                if ($existing['status'] === 'confirmed') {
                    return $existing;
                }
                $stmt = $this->db->prepare(
                    "UPDATE platform_side_effect_receipts
                     SET status = 'processing', job_id = ?, worker_id = ?, fencing_token = ?,
                         failure_class = NULL, error_code = NULL, error_summary = NULL, recovery_required = 0, updated_at = NOW(6)
                     WHERE id = ?"
                );
                $stmt->execute([$receipt['job_id'], $receipt['worker_id'], $receipt['fencing_token'], $existing['id']]);
                return array_replace($receipt, ['id' => (int)$existing['id'], 'status' => 'processing']);
            }
            $stmt = $this->db->prepare(
                "INSERT INTO platform_side_effect_receipts
                    (outbox_event_key, idempotency_key, effect_type, payload_hash, status, job_id, worker_id, fencing_token)
                 VALUES (?, ?, ?, ?, 'processing', ?, ?, ?)"
            );
            $stmt->execute([
                $receipt['outbox_event_key'], $receipt['idempotency_key'], $receipt['effect_type'],
                $receipt['payload_hash'], $receipt['job_id'], $receipt['worker_id'], $receipt['fencing_token'],
            ]);
            return array_replace($receipt, ['id' => (int)$this->db->lastInsertId(), 'status' => 'processing']);
        });
    }

    public function confirmSideEffect(PlatformJobLease $lease, string $effectType, string $idempotencyKey, string $payloadHash, string $resultJson): array
    {
        return $this->atomic(function () use ($lease, $effectType, $idempotencyKey, $payloadHash, $resultJson): array {
            $receipt = $this->requiredReceipt($effectType, $idempotencyKey);
            $this->assertHash($receipt, $payloadHash);
            if ($receipt['status'] === 'confirmed') {
                return $receipt;
            }
            $stmt = $this->db->prepare(
                "UPDATE platform_side_effect_receipts
                 SET status = 'confirmed', result_json = ?, failure_class = NULL, error_code = NULL,
                     error_summary = NULL, recovery_required = 0, confirmed_at = NOW(6), updated_at = NOW(6)
                  WHERE id = ? AND status <> 'confirmed' AND job_id = ? AND worker_id = ? AND fencing_token = ?"
            );
            $stmt->execute([$resultJson, $receipt['id'], $lease->jobId, $lease->workerId, $lease->fencingToken]);
            $this->assertTransition($stmt);
            $event = $this->db->prepare(
                "UPDATE platform_outbox_events SET status = 'dispatched', dispatched_at = NOW(6), recovery_required = 0, updated_at = NOW(6)
                 WHERE event_key = ? AND job_id = ? AND worker_id = ? AND fencing_token = ?"
            );
            $event->execute([$receipt['outbox_event_key'], $lease->jobId, $lease->workerId, $lease->fencingToken]);
            $this->assertTransition($event);
            return array_replace($receipt, ['status' => 'confirmed', 'result_json' => $resultJson]);
        });
    }

    public function failSideEffect(PlatformJobLease $lease, string $effectType, string $idempotencyKey, string $payloadHash, string $failureClass, string $errorCode, string $errorSummary, bool $recoveryRequired): array
    {
        return $this->atomic(function () use ($lease, $effectType, $idempotencyKey, $payloadHash, $failureClass, $errorCode, $errorSummary, $recoveryRequired): array {
            $receipt = $this->requiredReceipt($effectType, $idempotencyKey);
            $this->assertHash($receipt, $payloadHash);
            if ($receipt['status'] === 'confirmed') {
                return $receipt;
            }
            $stmt = $this->db->prepare(
                "UPDATE platform_side_effect_receipts
                 SET status = 'failed', failure_class = ?, error_code = ?, error_summary = ?, recovery_required = ?, updated_at = NOW(6)
                  WHERE id = ? AND status <> 'confirmed' AND job_id = ? AND worker_id = ? AND fencing_token = ?"
            );
            $stmt->execute([$failureClass, $errorCode, $errorSummary, $recoveryRequired ? 1 : 0, $receipt['id'], $lease->jobId, $lease->workerId, $lease->fencingToken]);
            $this->assertTransition($stmt);
            $eventStatus = $recoveryRequired ? 'recovery_required' : 'failed';
            $event = $this->db->prepare(
                'UPDATE platform_outbox_events SET status = ?, failure_class = ?, error_code = ?, error_summary = ?, recovery_required = ?, updated_at = NOW(6)
                 WHERE event_key = ? AND job_id = ? AND worker_id = ? AND fencing_token = ?'
            );
            $event->execute([$eventStatus, $failureClass, $errorCode, $errorSummary, $recoveryRequired ? 1 : 0, $receipt['outbox_event_key'], $lease->jobId, $lease->workerId, $lease->fencingToken]);
            $this->assertTransition($event);
            return array_replace($receipt, ['status' => 'failed', 'failure_class' => $failureClass, 'error_code' => $errorCode, 'recovery_required' => $recoveryRequired ? 1 : 0]);
        });
    }

    public function replay(string $eventKey, string $operator, string $reason): array
    {
        return $this->atomic(function () use ($eventKey, $operator, $reason): array {
            $event = $this->event($eventKey);
            $stmt = $this->db->prepare(
                "UPDATE platform_outbox_events
                 SET status = 'pending', replay_count = replay_count + 1, replay_operator = ?, replay_reason = ?,
                     last_replayed_at = NOW(6), failure_class = NULL, error_code = NULL, error_summary = NULL,
                     recovery_required = 0, dispatched_at = NULL, updated_at = NOW(6)
                 WHERE id = ?"
            );
            $stmt->execute([$operator, $reason, $event['id']]);
            return array_replace($event, ['status' => 'pending', 'replay_count' => (int)$event['replay_count'] + 1, 'replay_operator' => $operator, 'replay_reason' => $reason]);
        });
    }

    public function requestCompensation(string $effectType, string $idempotencyKey, string $operator, string $reason): array
    {
        return $this->compensation($effectType, $idempotencyKey, 'requested', [
            'compensation_operator' => $operator,
            'compensation_reason' => $reason,
            'compensation_requested_at' => self::nowExpression(),
        ]);
    }

    public function beginCompensation(string $effectType, string $idempotencyKey, PlatformJobLease $lease): array
    {
        return $this->compensation($effectType, $idempotencyKey, 'running', [
            'job_id' => $lease->jobId,
            'worker_id' => $lease->workerId,
            'fencing_token' => $lease->fencingToken,
        ]);
    }

    public function completeCompensation(PlatformJobLease $lease, string $effectType, string $idempotencyKey, string $resultJson): array
    {
        return $this->compensation($effectType, $idempotencyKey, 'compensated', [
            'compensation_result_json' => $resultJson,
            'compensation_error_code' => null,
            'compensation_error_summary' => null,
            'compensation_completed_at' => self::nowExpression(),
        ], $lease);
    }

    public function failCompensation(PlatformJobLease $lease, string $effectType, string $idempotencyKey, string $errorCode, string $errorSummary): array
    {
        return $this->compensation($effectType, $idempotencyKey, 'failed', [
            'compensation_error_code' => $errorCode,
            'compensation_error_summary' => $errorSummary,
            'compensation_completed_at' => self::nowExpression(),
        ], $lease);
    }

    private function compensation(string $effectType, string $idempotencyKey, string $status, array $fields = [], ?PlatformJobLease $lease = null): array
    {
        return $this->atomic(function () use ($effectType, $idempotencyKey, $status, $fields, $lease): array {
            $receipt = $this->requiredReceipt($effectType, $idempotencyKey);
            if ($receipt['status'] !== 'confirmed') {
                throw new PlatformOutboxInvalidTransition();
            }
            $assignments = ['compensation_status = ?', 'updated_at = NOW(6)'];
            $params = [$status];
            foreach ($fields as $field => $value) {
                $assignments[] = $field . ($value === self::nowExpression() ? ' = NOW(6)' : ' = ?');
                if ($value !== self::nowExpression()) {
                    $params[] = $value;
                }
            }
            $params[] = $receipt['id'];
            $where = ' WHERE id = ?';
            if ($lease !== null) {
                $where .= ' AND job_id = ? AND worker_id = ? AND fencing_token = ?';
                array_push($params, $lease->jobId, $lease->workerId, $lease->fencingToken);
            }
            $stmt = $this->db->prepare('UPDATE platform_side_effect_receipts SET ' . implode(', ', $assignments) . $where);
            $stmt->execute($params);
            if ($lease !== null) {
                $this->assertTransition($stmt);
            }
            return array_replace($receipt, ['compensation_status' => $status], $fields);
        });
    }

    private function eventByIdempotency(string $idempotencyKey): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM platform_outbox_events WHERE idempotency_key = ? FOR UPDATE');
        $stmt->execute([$idempotencyKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function event(string $eventKey): array
    {
        $stmt = $this->db->prepare('SELECT * FROM platform_outbox_events WHERE event_key = ? FOR UPDATE');
        $stmt->execute([$eventKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new PlatformOutboxRecordNotFound();
        }
        return $row;
    }

    private function receipt(string $effectType, string $idempotencyKey): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM platform_side_effect_receipts WHERE effect_type = ? AND idempotency_key = ? FOR UPDATE');
        $stmt->execute([$effectType, $idempotencyKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function requiredReceipt(string $effectType, string $idempotencyKey): array
    {
        $receipt = $this->receipt($effectType, $idempotencyKey);
        if ($receipt === null) {
            throw new PlatformOutboxRecordNotFound();
        }
        return $receipt;
    }

    private function assertHash(array $row, string $payloadHash): void
    {
        if (!hash_equals((string)$row['payload_hash'], $payloadHash)) {
            throw new PlatformOutboxIdempotencyConflict();
        }
    }

    private function assertTransition(PDOStatement $statement): void
    {
        if ($statement->rowCount() !== 1) {
            throw new PlatformJobLeaseLost();
        }
    }

    private function atomic(Closure $operation): array
    {
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $result = $operation();
            if ($ownsTransaction) {
                $this->db->commit();
            }
            return $result;
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    private static function nowExpression(): string
    {
        return '__NOW_6__';
    }
}

final class PlatformOutboxService
{
    public function __construct(private PlatformOutboxStore $store, private PlatformJobRunner $jobRunner)
    {
    }

    public function enqueue(
        string $eventKey,
        string $sourceChangeKey,
        string $businessTransactionKey,
        string $idempotencyKey,
        string $eventType,
        array $payload,
        bool $requiresSideEffect = false,
        ?string $expectedSideEffectHash = null
    ): array {
        if (!$this->store->inTransaction()) {
            throw new PlatformOutboxTransactionRequired();
        }
        $payloadJson = self::json($payload);
        return $this->store->enqueue([
            'event_key' => self::required($eventKey),
            'source_change_key' => trim($sourceChangeKey) === '' ? null : trim($sourceChangeKey),
            'business_transaction_key' => self::required($businessTransactionKey),
            'idempotency_key' => self::required($idempotencyKey),
            'event_type' => self::required($eventType),
            'payload_json' => $payloadJson,
            'payload_hash' => hash('sha256', $payloadJson),
            'requires_side_effect' => $requiresSideEffect,
            'expected_side_effect_hash' => self::optionalHash($expectedSideEffectHash),
        ]);
    }

    public function beginSideEffect(PlatformJobLease $lease, string $eventKey, string $idempotencyKey, string $effectType, array $payload): array
    {
        $this->jobRunner->assertCurrent($lease);
        $payloadJson = self::json($payload);
        return $this->store->beginSideEffect([
            'outbox_event_key' => self::required($eventKey),
            'idempotency_key' => self::required($idempotencyKey),
            'effect_type' => self::required($effectType),
            'payload_hash' => hash('sha256', $payloadJson),
            'job_id' => $lease->jobId,
            'worker_id' => $lease->workerId,
            'fencing_token' => $lease->fencingToken,
        ]);
    }

    public function confirmSideEffect(PlatformJobLease $lease, string $effectType, string $idempotencyKey, array $payload, array $result): array
    {
        $this->jobRunner->assertCurrent($lease);
        return $this->store->confirmSideEffect($lease, $effectType, $idempotencyKey, self::hash($payload), self::json($result));
    }

    public function failSideEffect(PlatformJobLease $lease, string $effectType, string $idempotencyKey, array $payload, string $failureClass, string $errorCode, string $errorSummary, bool $recoveryRequired = false): array
    {
        $this->jobRunner->assertCurrent($lease);
        return $this->store->failSideEffect(
            $lease,
            $effectType,
            $idempotencyKey,
            self::hash($payload),
            self::failureClass($failureClass),
            self::errorCode($errorCode),
            self::summary($errorSummary),
            $recoveryRequired
        );
    }

    public function replay(string $eventKey, string $operator, string $reason): array
    {
        return $this->store->replay(self::required($eventKey), self::required($operator), self::required($reason));
    }

    public function requestCompensation(string $effectType, string $idempotencyKey, string $operator, string $reason): array
    {
        return $this->store->requestCompensation(self::required($effectType), self::required($idempotencyKey), self::required($operator), self::required($reason));
    }

    public function beginCompensation(PlatformJobLease $lease, string $effectType, string $idempotencyKey): array
    {
        $this->jobRunner->assertCurrent($lease);
        return $this->store->beginCompensation(self::required($effectType), self::required($idempotencyKey), $lease);
    }

    public function completeCompensation(PlatformJobLease $lease, string $effectType, string $idempotencyKey, array $result): array
    {
        $this->jobRunner->assertCurrent($lease);
        return $this->store->completeCompensation($lease, self::required($effectType), self::required($idempotencyKey), self::json($result));
    }

    public function failCompensation(PlatformJobLease $lease, string $effectType, string $idempotencyKey, string $errorCode, string $errorSummary): array
    {
        $this->jobRunner->assertCurrent($lease);
        return $this->store->failCompensation($lease, self::required($effectType), self::required($idempotencyKey), self::errorCode($errorCode), self::summary($errorSummary));
    }

    private static function hash(array $payload): string
    {
        return hash('sha256', self::json($payload));
    }

    private static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function required(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException('outbox_value_required');
        }
        return $value;
    }

    private static function optionalHash(?string $hash): ?string
    {
        if ($hash === null || trim($hash) === '') {
            return null;
        }
        $hash = strtolower(trim($hash));
        if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
            throw new InvalidArgumentException('outbox_hash_invalid');
        }
        return $hash;
    }

    private static function failureClass(string $failureClass): string
    {
        if (!in_array($failureClass, ['transient', 'permanent', 'ambiguous'], true)) {
            throw new InvalidArgumentException('outbox_failure_class_invalid');
        }
        return $failureClass;
    }

    private static function errorCode(string $errorCode): string
    {
        $errorCode = strtolower(trim($errorCode));
        if (!preg_match('/^[a-z][a-z0-9_.-]{0,99}$/', $errorCode)) {
            throw new InvalidArgumentException('outbox_error_code_invalid');
        }
        return $errorCode;
    }

    private static function summary(string $summary): string
    {
        $summary = trim($summary);
        return function_exists('mb_substr') ? mb_substr($summary, 0, 1000) : substr($summary, 0, 1000);
    }
}
