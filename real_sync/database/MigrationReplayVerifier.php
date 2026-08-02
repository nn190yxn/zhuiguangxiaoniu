<?php
declare(strict_types=1);

interface MigrationReplayEvidenceSource
{
    public function collect(DateTimeImmutable $since, DateTimeImmutable $until, int $limit): array;
}

final class PdoMigrationReplayEvidenceSource implements MigrationReplayEvidenceSource
{
    public function __construct(private PDO $db)
    {
    }

    public function collect(DateTimeImmutable $since, DateTimeImmutable $until, int $limit): array
    {
        if ($until < $since) {
            throw new InvalidArgumentException('Evidence window end must not precede its start');
        }
        $limit = max(1, min(10000, $limit));
        $window = [
            'since' => $since->format('Y-m-d H:i:s'),
            'until' => $until->format('Y-m-d H:i:s'),
            'limit' => $limit,
        ];
        $businessAvailable = $this->tableExists('platform_sync_changes');
        $outboxAvailable = $this->tableExists('platform_outbox_events');
        $sideEffectsAvailable = $this->tableExists('platform_side_effect_receipts');

        $business = $businessAvailable ? $this->boundedRows(
            "SELECT id, CONCAT('sync:', id) AS change_key, domain, object_type, object_id,
                    state_version, SHA2(COALESCE(state_json, ''), 256) AS state_hash,
                    0 AS requires_outbox, occurred_at
             FROM platform_sync_changes
             WHERE occurred_at >= ? AND occurred_at <= ?
             ORDER BY occurred_at, id",
            $window,
        ) : ['rows' => [], 'truncated' => false];
        $outbox = $outboxAvailable ? $this->boundedRows(
            'SELECT id, event_key, source_change_key AS change_key, idempotency_key,
                    payload_hash, status, requires_side_effect, expected_side_effect_hash,
                    0 AS change_in_window, occurred_at
             FROM platform_outbox_events
             WHERE occurred_at >= ? AND occurred_at <= ?
             ORDER BY occurred_at, id',
            $window,
        ) : ['rows' => [], 'truncated' => false];
        $sideEffects = $sideEffectsAvailable ? $this->boundedRows(
            'SELECT id, outbox_event_key AS event_key, idempotency_key, effect_type,
                    payload_hash, status, occurred_at
             FROM platform_side_effect_receipts
             WHERE occurred_at >= ? AND occurred_at <= ?
             ORDER BY occurred_at, id',
            $window,
        ) : ['rows' => [], 'truncated' => false];
        $sideEffectsRequired = false;
        foreach ($outbox['rows'] as $row) {
            if ($row['requires_side_effect'] ?? false) {
                $sideEffectsRequired = true;
                break;
            }
        }

        return [
            'window' => $window,
            'source_status' => [
                'business_changes' => ['available' => $businessAvailable, 'required' => true, 'truncated' => $business['truncated']],
                'outbox_events' => ['available' => $outboxAvailable, 'required' => $outboxAvailable, 'truncated' => $outbox['truncated']],
                'side_effects' => ['available' => $sideEffectsAvailable, 'required' => $sideEffectsRequired, 'truncated' => $sideEffects['truncated']],
            ],
            'business_changes' => $business['rows'],
            'outbox_events' => $outbox['rows'],
            'side_effects' => $sideEffects['rows'],
        ];
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() === 1;
    }

    private function boundedRows(string $sql, array $window): array
    {
        $stmt = $this->db->prepare($sql . ' LIMIT ' . ($window['limit'] + 1));
        $stmt->execute([$window['since'], $window['until']]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $truncated = count($rows) > $window['limit'];
        if ($truncated) {
            array_pop($rows);
        }
        return ['rows' => $rows, 'truncated' => $truncated];
    }
}

final class MigrationReplayVerifier
{
    private const SCHEMA_VERSION = 'migration-replay-evidence/v1';

    public function dryRun(array $evidence): array
    {
        return $this->evaluate('dry-run', $evidence);
    }

    public function verify(array $evidence): array
    {
        return $this->evaluate('verify', $evidence);
    }

    public function rollbackPlan(array $evidence): array
    {
        $result = $this->evaluate('rollback-plan', $evidence);
        $result['strategy'] = 'preserving';
        $result['steps'] = [
            ['order' => 1, 'action' => 'freeze_new_writes', 'evidence_required' => false],
            ['order' => 2, 'action' => 'restore_n_minus_one_application', 'evidence_required' => false],
            ['order' => 3, 'action' => 'replay_business_writes', 'evidence_required' => true],
            ['order' => 4, 'action' => 'replay_outbox_events', 'evidence_required' => true],
            ['order' => 5, 'action' => 'reconcile_external_side_effects', 'evidence_required' => true],
            ['order' => 6, 'action' => 'require_zero_blocking_issues', 'evidence_required' => true],
        ];
        return $result;
    }

    private function evaluate(string $mode, array $evidence): array
    {
        $businessChanges = $this->records($evidence, 'business_changes');
        $outboxEvents = $this->records($evidence, 'outbox_events');
        $sideEffects = $this->records($evidence, 'side_effects');
        $issues = $this->sourceIssues($evidence['source_status'] ?? []);
        $actions = [];

        $changesByKey = [];
        foreach ($businessChanges as $index => $change) {
            $key = trim((string)($change['change_key'] ?? ''));
            if ($key === '') {
                $issues[] = ['type' => 'invalid_business_change', 'record' => $index, 'field' => 'change_key'];
                continue;
            }
            $hash = $this->hash($change['state_hash'] ?? null);
            if ($hash === null) {
                $issues[] = ['type' => 'invalid_business_change', 'key' => $key, 'field' => 'state_hash'];
            }
            if (isset($changesByKey[$key]) && ($changesByKey[$key]['state_hash'] ?? null) !== $hash) {
                $issues[] = ['type' => 'business_change_conflict', 'key' => $key];
            }
            $changesByKey[$key] = ['state_hash' => $hash, 'requires_outbox' => (bool)($change['requires_outbox'] ?? false)];
        }

        $outboxByKey = [];
        $outboxChangeKeys = [];
        foreach ($outboxEvents as $index => $event) {
            $eventKey = trim((string)($event['event_key'] ?? ''));
            if ($eventKey === '') {
                $issues[] = ['type' => 'invalid_outbox_event', 'record' => $index, 'field' => 'event_key'];
                continue;
            }
            $payloadHash = $this->hash($event['payload_hash'] ?? null);
            if ($payloadHash === null) {
                $issues[] = ['type' => 'invalid_outbox_event', 'key' => $eventKey, 'field' => 'payload_hash'];
            }
            if (isset($outboxByKey[$eventKey]) && ($outboxByKey[$eventKey]['payload_hash'] ?? null) !== $payloadHash) {
                $issues[] = ['type' => 'outbox_event_conflict', 'key' => $eventKey];
            }
            $changeKey = trim((string)($event['change_key'] ?? ''));
            if ($changeKey !== '') {
                $outboxChangeKeys[$changeKey] = true;
                if (($event['change_in_window'] ?? false) && !isset($changesByKey[$changeKey])) {
                    $issues[] = ['type' => 'orphan_outbox_event', 'key' => $eventKey, 'change_key' => $changeKey];
                }
            }
            $status = (string)($event['status'] ?? 'pending');
            if (in_array($status, ['pending', 'processing', 'failed'], true)) {
                $actions[] = ['action' => 'replay_outbox_event', 'event_key' => $eventKey, 'current_status' => $status];
            }
            $outboxByKey[$eventKey] = [
                'payload_hash' => $payloadHash,
                'requires_side_effect' => (bool)($event['requires_side_effect'] ?? false),
                'expected_side_effect_hash' => $this->hash($event['expected_side_effect_hash'] ?? null),
            ];
        }
        foreach ($changesByKey as $changeKey => $change) {
            if ($change['requires_outbox'] && !isset($outboxChangeKeys[$changeKey])) {
                $issues[] = ['type' => 'missing_outbox_event', 'change_key' => $changeKey];
                $actions[] = ['action' => 'rebuild_outbox_event', 'change_key' => $changeKey];
            }
        }

        $confirmedByIdempotencyKey = [];
        $confirmedEventKeys = [];
        foreach ($sideEffects as $index => $effect) {
            $eventKey = trim((string)($effect['event_key'] ?? ''));
            $idempotencyKey = trim((string)($effect['idempotency_key'] ?? ''));
            if ($eventKey === '' || $idempotencyKey === '') {
                $issues[] = ['type' => 'invalid_side_effect', 'record' => $index, 'field' => $eventKey === '' ? 'event_key' : 'idempotency_key'];
                continue;
            }
            $payloadHash = $this->hash($effect['payload_hash'] ?? null);
            if (!isset($outboxByKey[$eventKey])) {
                $issues[] = ['type' => 'orphan_side_effect', 'event_key' => $eventKey, 'idempotency_key' => $idempotencyKey];
            } elseif ($outboxByKey[$eventKey]['expected_side_effect_hash'] !== null
                && $outboxByKey[$eventKey]['expected_side_effect_hash'] !== $payloadHash) {
                $issues[] = ['type' => 'side_effect_hash_mismatch', 'event_key' => $eventKey, 'idempotency_key' => $idempotencyKey];
            }
            $status = (string)($effect['status'] ?? 'pending');
            if ($status === 'confirmed') {
                $confirmedEventKeys[$eventKey] = true;
                if (isset($confirmedByIdempotencyKey[$idempotencyKey])
                    && $confirmedByIdempotencyKey[$idempotencyKey] !== $payloadHash) {
                    $issues[] = ['type' => 'side_effect_idempotency_conflict', 'idempotency_key' => $idempotencyKey];
                }
                $confirmedByIdempotencyKey[$idempotencyKey] = $payloadHash;
            } elseif (in_array($status, ['pending', 'processing', 'failed'], true)) {
                $actions[] = [
                    'action' => 'replay_side_effect',
                    'event_key' => $eventKey,
                    'idempotency_key' => $idempotencyKey,
                    'current_status' => $status,
                ];
            }
        }
        foreach ($outboxByKey as $eventKey => $event) {
            if ($event['requires_side_effect'] && !isset($confirmedEventKeys[$eventKey])) {
                $issues[] = ['type' => 'missing_side_effect_receipt', 'event_key' => $eventKey];
                $actions[] = ['action' => 'reconcile_side_effect', 'event_key' => $eventKey];
            }
        }

        $issues = $this->uniqueSorted($issues);
        $actions = $this->uniqueSorted($actions);
        $canonicalEvidence = [
            'window' => $evidence['window'] ?? null,
            'source_status' => $evidence['source_status'] ?? null,
            'business_changes' => $businessChanges,
            'outbox_events' => $outboxEvents,
            'side_effects' => $sideEffects,
        ];
        $this->canonicalize($canonicalEvidence);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => $mode,
            'ok' => $issues === [],
            'mutations_applied' => false,
            'evidence_id' => hash('sha256', json_encode($canonicalEvidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'window' => $evidence['window'] ?? null,
            'summary' => [
                'business_changes' => count($businessChanges),
                'outbox_events' => count($outboxEvents),
                'side_effects' => count($sideEffects),
                'blocking_issues' => count($issues),
                'planned_replays' => count($actions),
            ],
            'source_status' => $evidence['source_status'] ?? [],
            'issues' => $issues,
            'replay_actions' => $actions,
        ];
    }

    private function records(array $evidence, string $key): array
    {
        $records = $evidence[$key] ?? [];
        if (!is_array($records) || !array_is_list($records)) {
            throw new InvalidArgumentException('Evidence field must be a list: ' . $key);
        }
        foreach ($records as $record) {
            if (!is_array($record)) {
                throw new InvalidArgumentException('Evidence records must be objects: ' . $key);
            }
        }
        return $records;
    }

    private function sourceIssues(mixed $sourceStatus): array
    {
        if (!is_array($sourceStatus)) {
            throw new InvalidArgumentException('Evidence source_status must be an object');
        }
        $issues = [];
        foreach ($sourceStatus as $source => $status) {
            if (!is_array($status)) {
                throw new InvalidArgumentException('Evidence source status must be an object: ' . $source);
            }
            if (($status['required'] ?? false) && !($status['available'] ?? false)) {
                $issues[] = ['type' => 'evidence_source_unavailable', 'source' => (string)$source];
            }
            if ($status['truncated'] ?? false) {
                $issues[] = ['type' => 'evidence_source_truncated', 'source' => (string)$source];
            }
        }
        return $issues;
    }

    private function hash(mixed $value): ?string
    {
        $value = strtolower(trim((string)$value));
        return preg_match('/^[a-f0-9]{64}$/', $value) ? $value : null;
    }

    private function uniqueSorted(array $records): array
    {
        $unique = [];
        foreach ($records as $record) {
            $this->canonicalize($record);
            $unique[json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)] = $record;
        }
        ksort($unique, SORT_STRING);
        return array_values($unique);
    }

    private function canonicalize(array &$value): void
    {
        if (array_is_list($value)) {
            foreach ($value as &$item) {
                if (is_array($item)) {
                    $this->canonicalize($item);
                }
            }
            unset($item);
            return;
        }
        ksort($value, SORT_STRING);
        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->canonicalize($item);
            }
        }
        unset($item);
    }
}
