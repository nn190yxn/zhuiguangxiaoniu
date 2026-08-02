<?php
declare(strict_types=1);

final class LegacyEndpointGovernance
{
    private const REQUIRED_EVIDENCE = [
        'contract_regression_passed',
        'consumer_inventory_complete',
        'replacement_health_verified',
        'rollback_plan_tested',
    ];

    public static function recordInvocation(
        PDO $db,
        array $entry,
        string $requestId,
        ?PlatformApiLogger $logger = null,
        ?PlatformRequestContext $context = null
    ): array {
        $ownsTransaction = !$db->inTransaction();
        try {
            if ($ownsTransaction) {
                $db->beginTransaction();
            }
            $aggregate = $db->prepare(
                'INSERT INTO platform_legacy_endpoints
                    (endpoint, http_method, consumer, domain_code, owner, invocation_count, last_invoked_at)
                 VALUES (?, ?, ?, ?, ?, 0, NULL)
                 ON DUPLICATE KEY UPDATE owner = COALESCE(owner, VALUES(owner)), id = LAST_INSERT_ID(id)'
            );
            $aggregate->execute([
                $entry['endpoint'], $entry['method'], $entry['consumer'], $entry['domain'], $entry['owner'] ?? null,
            ]);
            $endpointId = (int)$db->lastInsertId();
            $invocationKey = hash('sha256', implode('|', [
                $requestId, $entry['endpoint'], $entry['method'], $entry['consumer'], $entry['domain'],
            ]));
            $receipt = $db->prepare(
                'INSERT IGNORE INTO platform_legacy_endpoint_invocations
                    (invocation_key, legacy_endpoint_id, request_id, invoked_at)
                 VALUES (?, ?, ?, CURRENT_TIMESTAMP(6))'
            );
            $receipt->execute([$invocationKey, $endpointId, $requestId]);
            $recorded = $receipt->rowCount() === 1;
            if ($recorded) {
                $update = $db->prepare(
                    'UPDATE platform_legacy_endpoints
                     SET invocation_count = invocation_count + 1, last_invoked_at = CURRENT_TIMESTAMP(6)
                     WHERE id = ?'
                );
                $update->execute([$endpointId]);
            }
            if ($ownsTransaction) {
                $db->commit();
            }
            return ['recorded' => $recorded, 'endpoint_id' => $endpointId, 'invocation_key' => $invocationKey];
        } catch (Throwable $error) {
            if ($ownsTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            if ($logger !== null && $context !== null) {
                $logger->log('warning', 'legacy_endpoint.invocation_record_failed', $context, [
                    'endpoint' => $entry['endpoint'] ?? null,
                    'error_type' => get_class($error),
                ]);
            }
            return ['recorded' => false, 'error' => 'schema_not_ready'];
        }
    }

    public static function evaluateRetirementSnapshot(
        array $snapshot,
        ?DateTimeImmutable $now = null
    ): array {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $blockers = [];
        $add = static function (string $code, string $message) use (&$blockers): void {
            $blockers[] = ['code' => $code, 'message' => $message];
        };
        if (($snapshot['migration_status'] ?? '') !== 'eligible') {
            $add('migration_status_not_eligible', '迁移状态必须为 eligible');
        }
        $windowStart = self::date($snapshot['observation_window_started_at'] ?? null);
        $windowDays = max(1, (int)($snapshot['observation_window_days'] ?? 30));
        if ($windowStart === null || $windowStart->modify('+' . $windowDays . ' days') > $now) {
            $add('observation_window_missing', '连续零调用观察窗尚未完成');
        }
        $lastInvocation = self::date($snapshot['last_invoked_at'] ?? null);
        if (($lastInvocation !== null && ($windowStart === null || $lastInvocation >= $windowStart))
            || ($windowStart === null && (int)($snapshot['invocation_count'] ?? 0) > 0)) {
            $add('invocations_in_observation_window', '观察窗内仍有历史入口调用');
        }
        if (trim((string)($snapshot['replacement_endpoint'] ?? '')) === '') {
            $add('replacement_endpoint_missing', '缺少 replacement endpoint');
        }
        if (($snapshot['replacement_status'] ?? '') !== 'available') {
            $add('replacement_unavailable', 'replacement endpoint 尚未验证可用');
        }
        if (trim((string)($snapshot['owner'] ?? '')) === '') {
            $add('owner_missing', '缺少负责 owner');
        }
        if (($snapshot['approval_status'] ?? '') !== 'approved') {
            $add('retirement_approval_missing', '退役审批尚未批准');
        }
        if (trim((string)($snapshot['rollback_plan'] ?? '')) === '') {
            $add('rollback_plan_missing', '缺少回滚计划');
        }
        $evidence = is_array($snapshot['evidence'] ?? null) ? $snapshot['evidence'] : [];
        $missingEvidence = array_values(array_filter(
            self::REQUIRED_EVIDENCE,
            static fn(string $key): bool => ($evidence[$key] ?? false) !== true
        ));
        if ($missingEvidence !== []) {
            $blockers[] = ['code' => 'evidence_incomplete', 'message' => '退役证据不完整', 'missing' => $missingEvidence];
        }
        return ['eligible' => $blockers === [], 'blockers' => $blockers];
    }

    public static function retirementDecision(PDO $db, int $endpointId): array
    {
        try {
            $statement = $db->prepare(
                'SELECT e.*,
                        a.status AS approval_status,
                        a.rollback_plan,
                        a.evidence_json
                 FROM platform_legacy_endpoints e
                 LEFT JOIN platform_legacy_endpoint_retirement_approvals a ON a.id = (
                     SELECT a2.id FROM platform_legacy_endpoint_retirement_approvals a2
                     WHERE a2.legacy_endpoint_id = e.id ORDER BY a2.id DESC LIMIT 1
                 )
                 WHERE e.id = ?'
            );
            $statement->execute([$endpointId]);
            $snapshot = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($snapshot)) {
                return ['eligible' => false, 'blockers' => [['code' => 'legacy_endpoint_not_found', 'message' => '历史入口不存在']]];
            }
            $snapshot['evidence'] = self::jsonArray($snapshot['evidence_json'] ?? null);
            return self::evaluateRetirementSnapshot($snapshot);
        } catch (Throwable $error) {
            return ['eligible' => false, 'blockers' => [['code' => 'schema_not_ready', 'message' => '历史入口治理结构尚未就绪']]];
        }
    }

    public static function readiness(PDO $db): array
    {
        try {
            $tables = [
                'platform_legacy_endpoints',
                'platform_legacy_endpoint_invocations',
                'platform_legacy_endpoint_retirement_approvals',
                'platform_legacy_endpoint_audit_events',
            ];
            $placeholders = implode(',', array_fill(0, count($tables), '?'));
            $statement = $db->prepare(
                'SELECT TABLE_NAME FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $placeholders . ')'
            );
            $statement->execute($tables);
            $found = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
            $missing = array_values(array_diff($tables, $found));
            return ['status' => $missing === [] ? 'healthy' : 'unhealthy', 'missing_tables' => $missing];
        } catch (Throwable $error) {
            return ['status' => 'unhealthy', 'error' => 'legacy_endpoint_governance_check_failed'];
        }
    }

    public static function list(PDO $db, array $filters = []): array
    {
        $where = [];
        $params = [];
        foreach (['domain_code' => 'domain', 'migration_status' => 'status'] as $column => $key) {
            if (trim((string)($filters[$key] ?? '')) !== '') {
                $where[] = $column . ' = ?';
                $params[] = trim((string)$filters[$key]);
            }
        }
        $sql = 'SELECT * FROM platform_legacy_endpoints' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY domain_code, endpoint, http_method, consumer LIMIT 500';
        $statement = $db->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function updateStatus(
        PDO $db,
        int $endpointId,
        array $changes,
        int $actorStaffId,
        string $requestId
    ): array {
        self::requireActor($actorStaffId);
        $db->beginTransaction();
        try {
            $before = self::endpoint($db, $endpointId, true);
            $status = trim((string)($changes['migration_status'] ?? $before['migration_status']));
            if (!in_array($status, ['active', 'migrating', 'eligible', 'deprecated'], true)) {
                throw new InvalidArgumentException('invalid_migration_status');
            }
            if ($status === 'deprecated') {
                $decision = self::retirementDecision($db, $endpointId);
                if (!$decision['eligible']) {
                    $db->rollBack();
                    return $decision;
                }
            }
            $statement = $db->prepare(
                'UPDATE platform_legacy_endpoints SET migration_status = ?, replacement_endpoint = ?,
                 replacement_status = ?, replacement_checked_at = ?, owner = ?,
                 observation_window_started_at = ?, observation_window_days = ? WHERE id = ?'
            );
            $statement->execute([
                $status,
                self::nullable($changes['replacement_endpoint'] ?? $before['replacement_endpoint']),
                $changes['replacement_status'] ?? $before['replacement_status'],
                self::nullable($changes['replacement_checked_at'] ?? $before['replacement_checked_at']),
                self::nullable($changes['owner'] ?? $before['owner']),
                self::nullable($changes['observation_window_started_at'] ?? $before['observation_window_started_at']),
                max(1, (int)($changes['observation_window_days'] ?? $before['observation_window_days'])),
                $endpointId,
            ]);
            $after = self::endpoint($db, $endpointId);
            self::audit($db, $endpointId, 'legacy_endpoint.status_updated', $actorStaffId, $requestId, $before, $after);
            $db->commit();
            return ['eligible' => true, 'blockers' => [], 'endpoint' => $after];
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }

    public static function submitRetirement(
        PDO $db,
        int $endpointId,
        array $input,
        int $actorStaffId,
        string $requestId
    ): array {
        self::requireActor($actorStaffId);
        $db->beginTransaction();
        try {
            self::endpoint($db, $endpointId, true);
            $idempotencyKey = trim((string)($input['idempotency_key'] ?? ''));
            $rollbackPlan = trim((string)($input['rollback_plan'] ?? ''));
            $evidence = is_array($input['evidence'] ?? null) ? $input['evidence'] : [];
            if ($idempotencyKey === '' || $rollbackPlan === '') {
                throw new InvalidArgumentException('retirement_submission_incomplete');
            }
            $requestHash = hash('sha256', self::json([$endpointId, $rollbackPlan, $evidence]));
            $statement = $db->prepare(
                'INSERT INTO platform_legacy_endpoint_retirement_approvals
                    (legacy_endpoint_id, idempotency_key, request_hash, rollback_plan, evidence_json, submitted_by)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
            );
            $statement->execute([$endpointId, $idempotencyKey, $requestHash, $rollbackPlan, self::json($evidence), $actorStaffId]);
            $created = $statement->rowCount() === 1;
            $approvalId = (int)$db->lastInsertId();
            $approval = self::approval($db, $approvalId);
            if (!hash_equals((string)$approval['request_hash'], $requestHash)) {
                throw new RuntimeException('idempotency_conflict');
            }
            if ($created) {
                self::audit($db, $endpointId, 'legacy_endpoint.retirement_submitted', $actorStaffId, $requestId, null, $approval);
            }
            $decision = self::retirementDecision($db, $endpointId);
            $db->commit();
            return ['approval' => $approval, 'decision' => $decision];
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }

    public static function approveRetirement(
        PDO $db,
        int $approvalId,
        int $actorStaffId,
        string $requestId,
        ?string $note = null
    ): array {
        self::requireActor($actorStaffId);
        $db->beginTransaction();
        try {
            $before = self::approval($db, $approvalId, true);
            if ((int)$before['submitted_by'] === $actorStaffId) {
                throw new RuntimeException('retirement_approval_requires_distinct_reviewer');
            }
            if (!in_array($before['status'], ['submitted', 'approved'], true)) {
                throw new RuntimeException('retirement_approval_not_submitted');
            }
            if ($before['status'] === 'submitted') {
                $statement = $db->prepare(
                    "UPDATE platform_legacy_endpoint_retirement_approvals
                     SET status = 'approved', approved_by = ?, approved_at = CURRENT_TIMESTAMP(6), approval_note = ?
                     WHERE id = ? AND status = 'submitted'"
                );
                $statement->execute([$actorStaffId, self::nullable($note), $approvalId]);
                if ($statement->rowCount() !== 1) {
                    throw new RuntimeException('retirement_approval_state_conflict');
                }
            }
            $after = self::approval($db, $approvalId);
            if ($before['status'] === 'submitted') {
                self::audit($db, (int)$after['legacy_endpoint_id'], 'legacy_endpoint.retirement_approved', $actorStaffId, $requestId, $before, $after);
            }
            $decision = self::retirementDecision($db, (int)$after['legacy_endpoint_id']);
            $db->commit();
            return ['approval' => $after, 'decision' => $decision];
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }

    private static function endpoint(PDO $db, int $endpointId, bool $lock = false): array
    {
        $statement = $db->prepare('SELECT * FROM platform_legacy_endpoints WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute([$endpointId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('legacy_endpoint_not_found');
        }
        return $row;
    }

    private static function approval(PDO $db, int $approvalId, bool $lock = false): array
    {
        $statement = $db->prepare(
            'SELECT * FROM platform_legacy_endpoint_retirement_approvals WHERE id = ?' . ($lock ? ' FOR UPDATE' : '')
        );
        $statement->execute([$approvalId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('retirement_approval_not_found');
        }
        $row['evidence'] = self::jsonArray($row['evidence_json'] ?? null);
        return $row;
    }

    private static function audit(PDO $db, int $endpointId, string $action, int $actor, string $requestId, ?array $before, array $after): void
    {
        $statement = $db->prepare(
            'INSERT INTO platform_legacy_endpoint_audit_events
                (legacy_endpoint_id, action_code, actor_staff_id, request_id, before_json, after_json)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([$endpointId, $action, $actor, $requestId, $before === null ? null : self::json($before), self::json($after)]);
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        try {
            return trim((string)$value) === '' ? null : new DateTimeImmutable((string)$value, new DateTimeZone('UTC'));
        } catch (Throwable $error) {
            return null;
        }
    }

    private static function json(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return $json;
    }

    private static function jsonArray(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    private static function nullable(mixed $value): ?string
    {
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }

    private static function requireActor(int $actorStaffId): void
    {
        if ($actorStaffId <= 0) {
            throw new InvalidArgumentException('actor_staff_id_required');
        }
    }
}
