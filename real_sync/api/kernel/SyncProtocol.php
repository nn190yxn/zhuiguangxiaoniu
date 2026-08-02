<?php
declare(strict_types=1);

require_once __DIR__ . '/ApiException.php';

final class PlatformSyncProtocol
{
    private const LEVELS = [
        'A' => [
            'max_stale_seconds' => 30,
            'refresh_triggers' => ['write_readback', 'foreground', 'active_poll'],
            'objects' => ['submission', 'approval', 'contact', 'drill', 'upload'],
        ],
        'B' => [
            'max_stale_seconds' => 300,
            'refresh_triggers' => ['page_enter', 'foreground', 'interval'],
            'objects' => ['workload_summary', 'staff_directory', 'candidate_list', 'todo'],
        ],
        'C' => [
            'max_stale_seconds' => 1800,
            'refresh_triggers' => ['version_check', 'etag_check'],
            'objects' => ['course', 'policy', 'knowledge', 'public_content'],
        ],
    ];

    public static function levels(): array
    {
        return self::LEVELS;
    }

    public static function syncObject(
        string $objectType,
        string $objectId,
        int $stateVersion,
        string $updatedAt,
        string $syncLevel,
        mixed $state
    ): array {
        $objectType = self::identifier($objectType, 'object_type');
        $objectId = self::objectId($objectId);
        $syncLevel = strtoupper(trim($syncLevel));
        if (!isset(self::LEVELS[$syncLevel])) {
            throw new InvalidArgumentException('同步等级必须为 A、B 或 C');
        }
        if ($stateVersion < 0) {
            throw new InvalidArgumentException('状态版本不能为负数');
        }
        self::timestamp($updatedAt, 'updated_at');

        return [
            'object_type' => $objectType,
            'object_id' => $objectId,
            'state_version' => $stateVersion,
            'updated_at' => $updatedAt,
            'sync_level' => $syncLevel,
            'etag' => self::etag($objectType, $objectId, $stateVersion, $state),
            'state' => $state,
        ];
    }

    public static function tombstone(
        string $objectType,
        string $objectId,
        int $stateVersion,
        string $status,
        string $occurredAt,
        string $reason = ''
    ): array {
        $allowedStatuses = ['deleted', 'revoked', 'permission_revoked'];
        if (!in_array($status, $allowedStatuses, true)) {
            throw new InvalidArgumentException('墓碑状态无效');
        }
        if ($stateVersion < 0) {
            throw new InvalidArgumentException('状态版本不能为负数');
        }
        self::timestamp($occurredAt, 'occurred_at');
        return [
            'object_type' => self::identifier($objectType, 'object_type'),
            'object_id' => self::objectId($objectId),
            'state_version' => $stateVersion,
            'status' => $status,
            'occurred_at' => $occurredAt,
            'reason' => mb_substr(trim($reason), 0, 160),
        ];
    }

    public static function versionConflict(int $currentVersion, ?int $baseVersion, array $context = []): array
    {
        $data = [
            'conflict_type' => (string)($context['conflict_type'] ?? 'state_version'),
            'base_version' => $baseVersion,
            'current_version' => $currentVersion,
            'authoritative_state' => $context['authoritative_state'] ?? null,
            'recovery_action' => (string)($context['recovery_action'] ?? 'refresh'),
            'retryable' => (bool)($context['retryable'] ?? true),
        ];
        foreach (['object_type', 'object_id'] as $field) {
            if (isset($context[$field]) && trim((string)$context[$field]) !== '') {
                $data[$field] = (string)$context[$field];
            }
        }
        return $data;
    }

    public static function etag(string $objectType, string $objectId, int $stateVersion, mixed $state): string
    {
        $payload = [
            'object_type' => self::identifier($objectType, 'object_type'),
            'object_id' => self::objectId($objectId),
            'state_version' => $stateVersion,
            'state' => self::canonicalize($state),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return '"' . hash('sha256', $json) . '"';
    }

    public static function matchesEtag(?string $ifNoneMatch, string $etag): bool
    {
        if ($ifNoneMatch === null || trim($ifNoneMatch) === '') {
            return false;
        }
        foreach (explode(',', $ifNoneMatch) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '*' || $candidate === $etag || $candidate === 'W/' . $etag) {
                return true;
            }
        }
        return false;
    }

    public static function scopeHash(array $authContext, array $filters = []): string
    {
        $storeIds = array_values(array_unique(array_map('intval', (array)($authContext['store_ids'] ?? []))));
        sort($storeIds, SORT_NUMERIC);
        $scope = [
            'staff_id' => (int)($authContext['staff_id'] ?? 0),
            'session_version' => (int)($authContext['session_version'] ?? 0),
            'scope_type' => (string)($authContext['scope_type'] ?? 'self'),
            'store_ids' => $storeIds,
            'filters' => self::canonicalize($filters),
        ];
        $json = json_encode(self::canonicalize($scope), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return hash('sha256', $json);
    }

    public static function encodeCursor(array $position, string $scopeHash, string $secret): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $scopeHash)) {
            throw new InvalidArgumentException('同步范围摘要无效');
        }
        $occurredAt = (string)($position['occurred_at'] ?? '1970-01-01 00:00:00');
        self::timestamp($occurredAt, 'occurred_at');
        $payload = json_encode([
            'v' => 1,
            'scope' => $scopeHash,
            'occurred_at' => $occurredAt,
            'id' => max(0, (int)($position['id'] ?? 0)),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $encoded = self::base64UrlEncode($payload);
        return $encoded . '.' . self::base64UrlEncode(hash_hmac('sha256', $encoded, $secret, true));
    }

    public static function decodeCursor(?string $cursor, string $scopeHash, string $secret): array
    {
        if ($cursor === null || trim($cursor) === '') {
            return ['occurred_at' => '1970-01-01 00:00:00', 'id' => 0];
        }
        $parts = explode('.', $cursor, 2);
        if (count($parts) !== 2) {
            throw new PlatformApiException(400, 'invalid_sync_cursor', '增量游标无效，请重新同步');
        }
        [$encoded, $signature] = $parts;
        $expected = self::base64UrlEncode(hash_hmac('sha256', $encoded, $secret, true));
        if (!hash_equals($expected, $signature)) {
            throw new PlatformApiException(400, 'invalid_sync_cursor', '增量游标无效，请重新同步');
        }
        $decoded = json_decode(self::base64UrlDecode($encoded), true);
        if (!is_array($decoded) || (int)($decoded['v'] ?? 0) !== 1) {
            throw new PlatformApiException(400, 'invalid_sync_cursor', '增量游标无效，请重新同步');
        }
        if (!hash_equals($scopeHash, (string)($decoded['scope'] ?? ''))) {
            throw new PlatformApiException(400, 'sync_cursor_scope_changed', '同步范围已变化，请重新同步');
        }
        $occurredAt = (string)($decoded['occurred_at'] ?? '');
        try {
            self::timestamp($occurredAt, 'occurred_at');
        } catch (InvalidArgumentException) {
            throw new PlatformApiException(400, 'invalid_sync_cursor', '增量游标无效，请重新同步');
        }
        return ['occurred_at' => $occurredAt, 'id' => max(0, (int)($decoded['id'] ?? 0))];
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'canonicalize'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }
        return $value;
    }

    private static function identifier(string $value, string $field): string
    {
        $value = trim($value);
        if (!preg_match('/^[a-z][a-z0-9_.-]{0,62}$/', $value)) {
            throw new InvalidArgumentException($field . ' 格式无效');
        }
        return $value;
    }

    private static function objectId(string $value): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 128 || preg_match('/[\x00-\x1F\x7F]/u', $value)) {
            throw new InvalidArgumentException('object_id 格式无效');
        }
        return $value;
    }

    private static function timestamp(string $value, string $field): void
    {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
        if ($parsed === false || $parsed->format('Y-m-d H:i:s') !== $value) {
            throw new InvalidArgumentException($field . ' 格式无效');
        }
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new PlatformApiException(400, 'invalid_sync_cursor', '增量游标无效，请重新同步');
        }
        return $decoded;
    }
}
