<?php
declare(strict_types=1);

require_once __DIR__ . '/ApiException.php';

final class PlatformAuthContext
{
    private array $storeIds;
    private array $positionIds;
    private array $assignmentIds;
    private array $permissions;

    public function __construct(
        private bool $authenticated,
        private ?int $userId,
        private ?int $staffId,
        private string $role,
        array $storeIds,
        array $positionIds,
        array $assignmentIds,
        private int $sessionVersion,
        array $permissions,
        private string $scopeType
    ) {
        $this->storeIds = self::positiveIds($storeIds);
        $this->positionIds = self::positiveIds($positionIds);
        $this->assignmentIds = self::positiveIds($assignmentIds);
        $this->permissions = array_values(array_unique(array_filter(array_map('strval', $permissions))));
        if (!in_array($this->scopeType, ['all', 'stores', 'self'], true)) {
            $this->scopeType = 'self';
        }
    }

    public static function guest(): self
    {
        return new self(false, null, null, 'guest', [], [], [], 0, [], 'self');
    }

    public function isAuthenticated(): bool
    {
        return $this->authenticated;
    }

    public function userId(): ?int
    {
        return $this->userId;
    }

    public function staffId(): ?int
    {
        return $this->staffId;
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function requireAuthenticated(): void
    {
        if (!$this->authenticated) {
            throw new PlatformApiException(401, 'authentication_required', '请先登录');
        }
    }

    public function requirePermission(string $permission): void
    {
        $this->requireAuthenticated();
        if (!$this->hasPermission($permission)) {
            throw new PlatformApiException(403, 'permission_denied', '无权限执行该操作');
        }
    }

    public function visibleStoreIds(array $requestedStoreIds = []): array
    {
        $requestedStoreIds = self::positiveIds($requestedStoreIds);
        if ($this->scopeType === 'all') {
            return $requestedStoreIds;
        }
        if ($this->scopeType === 'self') {
            return [];
        }
        if ($requestedStoreIds === []) {
            return $this->storeIds;
        }
        return array_values(array_intersect($requestedStoreIds, $this->storeIds));
    }

    public function canAccessStaff(int $targetStaffId, ?int $targetStoreId = null): bool
    {
        if (!$this->authenticated || $targetStaffId <= 0) {
            return false;
        }
        if ($this->scopeType === 'all') {
            return true;
        }
        if ($this->staffId === $targetStaffId) {
            return true;
        }
        return $this->scopeType === 'stores'
            && $targetStoreId !== null
            && in_array($targetStoreId, $this->storeIds, true);
    }

    public function toArray(): array
    {
        return [
            'authenticated' => $this->authenticated,
            'user_id' => $this->userId,
            'staff_id' => $this->staffId,
            'role' => $this->role,
            'store_ids' => $this->storeIds,
            'position_ids' => $this->positionIds,
            'assignment_ids' => $this->assignmentIds,
            'session_version' => $this->sessionVersion,
            'permissions' => $this->permissions,
            'scope_type' => $this->scopeType,
        ];
    }

    private static function positiveIds(array $values): array
    {
        $ids = array_map('intval', $values);
        $ids = array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }
}
