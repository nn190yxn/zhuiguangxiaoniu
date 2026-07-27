<?php
declare(strict_types=1);

final class StaffDataHealthService {
    private PDO $db;
    private bool $canViewSensitive;

    public function __construct(PDO $db, bool $canViewSensitive) {
        $this->db = $db;
        $this->canViewSensitive = $canViewSensitive;
    }

    public function inspect(): array {
        foreach (['staffs', 'stores', 'organization_positions', 'wp_users', 'wp_usermeta'] as $table) {
            if (!adminTableExists($this->db, $table)) {
                throw new RuntimeException('员工数据健康检查缺少必要数据表: ' . $table);
            }
        }
        $categories = [
            'duplicate_employee_numbers' => $this->duplicateStaffField('employee_no'),
            'duplicate_phones' => $this->duplicateStaffField('phone'),
            'duplicate_accounts' => $this->duplicateAccounts(),
            'invalid_stores' => $this->invalidOrganizationReference('store'),
            'invalid_positions' => $this->invalidOrganizationReference('position'),
            'role_mismatches' => $this->roleMismatches(),
            'orphan_identities' => $this->orphanIdentities(),
        ];
        $counts = [];
        foreach ($categories as $name => $issues) {
            $counts[$name] = count($issues);
        }
        return [
            'checked_at' => date('c'),
            'healthy' => array_sum($counts) === 0,
            'total_issues' => array_sum($counts),
            'counts' => $counts,
            'issues' => $categories,
        ];
    }

    private function duplicateStaffField(string $field): array {
        if (!in_array($field, ['employee_no', 'phone'], true)) {
            throw new InvalidArgumentException('Unsupported duplicate field');
        }
        $stmt = $this->db->query(
            "SELECT {$field} AS identifier, COUNT(*) AS duplicate_count, GROUP_CONCAT(id ORDER BY id) AS staff_ids "
            . "FROM staffs WHERE {$field} IS NOT NULL AND TRIM({$field}) <> '' "
            . "GROUP BY {$field} HAVING COUNT(*) > 1 ORDER BY duplicate_count DESC, identifier"
        );
        $issues = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $identifier = (string)$row['identifier'];
            $issues[] = [
                'type' => 'duplicate_' . $field,
                'identifier' => $field === 'phone' ? $this->sensitive($identifier) : $identifier,
                'staff_ids' => $this->integerList((string)$row['staff_ids']),
                'count' => (int)$row['duplicate_count'],
            ];
        }
        return $issues;
    }

    private function duplicateAccounts(): array {
        $stmt = $this->db->query(
            'SELECT s.user_id, u.user_login, COUNT(*) AS duplicate_count, GROUP_CONCAT(s.id ORDER BY s.id) AS staff_ids '
            . 'FROM staffs s LEFT JOIN wp_users u ON u.ID = s.user_id '
            . 'WHERE s.user_id IS NOT NULL AND s.user_id > 0 GROUP BY s.user_id, u.user_login '
            . 'HAVING COUNT(*) > 1 ORDER BY duplicate_count DESC, s.user_id'
        );
        $issues = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $issues[] = [
                'type' => 'duplicate_account',
                'user_id' => (int)$row['user_id'],
                'username' => $this->sensitive((string)($row['user_login'] ?? '')),
                'staff_ids' => $this->integerList((string)$row['staff_ids']),
                'count' => (int)$row['duplicate_count'],
            ];
        }
        return $issues;
    }

    private function invalidOrganizationReference(string $type): array {
        $position = $type === 'position';
        $join = $position
            ? 'LEFT JOIN organization_positions ref ON ref.id = s.primary_position_id'
            : 'LEFT JOIN stores ref ON ref.id = s.store_id';
        $column = $position ? 's.primary_position_id' : 's.store_id';
        $stmt = $this->db->query(
            "SELECT s.id, s.employee_no, s.name, {$column} AS reference_id, ref.status AS reference_status "
            . "FROM staffs s {$join} WHERE s.lifecycle_status <> 'offboarded' "
            . "AND ({$column} IS NULL OR {$column} <= 0 OR ref.id IS NULL OR ref.status <> 1) ORDER BY s.id"
        );
        return array_map(static fn(array $row): array => [
            'type' => 'invalid_' . $type,
            'staff_id' => (int)$row['id'],
            'employee_no' => (string)$row['employee_no'],
            'name' => (string)$row['name'],
            'reference_id' => empty($row['reference_id']) ? null : (int)$row['reference_id'],
            'reason' => $row['reference_status'] === null ? 'missing' : 'inactive',
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private function roleMismatches(): array {
        $stmt = $this->db->query(
            "SELECT s.id, s.employee_no, s.name, s.role, s.user_id, u.user_login, um.meta_value AS capabilities "
            . 'FROM staffs s LEFT JOIN wp_users u ON u.ID = s.user_id '
            . "LEFT JOIN wp_usermeta um ON um.user_id = s.user_id AND um.meta_key = 'wp_capabilities' "
            . "WHERE s.lifecycle_status <> 'offboarded' AND s.user_id IS NOT NULL AND s.user_id > 0 ORDER BY s.id"
        );
        $issues = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $expected = $this->expectedWordPressRole((string)$row['role']);
            $actual = $this->capabilityRoles((string)($row['capabilities'] ?? ''));
            if (in_array($expected, $actual, true) && count($actual) === 1) {
                continue;
            }
            $issues[] = [
                'type' => 'role_mismatch',
                'staff_id' => (int)$row['id'],
                'employee_no' => (string)$row['employee_no'],
                'name' => (string)$row['name'],
                'user_id' => (int)$row['user_id'],
                'username' => $this->sensitive((string)($row['user_login'] ?? '')),
                'staff_role' => (string)$row['role'],
                'expected_wordpress_role' => $expected,
                'actual_wordpress_roles' => $actual,
            ];
        }
        return $issues;
    }

    private function orphanIdentities(): array {
        $issues = [];
        $staffStmt = $this->db->query(
            'SELECT s.id, s.employee_no, s.name, s.user_id FROM staffs s '
            . 'LEFT JOIN wp_users u ON u.ID = s.user_id '
            . "WHERE s.lifecycle_status <> 'offboarded' AND (s.user_id IS NULL OR s.user_id <= 0 OR u.ID IS NULL) ORDER BY s.id"
        );
        foreach ($staffStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $issues[] = [
                'type' => 'staff_without_account',
                'staff_id' => (int)$row['id'],
                'employee_no' => (string)$row['employee_no'],
                'name' => (string)$row['name'],
                'user_id' => empty($row['user_id']) ? null : (int)$row['user_id'],
            ];
        }
        $accountStmt = $this->db->query(
            "SELECT u.ID, u.user_login FROM wp_users u INNER JOIN wp_usermeta um ON um.user_id = u.ID AND um.meta_key = 'wp_capabilities' "
            . 'LEFT JOIN staffs s ON s.user_id = u.ID WHERE s.id IS NULL '
            . "AND (um.meta_value LIKE '%zgxn_staff%' OR um.meta_value LIKE '%zgxn_store_manager%') ORDER BY u.ID"
        );
        foreach ($accountStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $issues[] = [
                'type' => 'account_without_staff',
                'user_id' => (int)$row['ID'],
                'username' => $this->sensitive((string)$row['user_login']),
            ];
        }
        return $issues;
    }

    private function expectedWordPressRole(string $role): string {
        $role = appRoleCode($role);
        if ($role === 'admin') {
            return 'administrator';
        }
        return $role === 'manager' ? 'zgxn_store_manager' : 'zgxn_staff';
    }

    private function capabilityRoles(string $serialized): array {
        $value = @unserialize($serialized, ['allowed_classes' => false]);
        if (!is_array($value)) {
            return [];
        }
        $roles = [];
        foreach ($value as $role => $enabled) {
            if ($enabled && in_array($role, ['administrator', 'zgxn_store_manager', 'zgxn_staff'], true)) {
                $roles[] = $role;
            }
        }
        sort($roles);
        return $roles;
    }

    private function integerList(string $values): array {
        return array_values(array_map('intval', array_filter(explode(',', $values), 'strlen')));
    }

    private function sensitive(string $value): string {
        return $this->canViewSensitive ? $value : adminMaskSensitiveValue($value);
    }
}
