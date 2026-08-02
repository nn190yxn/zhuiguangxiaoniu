<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/kernel/bootstrap.php';

interface PlatformSyncStore
{
    public function begin(): void;
    public function commit(): void;
    public function rollback(): void;
    public function findDraft(int $staffId, string $domain, string $objectType, string $objectId, bool $forUpdate): ?array;
    public function saveDraft(array $draft): void;
    public function markDraftDeleted(int $staffId, string $domain, string $objectType, string $objectId, int $draftVersion, string $updatedAt): void;
    public function appendChange(array $change): int;
    public function listChanges(string $scopeHash, array $position, int $limit, array $filters = []): array;
}

final class PlatformPdoSyncStore implements PlatformSyncStore
{
    public function __construct(private PDO $db)
    {
    }

    public function begin(): void
    {
        $this->db->beginTransaction();
    }

    public function commit(): void
    {
        $this->db->commit();
    }

    public function rollback(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    public function findDraft(int $staffId, string $domain, string $objectType, string $objectId, bool $forUpdate): ?array
    {
        $sql = 'SELECT * FROM platform_sync_drafts
                WHERE owner_staff_id = ? AND domain = ? AND object_type = ? AND object_id = ?
                LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '');
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$staffId, $domain, $objectType, $objectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function saveDraft(array $draft): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO platform_sync_drafts
                (owner_staff_id, domain, object_type, object_id, draft_version, base_state_version,
                 payload_json, source_client, source_device_id, status, expires_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'active\', ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 draft_version = VALUES(draft_version),
                 base_state_version = VALUES(base_state_version),
                 payload_json = VALUES(payload_json),
                 source_client = VALUES(source_client),
                 source_device_id = VALUES(source_device_id),
                 status = \'active\',
                 expires_at = VALUES(expires_at),
                 updated_at = VALUES(updated_at)'
        );
        $stmt->execute([
            $draft['owner_staff_id'],
            $draft['domain'],
            $draft['object_type'],
            $draft['object_id'],
            $draft['draft_version'],
            $draft['base_state_version'],
            $draft['payload_json'],
            $draft['source_client'],
            $draft['source_device_id'],
            $draft['expires_at'],
            $draft['created_at'],
            $draft['updated_at'],
        ]);
    }

    public function markDraftDeleted(int $staffId, string $domain, string $objectType, string $objectId, int $draftVersion, string $updatedAt): void
    {
        $stmt = $this->db->prepare(
            "UPDATE platform_sync_drafts
             SET status = 'deleted', draft_version = ?, payload_json = '{}', updated_at = ?
             WHERE owner_staff_id = ? AND domain = ? AND object_type = ? AND object_id = ?"
        );
        $stmt->execute([$draftVersion, $updatedAt, $staffId, $domain, $objectType, $objectId]);
    }

    public function appendChange(array $change): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO platform_sync_changes
                (scope_hash, domain, object_type, object_id, state_version, sync_level, status,
                 state_json, etag, reason, occurred_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $change['scope_hash'],
            $change['domain'],
            $change['object_type'],
            $change['object_id'],
            $change['state_version'],
            $change['sync_level'],
            $change['status'],
            $change['state_json'],
            $change['etag'],
            $change['reason'],
            $change['occurred_at'],
            $change['created_at'],
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function listChanges(string $scopeHash, array $position, int $limit, array $filters = []): array
    {
        $params = [$scopeHash, $position['occurred_at'], $position['occurred_at'], $position['id']];
        $filterSql = '';
        foreach (['domain', 'object_type'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $filterSql .= ' AND ' . $field . ' = ?';
                $params[] = $filters[$field];
            }
        }
        $stmt = $this->db->prepare(
            'SELECT * FROM platform_sync_changes
             WHERE scope_hash = ?
               AND (occurred_at > ? OR (occurred_at = ? AND id > ?))
             ' . $filterSql . '
             ORDER BY occurred_at ASC, id ASC
             LIMIT ' . max(1, min(201, $limit))
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

final class PlatformSyncService
{
    private const MAX_DRAFT_BYTES = 65536;
    private const MAX_DRAFT_TTL_SECONDS = 86400;
    private const DRAFT_FIELDS = [
        'drill' => ['text', 'answers', 'notes', 'attachments', 'step_id', 'metadata'],
        'workload' => ['report_date', 'metrics', 'notes', 'attachments'],
        'recruitment' => ['notes', 'contact_plan', 'tags'],
        'platform' => ['text', 'notes', 'fields', 'attachments', 'metadata'],
    ];

    private Closure $clock;

    public function __construct(
        private PlatformSyncStore $store,
        private string $cursorSecret,
        ?callable $clock = null
    ) {
        if ($cursorSecret === '') {
            throw new InvalidArgumentException('同步游标签名密钥不能为空');
        }
        $this->clock = $clock !== null ? Closure::fromCallable($clock) : static fn(): DateTimeImmutable => new DateTimeImmutable('now');
    }

    public function getDraft(int $staffId, string $domain, string $objectType, string $objectId): ?array
    {
        $this->assertStaffId($staffId);
        [$domain, $objectType, $objectId] = $this->identity($domain, $objectType, $objectId);
        $row = $this->store->findDraft($staffId, $domain, $objectType, $objectId, false);
        if ($row === null || ($row['status'] ?? '') !== 'active' || $this->isExpired((string)$row['expires_at'])) {
            return null;
        }
        return $this->draftResponse($row);
    }

    public function saveDraft(
        int $staffId,
        string $domain,
        string $objectType,
        string $objectId,
        int $expectedDraftVersion,
        int $baseStateVersion,
        array $payload,
        string $sourceClient,
        ?string $sourceDeviceId = null,
        int $ttlSeconds = self::MAX_DRAFT_TTL_SECONDS
    ): array {
        $this->assertStaffId($staffId);
        [$domain, $objectType, $objectId] = $this->identity($domain, $objectType, $objectId);
        if ($expectedDraftVersion < 0 || $baseStateVersion < 0) {
            throw new InvalidArgumentException('草稿版本和基础版本不能为负数');
        }
        $sourceClient = $this->sourceClient($sourceClient);
        $sourceDeviceId = $this->deviceId($sourceDeviceId);
        $payloadJson = $this->payloadJson($domain, $payload);
        $ttlSeconds = max(60, min(self::MAX_DRAFT_TTL_SECONDS, $ttlSeconds));
        $now = ($this->clock)();

        $this->store->begin();
        try {
            $current = $this->store->findDraft($staffId, $domain, $objectType, $objectId, true);
            if ($current !== null && (($current['status'] ?? '') !== 'active' || $this->isExpired((string)$current['expires_at']))) {
                $current = null;
            }
            $currentVersion = $current === null ? 0 : (int)$current['draft_version'];
            if ($expectedDraftVersion !== $currentVersion) {
                throw new PlatformApiException(409, 'draft_version_conflict', '草稿已在其他设备更新', [
                    ...PlatformSyncProtocol::versionConflict($currentVersion, $expectedDraftVersion, [
                        'conflict_type' => 'draft_version',
                        'object_type' => $objectType,
                        'object_id' => $objectId,
                        'authoritative_state' => $current === null ? null : $this->draftResponse($current),
                        'recovery_action' => 'choose_draft_version',
                    ]),
                ]);
            }
            if ($current !== null && $baseStateVersion < (int)$current['base_state_version']) {
                throw new PlatformApiException(409, 'base_version_conflict', '草稿基于较旧的业务状态', [
                    ...PlatformSyncProtocol::versionConflict((int)$current['base_state_version'], $baseStateVersion, [
                        'conflict_type' => 'base_state_version',
                        'object_type' => $objectType,
                        'object_id' => $objectId,
                        'authoritative_state' => $this->draftResponse($current),
                        'recovery_action' => 'refresh_then_choose',
                    ]),
                ]);
            }

            $draft = [
                'owner_staff_id' => $staffId,
                'domain' => $domain,
                'object_type' => $objectType,
                'object_id' => $objectId,
                'draft_version' => PlatformStateVersion::next($currentVersion),
                'base_state_version' => $baseStateVersion,
                'payload_json' => $payloadJson,
                'source_client' => $sourceClient,
                'source_device_id' => $sourceDeviceId,
                'status' => 'active',
                'expires_at' => $now->modify('+' . $ttlSeconds . ' seconds')->format('Y-m-d H:i:s'),
                'created_at' => $current['created_at'] ?? $now->format('Y-m-d H:i:s'),
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ];
            $this->store->saveDraft($draft);
            $this->store->commit();
            return $this->draftResponse($draft);
        } catch (Throwable $error) {
            $this->store->rollback();
            throw $error;
        }
    }

    public function deleteDraft(
        int $staffId,
        string $domain,
        string $objectType,
        string $objectId,
        int $expectedDraftVersion,
        string $scopeHash
    ): array {
        $this->assertStaffId($staffId);
        [$domain, $objectType, $objectId] = $this->identity($domain, $objectType, $objectId);
        $now = ($this->clock)()->format('Y-m-d H:i:s');
        $this->store->begin();
        try {
            $current = $this->store->findDraft($staffId, $domain, $objectType, $objectId, true);
            if ($current === null || ($current['status'] ?? '') !== 'active' || $this->isExpired((string)$current['expires_at'])) {
                throw new PlatformApiException(404, 'draft_not_found', '草稿不存在');
            }
            PlatformStateVersion::assertExpected((int)$current['draft_version'], $expectedDraftVersion, [
                'conflict_type' => 'draft_version',
                'object_type' => $objectType,
                'object_id' => $objectId,
                'authoritative_state' => $this->draftResponse($current),
                'recovery_action' => 'choose_draft_version',
            ]);
            $nextVersion = PlatformStateVersion::next((int)$current['draft_version']);
            $this->store->markDraftDeleted($staffId, $domain, $objectType, $objectId, $nextVersion, $now);
            $change = $this->changeRecord(
                $scopeHash,
                $domain,
                'draft.' . $objectType,
                $objectId,
                $nextVersion,
                'A',
                'deleted',
                null,
                'draft_deleted',
                $now
            );
            $this->store->appendChange($change);
            $this->store->commit();
            return PlatformSyncProtocol::tombstone('draft.' . $objectType, $objectId, $nextVersion, 'deleted', $now, 'draft_deleted');
        } catch (Throwable $error) {
            $this->store->rollback();
            throw $error;
        }
    }

    public function recordChange(
        string $scopeHash,
        string $domain,
        string $objectType,
        string $objectId,
        int $stateVersion,
        string $syncLevel,
        string $status,
        mixed $state,
        string $reason = ''
    ): int {
        [$domain, $objectType, $objectId] = $this->identity($domain, $objectType, $objectId);
        $now = ($this->clock)()->format('Y-m-d H:i:s');
        return $this->store->appendChange($this->changeRecord(
            $scopeHash,
            $domain,
            $objectType,
            $objectId,
            $stateVersion,
            $syncLevel,
            $status,
            $state,
            $reason,
            $now
        ));
    }

    public function incremental(string $scopeHash, ?string $cursor, int $limit = 100, array $filters = []): array
    {
        $limit = max(1, min(200, $limit));
        $filters = array_intersect_key($filters, array_flip(['domain', 'object_type']));
        foreach ($filters as $field => $value) {
            if (!preg_match('/^[a-z][a-z0-9_.-]{0,62}$/', (string)$value)) {
                throw new InvalidArgumentException($field . ' 格式无效');
            }
        }
        ksort($filters, SORT_STRING);
        $cursorScopeHash = hash('sha256', $scopeHash . '|' . json_encode($filters, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $position = PlatformSyncProtocol::decodeCursor($cursor, $cursorScopeHash, $this->cursorSecret);
        $rows = $this->store->listChanges($scopeHash, $position, $limit + 1, $filters);
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }
        $items = [];
        $tombstones = [];
        foreach ($rows as $row) {
            $state = json_decode((string)($row['state_json'] ?? 'null'), true);
            if (($row['status'] ?? 'active') === 'active') {
                $items[] = PlatformSyncProtocol::syncObject(
                    (string)$row['object_type'],
                    (string)$row['object_id'],
                    (int)$row['state_version'],
                    (string)$row['occurred_at'],
                    (string)$row['sync_level'],
                    $state
                );
            } else {
                $tombstones[] = PlatformSyncProtocol::tombstone(
                    (string)$row['object_type'],
                    (string)$row['object_id'],
                    (int)$row['state_version'],
                    (string)$row['status'],
                    (string)$row['occurred_at'],
                    (string)($row['reason'] ?? '')
                );
            }
        }
        $last = $rows === [] ? $position : [
            'occurred_at' => (string)$rows[array_key_last($rows)]['occurred_at'],
            'id' => (int)$rows[array_key_last($rows)]['id'],
        ];
        $result = [
            'items' => $items,
            'tombstones' => $tombstones,
            'next_cursor' => PlatformSyncProtocol::encodeCursor($last, $cursorScopeHash, $this->cursorSecret),
            'has_more' => $hasMore,
            'sync_anchor' => ($this->clock)()->format('Y-m-d H:i:s'),
        ];
        $result['etag'] = PlatformSyncProtocol::etag('sync_result', $cursorScopeHash, (int)$last['id'], [
            'items' => $items,
            'tombstones' => $tombstones,
            'next_cursor' => $result['next_cursor'],
            'has_more' => $hasMore,
        ]);
        return $result;
    }

    private function changeRecord(
        string $scopeHash,
        string $domain,
        string $objectType,
        string $objectId,
        int $stateVersion,
        string $syncLevel,
        string $status,
        mixed $state,
        string $reason,
        string $occurredAt
    ): array {
        if (!preg_match('/^[a-f0-9]{64}$/', $scopeHash)) {
            throw new InvalidArgumentException('同步范围摘要无效');
        }
        $syncLevel = strtoupper(trim($syncLevel));
        if (!isset(PlatformSyncProtocol::levels()[$syncLevel])) {
            throw new InvalidArgumentException('同步等级必须为 A、B 或 C');
        }
        if (!in_array($status, ['active', 'deleted', 'revoked', 'permission_revoked'], true)) {
            throw new InvalidArgumentException('同步变更状态无效');
        }
        if ($stateVersion < 0) {
            throw new InvalidArgumentException('状态版本不能为负数');
        }
        $stateJson = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return [
            'scope_hash' => $scopeHash,
            'domain' => $domain,
            'object_type' => $objectType,
            'object_id' => $objectId,
            'state_version' => $stateVersion,
            'sync_level' => $syncLevel,
            'status' => $status,
            'state_json' => $stateJson,
            'etag' => PlatformSyncProtocol::etag($objectType, $objectId, $stateVersion, $state),
            'reason' => mb_substr(trim($reason), 0, 160),
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
        ];
    }

    private function draftResponse(array $row): array
    {
        return [
            'domain' => (string)$row['domain'],
            'object_type' => (string)$row['object_type'],
            'object_id' => (string)$row['object_id'],
            'draft_version' => (int)$row['draft_version'],
            'base_state_version' => (int)$row['base_state_version'],
            'payload' => json_decode((string)$row['payload_json'], true) ?: [],
            'source_client' => (string)$row['source_client'],
            'source_device_id' => $row['source_device_id'] === null ? null : (string)$row['source_device_id'],
            'updated_at' => (string)$row['updated_at'],
            'expires_at' => (string)$row['expires_at'],
        ];
    }

    private function payloadJson(string $domain, array $payload): string
    {
        $allowed = self::DRAFT_FIELDS[$domain] ?? null;
        if ($allowed === null) {
            throw new InvalidArgumentException('该业务域未开放跨设备草稿');
        }
        $unknown = array_diff(array_keys($payload), $allowed);
        if ($unknown !== []) {
            throw new InvalidArgumentException('草稿包含未批准字段: ' . implode(', ', array_slice($unknown, 0, 3)));
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > self::MAX_DRAFT_BYTES) {
            throw new InvalidArgumentException('草稿大小不能超过 64KB');
        }
        return $json;
    }

    private function identity(string $domain, string $objectType, string $objectId): array
    {
        foreach (['domain' => $domain, 'object_type' => $objectType] as $field => $value) {
            if (!preg_match('/^[a-z][a-z0-9_.-]{0,62}$/', trim($value))) {
                throw new InvalidArgumentException($field . ' 格式无效');
            }
        }
        $objectId = trim($objectId);
        if ($objectId === '' || mb_strlen($objectId) > 128 || preg_match('/[\x00-\x1F\x7F]/u', $objectId)) {
            throw new InvalidArgumentException('object_id 格式无效');
        }
        return [trim($domain), trim($objectType), $objectId];
    }

    private function sourceClient(string $sourceClient): string
    {
        $sourceClient = trim($sourceClient);
        if (!in_array($sourceClient, ['web', 'pwa', 'mini_program'], true)) {
            throw new InvalidArgumentException('source_client 格式无效');
        }
        return $sourceClient;
    }

    private function deviceId(?string $deviceId): ?string
    {
        $deviceId = trim((string)$deviceId);
        if ($deviceId === '') {
            return null;
        }
        if (mb_strlen($deviceId) > 120 || preg_match('/[\x00-\x1F\x7F]/u', $deviceId)) {
            throw new InvalidArgumentException('source_device_id 格式无效');
        }
        return $deviceId;
    }

    private function assertStaffId(int $staffId): void
    {
        if ($staffId <= 0) {
            throw new PlatformApiException(403, 'staff_identity_required', '当前账号未关联员工身份');
        }
    }

    private function isExpired(string $expiresAt): bool
    {
        return $expiresAt <= ($this->clock)()->format('Y-m-d H:i:s');
    }
}
