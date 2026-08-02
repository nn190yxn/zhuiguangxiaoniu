<?php

declare(strict_types=1);

final class RecruitmentPermissionService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function scopeFor(array $user, array $staff): array
    {
        $role = adminEffectiveRole($user, $staff);
        $staffId = (int) ($staff['id'] ?? 0);
        $storeId = (int) ($staff['store_id'] ?? 0);
        $canViewAll = in_array($role, ['admin', 'ceo', 'operation'], true);
        $assignedRequirementIds = $this->activeAssignedRequirementIds($staffId);

        if ($canViewAll) {
            return [
                'mode' => 'all',
                'role' => $role,
                'staff_id' => $staffId,
                'store_ids' => [],
                'requirement_ids' => $assignedRequirementIds,
                'can_view_all' => true,
                'source' => 'headquarters',
            ];
        }

        $storeIds = $this->activeStoreIds($staffId, $storeId);
        return [
            'mode' => $storeIds ? 'store' : 'assigned',
            'role' => $role,
            'staff_id' => $staffId,
            'store_ids' => $storeIds,
            'requirement_ids' => $assignedRequirementIds,
            'can_view_all' => false,
            'source' => $storeIds ? 'store_assignments' : 'temporary_assignments',
        ];
    }

    public function canAccessRequirement(array $scope, int $requirementId): bool
    {
        if ($requirementId <= 0) {
            return false;
        }
        if (!empty($scope['can_view_all'])) {
            return true;
        }
        if (in_array($requirementId, array_map('intval', $scope['requirement_ids'] ?? []), true)) {
            return true;
        }
        if (!adminTableExists($this->pdo, 'recruitment_requirements')) {
            return false;
        }
        $stmt = $this->pdo->prepare('SELECT store_id FROM recruitment_requirements WHERE id = ? LIMIT 1');
        $stmt->execute([$requirementId]);
        $storeId = (int) ($stmt->fetchColumn() ?: 0);
        return $storeId > 0 && in_array($storeId, array_map('intval', $scope['store_ids'] ?? []), true);
    }

    public function requirementWhereClause(array $scope, string $alias = 'requirement'): array
    {
        if (!empty($scope['can_view_all'])) {
            return ['1 = 1', []];
        }

        $conditions = [];
        $params = [];
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $scope['store_ids'] ?? []))));
        if ($storeIds) {
            $conditions[] = $alias . '.store_id IN (' . implode(', ', array_fill(0, count($storeIds), '?')) . ')';
            array_push($params, ...$storeIds);
        }

        $requirementIds = array_values(array_unique(array_filter(array_map('intval', $scope['requirement_ids'] ?? []))));
        if ($requirementIds) {
            $conditions[] = $alias . '.id IN (' . implode(', ', array_fill(0, count($requirementIds), '?')) . ')';
            array_push($params, ...$requirementIds);
        }

        if (!$conditions) {
            return ['1 = 0', []];
        }
        return ['(' . implode(' OR ', $conditions) . ')', $params];
    }

    private function activeStoreIds(int $staffId, int $fallbackStoreId): array
    {
        $storeIds = [];
        if ($fallbackStoreId > 0) {
            $storeIds[] = $fallbackStoreId;
        }
        if ($staffId <= 0 || !adminTableExists($this->pdo, 'staff_assignments')) {
            return array_values(array_unique($storeIds));
        }
        $stmt = $this->pdo->prepare(
            'SELECT store_id FROM staff_assignments '
            . 'WHERE staff_id = ? AND start_date <= CURDATE() '
            . 'AND (end_date IS NULL OR end_date >= CURDATE())'
        );
        $stmt->execute([$staffId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $storeId) {
            $storeId = (int) $storeId;
            if ($storeId > 0) {
                $storeIds[] = $storeId;
            }
        }
        return array_values(array_unique($storeIds));
    }

    private function activeAssignedRequirementIds(int $staffId): array
    {
        if ($staffId <= 0 || !adminTableExists($this->pdo, 'recruitment_requirement_assignments')) {
            return [];
        }
        $stmt = $this->pdo->prepare(
            'SELECT requirement_id FROM recruitment_requirement_assignments '
            . 'WHERE staff_id = ? AND status = ? '
            . 'AND starts_at <= NOW() AND (expires_at IS NULL OR expires_at >= NOW())'
        );
        $stmt->execute([$staffId, 'active']);
        return array_values(array_unique(array_filter(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)))));
    }
}
