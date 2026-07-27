<?php
declare(strict_types=1);

final class StaffProfileService {
    private const CORRECTABLE_FIELDS = ['name', 'phone', 'store_id', 'primary_position_id', 'entry_date'];

    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function profile(int $staffId): ?array {
        $stmt = $this->db->prepare(
            'SELECT s.id, s.employee_no, s.name, s.phone, s.role, s.stage, s.entry_date, s.lifecycle_status, '
            . 's.store_id, st.name AS store_name, s.primary_position_id, p.position_name AS primary_position_name, '
            . 's.user_id, u.user_login, u.user_email, u.user_status '
            . 'FROM staffs s LEFT JOIN stores st ON st.id = s.store_id '
            . 'LEFT JOIN organization_positions p ON p.id = s.primary_position_id '
            . 'LEFT JOIN wp_users u ON u.ID = s.user_id WHERE s.id = ? LIMIT 1'
        );
        $stmt->execute([$staffId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return [
            'id' => (int)$row['id'],
            'employee_no' => (string)$row['employee_no'],
            'name' => (string)$row['name'],
            'phone' => (string)$row['phone'],
            'role' => (string)$row['role'],
            'stage' => (string)($row['stage'] ?? ''),
            'entry_date' => $row['entry_date'] ?? null,
            'lifecycle_status' => (string)$row['lifecycle_status'],
            'store' => ['id' => (int)$row['store_id'], 'name' => (string)($row['store_name'] ?? '')],
            'primary_position' => [
                'id' => (int)($row['primary_position_id'] ?? 0),
                'name' => (string)($row['primary_position_name'] ?? ''),
            ],
            'secondary_assignments' => $this->secondaryAssignments($staffId),
            'account' => [
                'linked' => (int)($row['user_id'] ?? 0) > 0,
                'enabled' => (int)($row['user_id'] ?? 0) > 0 && (int)($row['user_status'] ?? 0) === 0,
                'username' => (string)($row['user_login'] ?? ''),
                'email' => (string)($row['user_email'] ?? ''),
            ],
        ];
    }

    public function correctionsForStaff(int $staffId): array {
        $stmt = $this->db->prepare(
            'SELECT id, change_summary_json, request_reason, status, handler_comment, handled_at, created_at, updated_at '
            . 'FROM staff_profile_correction_requests WHERE staff_id = ? ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute([$staffId]);
        return array_map([$this, 'formatCorrection'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function submit(int $staffId, array $changes, string $reason, array $actorUser, array $actorStaff): array {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason, 'UTF-8') > 500) {
            throw new InvalidArgumentException('申请原因不能为空且不能超过 500 个字符');
        }
        $this->db->beginTransaction();
        try {
            $staffStmt = $this->db->prepare(
                'SELECT id, name, phone, store_id, primary_position_id, entry_date FROM staffs WHERE id = ? FOR UPDATE'
            );
            $staffStmt->execute([$staffId]);
            $staff = $staffStmt->fetch(PDO::FETCH_ASSOC);
            if (!$staff) {
                throw new RuntimeException('员工档案不存在');
            }
            $summary = [];
            foreach ($changes as $field => $requestedValue) {
                if (!in_array($field, self::CORRECTABLE_FIELDS, true) || (!is_scalar($requestedValue) && $requestedValue !== null)) {
                    throw new InvalidArgumentException('更正字段不受支持: ' . (string)$field);
                }
                $normalized = $requestedValue === null ? null : trim((string)$requestedValue);
                if ($normalized !== null && mb_strlen($normalized, 'UTF-8') > 255) {
                    throw new InvalidArgumentException('更正值不能超过 255 个字符');
                }
                $current = $staff[$field] === null ? null : (string)$staff[$field];
                if ($current === $normalized) {
                    continue;
                }
                $summary[] = ['field' => $field, 'current_value' => $current, 'requested_value' => $normalized];
            }
            if ($summary === []) {
                throw new InvalidArgumentException('至少提交一项有变化的档案字段');
            }
            $json = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $duplicateStmt = $this->db->prepare(
                "SELECT id FROM staff_profile_correction_requests WHERE staff_id = ? AND status = 'pending' "
                . 'AND change_summary_json = ? LIMIT 1 FOR UPDATE'
            );
            $duplicateStmt->execute([$staffId, $json]);
            $existingId = $duplicateStmt->fetchColumn();
            if ($existingId !== false) {
                $item = $this->correction((int)$existingId);
                $this->db->commit();
                return $item + ['idempotent' => true];
            }
            $insert = $this->db->prepare(
                "INSERT INTO staff_profile_correction_requests (staff_id, change_summary_json, request_reason, status) VALUES (?, ?, ?, 'pending')"
            );
            $insert->execute([$staffId, $json, $reason]);
            $id = (int)$this->db->lastInsertId();
            adminRecordOperation($this->db, $actorUser, $actorStaff, [
                'module' => 'staff_profile',
                'action' => 'request_correction',
                'target_type' => 'staff_profile_correction',
                'target_id' => (string)$id,
                'before' => null,
                'after' => ['staff_id' => $staffId, 'changes' => $summary, 'status' => 'pending'],
            ]);
            $this->db->commit();
            return $this->correction($id) + ['idempotent' => false];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function listCorrections(array $query): array {
        $page = max(1, (int)($query['page'] ?? 1));
        $pageSize = min(100, max(1, (int)($query['page_size'] ?? 20)));
        $status = trim((string)($query['status'] ?? ''));
        $params = [];
        $where = '';
        if ($status !== '') {
            if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
                throw new InvalidArgumentException('申请状态无效');
            }
            $where = ' WHERE r.status = ?';
            $params[] = $status;
        }
        $count = $this->db->prepare('SELECT COUNT(*) FROM staff_profile_correction_requests r' . $where);
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $stmt = $this->db->prepare(
            'SELECT r.id, r.staff_id, r.change_summary_json, r.request_reason, r.status, r.handled_by_staff_id, '
            . 'r.handler_comment, r.handled_at, r.created_at, r.updated_at, s.employee_no, s.name AS staff_name, '
            . 'handler.name AS handler_name FROM staff_profile_correction_requests r '
            . 'INNER JOIN staffs s ON s.id = r.staff_id LEFT JOIN staffs handler ON handler.id = r.handled_by_staff_id'
            . $where . ' ORDER BY r.created_at DESC, r.id DESC LIMIT ? OFFSET ?'
        );
        $stmt->execute(array_merge($params, [$pageSize, ($page - 1) * $pageSize]));
        return [
            'list' => array_map([$this, 'formatCorrection'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []),
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
            'total_pages' => $total === 0 ? 0 : (int)ceil($total / $pageSize),
        ];
    }

    public function handle(int $requestId, string $status, string $comment, array $actorUser, array $actorStaff): array {
        if ($requestId <= 0) {
            throw new InvalidArgumentException('缺少有效的更正申请 ID');
        }
        if (!in_array($status, ['approved', 'rejected'], true)) {
            throw new InvalidArgumentException('处理结果必须为 approved 或 rejected');
        }
        $comment = trim($comment);
        if ($comment === '' || mb_strlen($comment, 'UTF-8') > 500) {
            throw new InvalidArgumentException('处理意见不能为空且不能超过 500 个字符');
        }
        $handlerStaffId = (int)($actorStaff['id'] ?? 0);
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM staff_profile_correction_requests WHERE id = ? FOR UPDATE');
            $stmt->execute([$requestId]);
            $before = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$before) {
                throw new RuntimeException('更正申请不存在');
            }
            if ((string)$before['status'] !== 'pending') {
                throw new DomainException('更正申请已处理');
            }
            $update = $this->db->prepare(
                'UPDATE staff_profile_correction_requests SET status = ?, handled_by_staff_id = ?, '
                . 'handler_comment = ?, handled_at = NOW(), updated_at = NOW() WHERE id = ?'
            );
            $update->execute([$status, $handlerStaffId, $comment, $requestId]);
            adminRecordOperation($this->db, $actorUser, $actorStaff, [
                'module' => 'staff_profile',
                'action' => 'handle_correction',
                'target_type' => 'staff_profile_correction',
                'target_id' => (string)$requestId,
                'before' => ['status' => $before['status'], 'handler_comment' => $before['handler_comment']],
                'after' => ['status' => $status, 'handler_comment' => $comment, 'handled_by_staff_id' => $handlerStaffId],
            ]);
            $this->db->commit();
            return $this->correction($requestId);
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    private function correction(int $id): array {
        $stmt = $this->db->prepare(
            'SELECT id, staff_id, change_summary_json, request_reason, status, handled_by_staff_id, handler_comment, '
            . 'handled_at, created_at, updated_at FROM staff_profile_correction_requests WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('更正申请不存在');
        }
        return $this->formatCorrection($row);
    }

    private function formatCorrection(array $row): array {
        return [
            'id' => (int)$row['id'],
            'staff_id' => isset($row['staff_id']) ? (int)$row['staff_id'] : null,
            'employee_no' => $row['employee_no'] ?? null,
            'staff_name' => $row['staff_name'] ?? null,
            'changes' => json_decode((string)$row['change_summary_json'], true) ?: [],
            'request_reason' => (string)$row['request_reason'],
            'status' => (string)$row['status'],
            'handled_by_staff_id' => isset($row['handled_by_staff_id']) ? (int)$row['handled_by_staff_id'] : null,
            'handler_name' => $row['handler_name'] ?? null,
            'handler_comment' => $row['handler_comment'] ?? null,
            'handled_at' => $row['handled_at'] ?? null,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private function secondaryAssignments(int $staffId): array {
        $stmt = $this->db->prepare(
            "SELECT a.id, a.store_id, st.name AS store_name, a.position_id, p.position_name, a.system_role, "
            . "a.start_date, a.end_date FROM staff_assignments a LEFT JOIN stores st ON st.id = a.store_id "
            . "LEFT JOIN organization_positions p ON p.id = a.position_id WHERE a.staff_id = ? "
            . "AND a.assignment_type = 'secondary' AND a.start_date <= CURDATE() "
            . 'AND (a.end_date IS NULL OR a.end_date >= CURDATE()) ORDER BY a.start_date, a.id'
        );
        $stmt->execute([$staffId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
