<?php
declare(strict_types=1);

final class StaffDirectoryExportLimitException extends RuntimeException {}

final class StaffDirectoryService {
    private PDO $db;
    private bool $canViewSensitive;

    public function __construct(PDO $db, bool $canViewSensitive) {
        $this->db = $db;
        $this->canViewSensitive = $canViewSensitive;
    }

    public function list(array $query): array {
        $page = max(1, (int)($query['page'] ?? 1));
        $pageSize = min(100, max(1, (int)($query['page_size'] ?? 50)));
        $offset = ($page - 1) * $pageSize;
        $where = [];
        $params = [];

        $keyword = trim((string)($query['keyword'] ?? ''));
        if ($keyword !== '') {
            $where[] = '(s.name LIKE ? OR s.phone LIKE ? OR s.employee_no LIKE ?)';
            array_push($params, '%' . $keyword . '%', '%' . $keyword . '%', '%' . $keyword . '%');
        }
        foreach (['store_id' => 's.store_id', 'position_id' => 's.primary_position_id'] as $key => $column) {
            $value = max(0, (int)($query[$key] ?? 0));
            if ($value > 0) {
                $where[] = $column . ' = ?';
                $params[] = $value;
            }
        }
        $role = appRoleCode((string)($query['role'] ?? ''));
        if ($role !== '') {
            $where[] = 's.role = ?';
            $params[] = $role;
        }
        $lifecycle = strtolower(trim((string)($query['lifecycle_status'] ?? $query['status'] ?? '')));
        if (in_array($lifecycle, ['active', 'inactive', 'offboarded'], true)) {
            $where[] = 's.lifecycle_status = ?';
            $params[] = $lifecycle;
        } elseif (!$this->truthy($query['include_offboarded'] ?? false)) {
            $where[] = "s.lifecycle_status <> 'offboarded'";
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM staffs s' . $whereSql);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $select = [
            's.id', 's.employee_no', 's.name', 's.phone', 's.role', 's.job_title', 's.stage', 's.status',
            's.lifecycle_status', 's.store_id', 's.primary_position_id', 's.entry_date', 's.offboarded_at',
            's.user_id', 's.created_at', 'st.name AS store_name', 'p.position_name AS primary_position_name',
            'u.user_login', 'u.user_email', 'u.user_status',
        ];
        $metricJoin = '';
        if (adminTableExists($this->db, 'monthly_statistics')) {
            array_push($select, 'COALESCE(ms.total_courses, 0) AS total_courses', 'COALESCE(ms.total_drills, 0) AS total_drills', 'COALESCE(ms.avg_pass_rate, 0) AS avg_pass_rate');
            $metricJoin = ' LEFT JOIN (
                SELECT staff_id, SUM(courses_completed) AS total_courses, SUM(drills_completed) AS total_drills, AVG(pass_rate) AS avg_pass_rate
                FROM monthly_statistics GROUP BY staff_id
            ) ms ON ms.staff_id = s.id';
        } else {
            array_push($select, '0 AS total_courses', '0 AS total_drills', '0 AS avg_pass_rate');
        }
        $sql = 'SELECT ' . implode(', ', $select) . '
            FROM staffs s
            LEFT JOIN stores st ON st.id = s.store_id
            LEFT JOIN organization_positions p ON p.id = s.primary_position_id
            LEFT JOIN wp_users u ON u.ID = s.user_id' . $metricJoin . $whereSql . '
            ORDER BY s.created_at DESC, s.id DESC LIMIT ? OFFSET ?';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([...$params, $pageSize, $offset]);
        $items = array_map(fn(array $row): array => $this->formatStaff($row), $stmt->fetchAll(PDO::FETCH_ASSOC));

        return [
            'list' => $items,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'total_pages' => $total === 0 ? 0 : (int)ceil($total / $pageSize),
        ];
    }

    public function export(array $query): array {
        $query['page'] = 1;
        $query['page_size'] = 100;
        $firstPage = $this->list($query);
        if ((int)$firstPage['total'] > 20000) {
            throw new StaffDirectoryExportLimitException('员工导出最多支持 20000 行，请增加筛选条件');
        }
        return [
            'total' => (int)$firstPage['total'],
            'rows' => $this->exportRows($query, $firstPage),
        ];
    }

    public function detail(int $staffId): ?array {
        $select = [
            's.id', 's.employee_no', 's.name', 's.phone', 's.role', 's.job_title', 's.stage', 's.status',
            's.lifecycle_status', 's.store_id', 's.primary_position_id', 's.entry_date', 's.offboarded_at',
            's.offboard_reason', 's.user_id', 's.created_at', 'st.name AS store_name',
            'p.position_name AS primary_position_name', 'u.user_login', 'u.user_email', 'u.user_status',
        ];
        if (adminColumnExists($this->db, 'staffs', 'openid')) {
            $select[] = 's.openid';
        } else {
            $select[] = 'NULL AS openid';
        }
        if (adminColumnExists($this->db, 'staffs', 'openid_bound_at')) {
            $select[] = 's.openid_bound_at';
        } else {
            $select[] = 'NULL AS openid_bound_at';
        }

        $stmt = $this->db->prepare('SELECT ' . implode(', ', $select) . '
            FROM staffs s
            LEFT JOIN stores st ON st.id = s.store_id
            LEFT JOIN organization_positions p ON p.id = s.primary_position_id
            LEFT JOIN wp_users u ON u.ID = s.user_id
            WHERE s.id = ? LIMIT 1');
        $stmt->execute([$staffId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $item = $this->formatStaff($row);
        $item['wechat_bound'] = !empty($row['openid']);
        $item['wechat_bound_text'] = $item['wechat_bound'] ? '已绑定' : '未绑定';
        $item['openid_bound_at'] = $row['openid_bound_at'] ?? null;
        $item['must_change_password'] = null;

        return [
            'item' => $item,
            'current_assignments' => $this->assignments($staffId, true),
            'assignment_history' => $this->assignments($staffId, false),
            'account_status' => [
                'linked' => (int)$row['user_id'] > 0,
                'enabled' => (int)$row['user_id'] > 0 && (int)($row['user_status'] ?? 0) === 0 && (int)$row['status'] === 1,
                'username' => $this->sensitive((string)($row['user_login'] ?? '')),
                'email' => $this->sensitive((string)($row['user_email'] ?? '')),
                'wechat_bound' => !empty($row['openid']),
            ],
            'business_summary' => $this->businessSummary($staffId, (int)$row['user_id']),
            'available_actions' => $this->availableActions((string)$row['lifecycle_status'], !empty($row['openid'])),
            'devices' => $this->devices($staffId),
            'recent_login_audits' => $this->loginAudits($staffId),
            'operation_audits' => $this->operationAudits($staffId),
        ];
    }

    private function formatStaff(array $row): array {
        $status = (int)$row['status'];
        return [
            'id' => (int)$row['id'],
            'employee_no' => (string)$row['employee_no'],
            'name' => (string)$row['name'],
            'phone' => $this->sensitive((string)$row['phone']),
            'role' => (string)$row['role'],
            'role_name' => $this->roleName((string)$row['role']),
            'job_title' => (string)($row['job_title'] ?? ''),
            'stage' => (string)($row['stage'] ?? ''),
            'status' => $status,
            'status_text' => $status === 1 ? '启用' : '停用',
            'lifecycle_status' => (string)$row['lifecycle_status'],
            'store_id' => (int)$row['store_id'],
            'store_name' => (string)($row['store_name'] ?? ''),
            'primary_position_id' => (int)($row['primary_position_id'] ?? 0),
            'primary_position_name' => (string)($row['primary_position_name'] ?? ''),
            'entry_date' => $row['entry_date'] ?? null,
            'offboarded_at' => $row['offboarded_at'] ?? null,
            'offboard_reason' => $row['offboard_reason'] ?? null,
            'user_id' => (int)($row['user_id'] ?? 0),
            'account_linked' => (int)($row['user_id'] ?? 0) > 0,
            'account_enabled' => (int)($row['user_id'] ?? 0) > 0 && (int)($row['user_status'] ?? 0) === 0 && $status === 1,
            'username' => $this->sensitive((string)($row['user_login'] ?? '')),
            'email' => $this->sensitive((string)($row['user_email'] ?? '')),
            'total_courses' => (int)($row['total_courses'] ?? 0),
            'total_drills' => (int)($row['total_drills'] ?? 0),
            'avg_pass_rate' => round((float)($row['avg_pass_rate'] ?? 0), 1),
            'created_at' => $row['created_at'] ?? null,
        ];
    }

    private function exportRows(array $query, array $firstPage): Generator {
        $page = $firstPage;
        while (true) {
            foreach ($page['list'] as $item) {
                yield $item;
            }
            if ((int)$page['page'] >= (int)$page['total_pages']) {
                return;
            }
            $query['page'] = (int)$page['page'] + 1;
            $page = $this->list($query);
        }
    }

    private function assignments(int $staffId, bool $current): array {
        if (!adminTableExists($this->db, 'staff_assignments')) {
            return [];
        }
        $condition = $current ? ' AND a.start_date <= CURDATE() AND (a.end_date IS NULL OR a.end_date >= CURDATE())' : '';
        $stmt = $this->db->prepare('SELECT a.id, a.staff_id, a.store_id, st.name AS store_name, a.position_id,
                p.position_name, a.system_role, a.assignment_type, a.start_date, a.end_date,
                a.change_reason, a.operator_staff_id, a.created_at
            FROM staff_assignments a
            LEFT JOIN stores st ON st.id = a.store_id
            LEFT JOIN organization_positions p ON p.id = a.position_id
            WHERE a.staff_id = ?' . $condition . '
            ORDER BY a.start_date DESC, a.id DESC');
        $stmt->execute([$staffId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function businessSummary(int $staffId, int $userId): array {
        $summary = ['workload_reports' => 0, 'completed_exams' => 0, 'completed_passes' => 0, 'courses' => 0, 'drills' => 0];
        if (adminTableExists($this->db, 'workload_daily_reports')) {
            $summary['workload_reports'] = $this->countBy('workload_daily_reports', 'staff_id', $staffId);
        }
        if ($userId > 0 && adminTableExists($this->db, 'exam_records')) {
            $summary['completed_exams'] = $this->countBy('exam_records', 'user_id', $userId, "status = 'completed'");
        }
        if ($userId > 0 && adminTableExists($this->db, 'user_pass_progress')) {
            $summary['completed_passes'] = $this->countBy('user_pass_progress', 'user_id', $userId, "status = 'completed'");
        }
        if (adminTableExists($this->db, 'monthly_statistics')) {
            $stmt = $this->db->prepare('SELECT COALESCE(SUM(courses_completed), 0), COALESCE(SUM(drills_completed), 0) FROM monthly_statistics WHERE staff_id = ?');
            $stmt->execute([$staffId]);
            $totals = $stmt->fetch(PDO::FETCH_NUM) ?: [0, 0];
            $summary['courses'] = (int)$totals[0];
            $summary['drills'] = (int)$totals[1];
        }
        return $summary;
    }

    private function countBy(string $table, string $column, int $value, string $extra = ''): int {
        $sql = 'SELECT COUNT(*) FROM `' . $table . '` WHERE `' . $column . '` = ?' . ($extra !== '' ? ' AND ' . $extra : '');
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$value]);
        return (int)$stmt->fetchColumn();
    }

    private function devices(int $staffId): array {
        if (!adminTableExists($this->db, 'device_logins')) {
            return [];
        }
        $stmt = $this->db->prepare('SELECT id, staff_id, device_id, device_fingerprint, device_name, device_model,
                os_version, app_version, login_count, is_trusted, is_active, first_login, last_login, created_at
            FROM device_logins WHERE staff_id = ? ORDER BY last_login DESC LIMIT 20');
        $stmt->execute([$staffId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['device_id'] = $this->sensitive((string)($row['device_id'] ?? ''));
            $row['device_fingerprint'] = $this->sensitive((string)($row['device_fingerprint'] ?? ''));
        }
        unset($row);
        return $rows;
    }

    private function loginAudits(int $staffId): array {
        if (!adminTableExists($this->db, 'login_audit_logs')) {
            return [];
        }
        $stmt = $this->db->prepare('SELECT id, login_type, login_status, source, ip_address, message, device_id,
                device_fingerprint, is_new_device, risk_level, created_at
            FROM login_audit_logs WHERE staff_id = ? ORDER BY created_at DESC LIMIT 20');
        $stmt->execute([$staffId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['ip_address'] = $this->sensitive((string)($row['ip_address'] ?? ''));
            $row['device_id'] = $this->sensitive((string)($row['device_id'] ?? ''));
            $row['device_fingerprint'] = $this->sensitive((string)($row['device_fingerprint'] ?? ''));
        }
        unset($row);
        return $rows;
    }

    private function operationAudits(int $staffId): array {
        if (!adminTableExists($this->db, 'admin_operation_logs')) {
            return [];
        }
        $stmt = $this->db->prepare('SELECT l.id, l.operator_staff_id, l.action, l.created_at,
                s.name AS operator_name
            FROM admin_operation_logs l
            LEFT JOIN staffs s ON s.id = l.operator_staff_id
            WHERE l.target_type = ? AND l.target_id = ?
            ORDER BY l.created_at DESC LIMIT 20');
        $stmt->execute(['staff', (string)$staffId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function availableActions(string $lifecycle, bool $wechatBound): array {
        $actions = ['edit_profile', 'reset_password'];
        if ($lifecycle === 'active') {
            array_push($actions, 'deactivate', 'offboard');
        } elseif ($lifecycle === 'inactive') {
            array_push($actions, 'activate', 'offboard');
        } else {
            $actions[] = 'restore';
        }
        if ($wechatBound) {
            $actions[] = 'unbind_wechat';
        }
        return $actions;
    }

    private function sensitive(string $value): string {
        return $this->canViewSensitive ? $value : (string)adminMaskSensitiveValue($value);
    }

    private function roleName(string $role): string {
        return ['sales' => '销售', 'coach' => '教练', 'manager' => '店长', 'ops' => '运营', 'operation' => '运营', 'finance' => '财务', 'admin' => '管理员', 'ceo' => '负责人'][$role] ?? $role;
    }

    private function truthy($value): bool {
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }
}
