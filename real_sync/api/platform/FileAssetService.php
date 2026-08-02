<?php

declare(strict_types=1);

interface PlatformFileAssetStore
{
    public function createAsset(array $asset): array;

    public function createGrant(array $grant): array;

    public function recordAccess(array $event): void;
}

final class PlatformPdoFileAssetStore implements PlatformFileAssetStore
{
    public function __construct(private PDO $pdo)
    {
    }

    public function createAsset(array $asset): array
    {
        $sql = 'INSERT INTO platform_file_assets '
            . '(asset_key, asset_class, purpose_code, owner_type, owner_id, business_object_type, business_object_id, '
            . 'original_name, mime_type, byte_size, sha256, storage_driver, storage_key, access_mode, '
            . 'retention_policy_code, retention_until, download_expires_at, status, created_by_type, created_by_id, created_at, updated_at) '
            . 'VALUES (:asset_key, :asset_class, :purpose_code, :owner_type, :owner_id, :business_object_type, :business_object_id, '
            . ':original_name, :mime_type, :byte_size, :sha256, :storage_driver, :storage_key, :access_mode, '
            . ':retention_policy_code, :retention_until, :download_expires_at, :status, :created_by_type, :created_by_id, :created_at, :updated_at)';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($asset);
        $asset['id'] = (int) $this->pdo->lastInsertId();

        return $asset;
    }

    public function createGrant(array $grant): array
    {
        $sql = 'INSERT INTO platform_file_access_grants '
            . '(asset_id, principal_type, principal_id, permission_code, scope_type, scope_id, reason, expires_at, '
            . 'revoked_at, granted_by_type, granted_by_id, created_at) '
            . 'VALUES (:asset_id, :principal_type, :principal_id, :permission_code, :scope_type, :scope_id, :reason, '
            . ':expires_at, :revoked_at, :granted_by_type, :granted_by_id, :created_at)';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($grant);
        $grant['id'] = (int) $this->pdo->lastInsertId();

        return $grant;
    }

    public function recordAccess(array $event): void
    {
        $sql = 'INSERT INTO platform_file_access_events '
            . '(asset_id, actor_type, actor_id, action_code, permission_code, decision, reason_code, scope_type, '
            . 'scope_id, request_id, access_reason, occurred_at) '
            . 'VALUES (:asset_id, :actor_type, :actor_id, :action_code, :permission_code, :decision, :reason_code, '
            . ':scope_type, :scope_id, :request_id, :access_reason, :occurred_at)';
        $this->pdo->prepare($sql)->execute($event);
    }
}

final class PlatformFileAssetPolicy
{
    public const PUBLIC_STATIC = 'public_static';
    public const CONTROLLED = 'controlled';
    public const TEMPORARY_EXPORT = 'temporary_export';
    public const SENSITIVE_SOURCE = 'sensitive_source';

    private const DEFINITIONS = [
        self::PUBLIC_STATIC => [
            'access_mode' => 'public',
            'storage_drivers' => ['web_static', 'object_public'],
            'retention_required' => false,
            'download_expiry_required' => false,
            'max_bytes' => 104857600,
        ],
        self::CONTROLLED => [
            'access_mode' => 'authorized',
            'storage_drivers' => ['local_private', 'object_private'],
            'retention_required' => true,
            'download_expiry_required' => false,
            'max_bytes' => 104857600,
        ],
        self::TEMPORARY_EXPORT => [
            'access_mode' => 'owner_scoped',
            'storage_drivers' => ['local_private', 'object_private'],
            'retention_required' => true,
            'download_expiry_required' => true,
            'max_bytes' => 536870912,
        ],
        self::SENSITIVE_SOURCE => [
            'access_mode' => 'authorized_audited',
            'storage_drivers' => ['local_private', 'object_private'],
            'retention_required' => true,
            'download_expiry_required' => false,
            'max_bytes' => 104857600,
        ],
    ];

    public static function for(string $assetClass): array
    {
        if (!isset(self::DEFINITIONS[$assetClass])) {
            throw new InvalidArgumentException('file_asset_class_invalid');
        }

        return self::DEFINITIONS[$assetClass];
    }

    public static function all(): array
    {
        return self::DEFINITIONS;
    }
}

final class PlatformFileAssetService
{
    private const ACTIONS = ['read', 'download', 'manage'];
    private const PERMISSIONS = ['read', 'download', 'manage'];

    public function __construct(private PlatformFileAssetStore $store)
    {
    }

    public function register(array $input, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $assetClass = self::requiredToken($input, 'asset_class', 32);
        $policy = PlatformFileAssetPolicy::for($assetClass);
        $storageDriver = self::requiredToken($input, 'storage_driver', 32);
        if (!in_array($storageDriver, $policy['storage_drivers'], true)) {
            throw new InvalidArgumentException('file_storage_driver_for_class_invalid');
        }

        $originalName = self::requiredString($input, 'original_name', 255);
        if (
            $originalName !== basename($originalName)
            || str_contains($originalName, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $originalName) === 1
        ) {
            throw new InvalidArgumentException('file_original_name_invalid');
        }
        $mimeType = strtolower(self::requiredString($input, 'mime_type', 127));
        if (preg_match('#^[a-z0-9][a-z0-9.+-]*/[a-z0-9][a-z0-9.+-]*$#', $mimeType) !== 1) {
            throw new InvalidArgumentException('file_mime_type_invalid');
        }
        $byteSize = filter_var($input['byte_size'] ?? null, FILTER_VALIDATE_INT);
        if ($byteSize === false || $byteSize < 1 || $byteSize > $policy['max_bytes']) {
            throw new InvalidArgumentException('file_byte_size_invalid');
        }
        $sha256 = strtolower(self::requiredString($input, 'sha256', 64));
        if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            throw new InvalidArgumentException('file_sha256_invalid');
        }

        $storageKey = self::requiredString($input, 'storage_key', 512);
        self::assertStorageKey($storageKey);
        [$businessObjectType, $businessObjectId] = self::optionalPair(
            $input,
            'business_object_type',
            'business_object_id',
            64,
            128
        );
        $retentionUntil = self::optionalDate($input['retention_until'] ?? null, 'file_retention_until_invalid');
        $downloadExpiresAt = self::optionalDate($input['download_expires_at'] ?? null, 'file_download_expires_at_invalid');
        if ($policy['retention_required'] && ($retentionUntil === null || $retentionUntil <= $now)) {
            throw new InvalidArgumentException('file_retention_required');
        }
        if ($policy['download_expiry_required'] && ($downloadExpiresAt === null || $downloadExpiresAt <= $now)) {
            throw new InvalidArgumentException('file_download_expiry_required');
        }
        if ($downloadExpiresAt !== null && $retentionUntil !== null && $downloadExpiresAt > $retentionUntil) {
            throw new InvalidArgumentException('file_download_expiry_after_retention');
        }

        $timestamp = self::formatDate($now);
        return $this->store->createAsset([
            'asset_key' => isset($input['asset_key']) ? self::assetKey($input['asset_key']) : bin2hex(random_bytes(16)),
            'asset_class' => $assetClass,
            'purpose_code' => self::requiredToken($input, 'purpose_code', 64),
            'owner_type' => self::requiredToken($input, 'owner_type', 32),
            'owner_id' => self::requiredString($input, 'owner_id', 128),
            'business_object_type' => $businessObjectType,
            'business_object_id' => $businessObjectId,
            'original_name' => $originalName,
            'mime_type' => $mimeType,
            'byte_size' => $byteSize,
            'sha256' => $sha256,
            'storage_driver' => $storageDriver,
            'storage_key' => $storageKey,
            'access_mode' => $policy['access_mode'],
            'retention_policy_code' => self::requiredToken($input, 'retention_policy_code', 64),
            'retention_until' => self::formatNullableDate($retentionUntil),
            'download_expires_at' => self::formatNullableDate($downloadExpiresAt),
            'status' => 'active',
            'created_by_type' => self::requiredToken($input, 'created_by_type', 32),
            'created_by_id' => self::requiredString($input, 'created_by_id', 128),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    public function grant(array $input, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $assetId = filter_var($input['asset_id'] ?? null, FILTER_VALIDATE_INT);
        if ($assetId === false || $assetId < 1) {
            throw new InvalidArgumentException('file_asset_id_invalid');
        }
        $permission = self::requiredToken($input, 'permission_code', 24);
        if (!in_array($permission, self::PERMISSIONS, true)) {
            throw new InvalidArgumentException('file_permission_invalid');
        }
        [$scopeType, $scopeId] = self::optionalPair($input, 'scope_type', 'scope_id', 64, 128, '');
        $expiresAt = self::optionalDate($input['expires_at'] ?? null, 'file_grant_expiry_invalid');
        if ($expiresAt !== null && $expiresAt <= $now) {
            throw new InvalidArgumentException('file_grant_expiry_invalid');
        }

        return $this->store->createGrant([
            'asset_id' => $assetId,
            'principal_type' => self::requiredToken($input, 'principal_type', 32),
            'principal_id' => self::requiredString($input, 'principal_id', 128),
            'permission_code' => $permission,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'reason' => self::requiredString($input, 'reason', 500),
            'expires_at' => self::formatNullableDate($expiresAt),
            'revoked_at' => null,
            'granted_by_type' => self::requiredToken($input, 'granted_by_type', 32),
            'granted_by_id' => self::requiredString($input, 'granted_by_id', 128),
            'created_at' => self::formatDate($now),
        ]);
    }

    public function authorize(
        array $asset,
        array $actor,
        string $action,
        array $grants,
        array $context,
        ?DateTimeImmutable $now = null
    ): array {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if (!in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException('file_action_invalid');
        }
        $actorType = self::requiredToken($actor, 'type', 32);
        $actorId = self::requiredString($actor, 'id', 128);
        $requestId = self::requiredString($context, 'request_id', 128);
        $decision = $this->decide($asset, $actor, $actorType, $actorId, $action, $grants, $now);
        $this->store->recordAccess([
            'asset_id' => (int) ($asset['id'] ?? 0),
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'action_code' => $action,
            'permission_code' => $decision['permission_code'],
            'decision' => $decision['allowed'] ? 'allowed' : 'denied',
            'reason_code' => $decision['reason_code'],
            'scope_type' => $decision['scope_type'],
            'scope_id' => $decision['scope_id'],
            'request_id' => $requestId,
            'access_reason' => isset($context['access_reason'])
                ? self::limitedString($context['access_reason'], 'access_reason', 500)
                : null,
            'occurred_at' => self::formatDate($now),
        ]);

        return $decision;
    }

    private function decide(
        array $asset,
        array $actor,
        string $actorType,
        string $actorId,
        string $action,
        array $grants,
        DateTimeImmutable $now
    ): array {
        if ((int) ($asset['id'] ?? 0) < 1) {
            throw new InvalidArgumentException('file_asset_id_invalid');
        }
        if (($asset['status'] ?? '') !== 'active') {
            return self::denied('asset_inactive');
        }
        $retentionUntil = self::optionalDate($asset['retention_until'] ?? null, 'file_retention_until_invalid');
        if ($retentionUntil !== null && $retentionUntil <= $now) {
            return self::denied('retention_expired');
        }
        $downloadExpiresAt = self::optionalDate(
            $asset['download_expires_at'] ?? null,
            'file_download_expires_at_invalid'
        );
        if ($action === 'download' && $downloadExpiresAt !== null && $downloadExpiresAt <= $now) {
            return self::denied('download_expired');
        }
        if (($asset['asset_class'] ?? '') === PlatformFileAssetPolicy::PUBLIC_STATIC && $action !== 'manage') {
            return self::allowed('public_asset', $action, null, null);
        }
        if (($asset['owner_type'] ?? '') === $actorType && (string) ($asset['owner_id'] ?? '') === $actorId) {
            return self::allowed('owner', 'manage', null, null);
        }

        $actorScopes = array_fill_keys(array_map('strval', $actor['scopes'] ?? []), true);
        foreach ($grants as $grant) {
            if ((int) ($grant['asset_id'] ?? 0) !== (int) $asset['id']) {
                continue;
            }
            if (($grant['principal_type'] ?? '') !== $actorType || (string) ($grant['principal_id'] ?? '') !== $actorId) {
                continue;
            }
            if (($grant['revoked_at'] ?? null) !== null) {
                continue;
            }
            $expiresAt = self::optionalDate($grant['expires_at'] ?? null, 'file_grant_expiry_invalid');
            if ($expiresAt !== null && $expiresAt <= $now) {
                continue;
            }
            $permission = (string) ($grant['permission_code'] ?? '');
            if ($permission !== 'manage' && $permission !== $action) {
                continue;
            }
            $scopeType = (string) ($grant['scope_type'] ?? '');
            $scopeId = (string) ($grant['scope_id'] ?? '');
            if ($scopeType !== '' && !isset($actorScopes[$scopeType . ':' . $scopeId])) {
                continue;
            }

            return self::allowed('acl_grant', $permission, $scopeType ?: null, $scopeId ?: null);
        }

        return self::denied('permission_denied');
    }

    private static function allowed(string $reason, string $permission, ?string $scopeType, ?string $scopeId): array
    {
        return [
            'allowed' => true,
            'reason_code' => $reason,
            'permission_code' => $permission,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
        ];
    }

    private static function denied(string $reason): array
    {
        return [
            'allowed' => false,
            'reason_code' => $reason,
            'permission_code' => null,
            'scope_type' => null,
            'scope_id' => null,
        ];
    }

    private static function requiredToken(array $input, string $field, int $maxLength): string
    {
        $value = self::requiredString($input, $field, $maxLength);
        if (preg_match('/^[a-z][a-z0-9_.-]*$/', $value) !== 1) {
            throw new InvalidArgumentException($field . '_invalid');
        }

        return $value;
    }

    private static function requiredString(array $input, string $field, int $maxLength): string
    {
        return self::limitedString($input[$field] ?? null, $field, $maxLength);
    }

    private static function limitedString(mixed $raw, string $field, int $maxLength): string
    {
        if (!is_string($raw)) {
            throw new InvalidArgumentException($field . '_required');
        }
        $value = trim($raw);
        if ($value === '' || strlen($value) > $maxLength) {
            throw new InvalidArgumentException($field . '_invalid');
        }

        return $value;
    }

    private static function optionalPair(
        array $input,
        string $typeField,
        string $idField,
        int $typeMax,
        int $idMax,
        ?string $emptyValue = null
    ): array {
        $typePresent = isset($input[$typeField]) && trim((string) $input[$typeField]) !== '';
        $idPresent = isset($input[$idField]) && trim((string) $input[$idField]) !== '';
        if ($typePresent !== $idPresent) {
            throw new InvalidArgumentException($typeField . '_pair_invalid');
        }
        if (!$typePresent) {
            return [$emptyValue, $emptyValue];
        }

        return [
            self::requiredToken($input, $typeField, $typeMax),
            self::requiredString($input, $idField, $idMax),
        ];
    }

    private static function optionalDate(mixed $raw, string $error): ?DateTimeImmutable
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        try {
            return $raw instanceof DateTimeImmutable ? $raw : new DateTimeImmutable((string) $raw, new DateTimeZone('UTC'));
        } catch (Throwable) {
            throw new InvalidArgumentException($error);
        }
    }

    private static function assetKey(mixed $raw): string
    {
        $value = strtolower(trim((string) $raw));
        if (preg_match('/^[a-f0-9]{32}$/', $value) !== 1) {
            throw new InvalidArgumentException('file_asset_key_invalid');
        }

        return $value;
    }

    private static function assertStorageKey(string $storageKey): void
    {
        if (
            str_starts_with($storageKey, '/')
            || str_contains($storageKey, '\\')
            || preg_match('#(^|/)\.\.(/|$)#', $storageKey) === 1
            || preg_match('/[\x00-\x1F\x7F]/', $storageKey) === 1
        ) {
            throw new InvalidArgumentException('file_storage_key_invalid');
        }
    }

    private static function formatDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function formatNullableDate(?DateTimeImmutable $date): ?string
    {
        return $date === null ? null : self::formatDate($date);
    }
}
