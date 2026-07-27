<?php
declare(strict_types=1);

final class WorkloadPermissionScopeException extends RuntimeException {
    private int $statusCode;

    public function __construct(string $message, int $statusCode = 403) {
        parent::__construct($message);
        $this->statusCode = $statusCode;
    }

    public function statusCode(): int {
        return $this->statusCode;
    }
}

final class WorkloadPermissionScopeService {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function resolve(array $context): array {
        $role = strtolower(trim((string) ($context['role'] ?? '')));
        if (!empty($context['permissions']['can_view_all']) || in_array($role, ['operation', 'admin', 'ceo'], true)) {
            return $this->scope('all', [], null, 'all', $role === 'admin');
        }

        $staffId = (int) ($context['staff_id'] ?? 0);
        if ($staffId <= 0) {
            throw new WorkloadPermissionScopeException('当前账号未绑定员工档案');
        }
        if ($role !== 'manager') {
            return $this->scope('staff', [], $staffId, 'self', false);
        }

        $storeIds = [];
        $contextStoreId = (int) ($context['store_id'] ?? 0);
        if ($contextStoreId > 0) {
            $storeIds[] = $contextStoreId;
        }
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT store_id FROM staff_assignments "
            . "WHERE staff_id = ? AND assignment_type IN ('primary', 'secondary') "
            . "AND system_role IN ('manager', 'store_manager', 'shop_manager', '店长') "
            . 'AND start_date <= CURDATE() AND (end_date IS NULL OR end_date >= CURDATE()) '
            . 'ORDER BY store_id'
        );
        $stmt->execute([$staffId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $storeId) {
            if ((int) $storeId > 0) {
                $storeIds[] = (int) $storeId;
            }
        }
        $storeIds = array_values(array_unique($storeIds));
        sort($storeIds, SORT_NUMERIC);
        if ($storeIds === []) {
            throw new WorkloadPermissionScopeException('店长账号没有有效授权门店');
        }
        return $this->scope('stores', $storeIds, null, 'stores', false);
    }

    private function scope(
        string $scopeType,
        array $storeIds,
        ?int $staffId,
        string $rankingScope,
        bool $canManageConfiguration
    ): array {
        return [
            'scope_type' => $scopeType,
            'store_ids' => $storeIds,
            'staff_id' => $staffId,
            'ranking_scope' => $rankingScope,
            'can_manage_configuration' => $canManageConfiguration,
            'can_export' => true,
        ];
    }
}
