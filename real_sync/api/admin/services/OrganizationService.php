<?php

declare(strict_types=1);

final class OrganizationPositionValidationException extends RuntimeException
{
}

final class OrganizationPositionConflictException extends RuntimeException
{
    private string $conflictField;

    public function __construct(string $message, string $conflictField)
    {
        parent::__construct($message);
        $this->conflictField = $conflictField;
    }

    public function conflictField(): string
    {
        return $this->conflictField;
    }
}

final class OrganizationPositionReferenceException extends RuntimeException
{
    private array $referenceSummary;

    public function __construct(array $referenceSummary)
    {
        parent::__construct('该岗位仍有当前员工或有效任职引用，暂时无法停用');
        $this->referenceSummary = $referenceSummary;
    }

    public function referenceSummary(): array
    {
        return $this->referenceSummary;
    }
}

final class OrganizationStoreValidationException extends RuntimeException
{
}

final class OrganizationStoreConflictException extends RuntimeException
{
    private string $conflictField;

    public function __construct(string $message, string $conflictField)
    {
        parent::__construct($message);
        $this->conflictField = $conflictField;
    }

    public function conflictField(): string
    {
        return $this->conflictField;
    }
}

final class OrganizationStoreReferenceException extends RuntimeException
{
    private array $referenceSummary;

    public function __construct(array $referenceSummary)
    {
        parent::__construct('该门店仍有当前在职员工归属，请先完成调店或离职处理');
        $this->referenceSummary = $referenceSummary;
    }

    public function referenceSummary(): array
    {
        return $this->referenceSummary;
    }
}

final class OrganizationAssignmentValidationException extends RuntimeException
{
}

final class OrganizationAssignmentConflictException extends RuntimeException
{
    private array $conflictingAssignments;

    public function __construct(string $message, array $conflictingAssignments = [])
    {
        parent::__construct($message);
        $this->conflictingAssignments = array_values($conflictingAssignments);
    }

    public function conflictingAssignments(): array
    {
        return $this->conflictingAssignments;
    }
}

final class OrganizationService
{
    private const MAX_SORT_ORDER = 1000000;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function listPositions(array $filters = []): array
    {
        $conditions = [];
        $params = [];

        if (array_key_exists('status', $filters) && $filters['status'] !== '' && $filters['status'] !== 'all') {
            $conditions[] = 'p.status = ?';
            $params[] = $this->normalizeStatus($filters['status']);
        }

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            if (mb_strlen($keyword) > 100) {
                throw new OrganizationPositionValidationException('搜索关键词不能超过 100 个字符');
            }
            $conditions[] = '(p.position_code LIKE ? OR p.position_name LIKE ?)';
            $likeKeyword = '%' . $keyword . '%';
            $params[] = $likeKeyword;
            $params[] = $likeKeyword;
        }

        $sql = $this->positionSelectSql();
        if ($conditions) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY p.sort_order ASC, p.id ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map([$this, 'formatPosition'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function getPosition(int $positionId): array
    {
        if ($positionId <= 0) {
            throw new OrganizationPositionValidationException('岗位 ID 无效');
        }

        $stmt = $this->pdo->prepare($this->positionSelectSql() . ' WHERE p.id = ? LIMIT 1');
        $stmt->execute([$positionId]);
        $position = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$position) {
            throw new OrganizationPositionValidationException('岗位不存在');
        }

        return $this->formatPosition($position);
    }

    public function createPosition(array $input, array $operatorUser, array $operatorStaff = []): array
    {
        $data = $this->normalizePositionInput($input);
        ensureAdminOperationLogsTable($this->pdo);

        $this->pdo->beginTransaction();
        try {
            $this->assertPositionCodeAvailable($data['position_code']);

            $stmt = $this->pdo->prepare(
                'INSERT INTO organization_positions '
                . '(position_code, position_name, applicable_roles_json, sort_order, status) '
                . 'VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $data['position_code'],
                $data['position_name'],
                $this->encodeRoles($data['applicable_roles']),
                $data['sort_order'],
                $data['status'],
            ]);

            $positionId = (int) $this->pdo->lastInsertId();
            $after = $this->getPosition($positionId);
            adminRecordOperation(
                $this->pdo,
                $operatorUser,
                $operatorStaff ?: null,
                [
                    'module' => 'organization',
                    'action' => 'position.create',
                    'target_type' => 'organization_position',
                    'target_id' => (string) $positionId,
                    'before' => null,
                    'after' => $after,
                ]
            );
            $this->pdo->commit();

            return $after;
        } catch (Throwable $error) {
            $this->rollBackIfNeeded();
            if ($this->isDuplicateKeyError($error)) {
                throw new OrganizationPositionConflictException('岗位编码已存在', 'position_code');
            }
            throw $error;
        }
    }

    public function updatePosition(
        int $positionId,
        array $input,
        array $operatorUser,
        array $operatorStaff = []
    ): array {
        if ($positionId <= 0) {
            throw new OrganizationPositionValidationException('岗位 ID 无效');
        }
        ensureAdminOperationLogsTable($this->pdo);

        $this->pdo->beginTransaction();
        try {
            $beforeRow = $this->lockPosition($positionId);
            $before = $this->formatPosition($beforeRow);
            $data = $this->normalizePositionInput($input, $before);
            $this->assertPositionCodeAvailable($data['position_code'], $positionId);

            if ($before['status'] === 1 && $data['status'] === 0) {
                $references = $this->getPositionReferenceSummary($positionId);
                if ($references['current_staff_count'] > 0 || $references['current_assignment_count'] > 0) {
                    throw new OrganizationPositionReferenceException($references);
                }
            }

            $stmt = $this->pdo->prepare(
                'UPDATE organization_positions SET '
                . 'position_code = ?, position_name = ?, applicable_roles_json = ?, sort_order = ?, status = ? '
                . 'WHERE id = ?'
            );
            $stmt->execute([
                $data['position_code'],
                $data['position_name'],
                $this->encodeRoles($data['applicable_roles']),
                $data['sort_order'],
                $data['status'],
                $positionId,
            ]);

            $after = $this->getPosition($positionId);
            adminRecordOperation(
                $this->pdo,
                $operatorUser,
                $operatorStaff ?: null,
                [
                    'module' => 'organization',
                    'action' => 'position.update',
                    'target_type' => 'organization_position',
                    'target_id' => (string) $positionId,
                    'before' => $before,
                    'after' => $after,
                ]
            );
            $this->pdo->commit();

            return $after;
        } catch (Throwable $error) {
            $this->rollBackIfNeeded();
            if ($this->isDuplicateKeyError($error)) {
                throw new OrganizationPositionConflictException('岗位编码已存在', 'position_code');
            }
            throw $error;
        }
    }

    public function setPositionStatus(
        int $positionId,
        $status,
        array $operatorUser,
        array $operatorStaff = []
    ): array {
        return $this->updatePosition(
            $positionId,
            ['status' => $this->normalizeStatus($status)],
            $operatorUser,
            $operatorStaff
        );
    }

    public function getPositionReferenceSummary(int $positionId): array
    {
        $staffStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM staffs "
            . "WHERE primary_position_id = ? AND status = 1 AND lifecycle_status = 'active'"
        );
        $staffStmt->execute([$positionId]);

        $assignmentStmt = $this->pdo->prepare(
            'SELECT '
            . 'SUM(CASE WHEN start_date <= CURDATE() AND (end_date IS NULL OR end_date >= CURDATE()) THEN 1 ELSE 0 END) '
            . 'AS current_count, COUNT(*) AS total_count '
            . 'FROM staff_assignments WHERE position_id = ?'
        );
        $assignmentStmt->execute([$positionId]);
        $assignmentCounts = $assignmentStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'current_staff_count' => (int) $staffStmt->fetchColumn(),
            'current_assignment_count' => (int) ($assignmentCounts['current_count'] ?? 0),
            'historical_assignment_count' => max(
                0,
                (int) ($assignmentCounts['total_count'] ?? 0) - (int) ($assignmentCounts['current_count'] ?? 0)
            ),
        ];
    }

    public function listStores(array $filters = []): array
    {
        $conditions = [];
        $params = [];

        if (array_key_exists('status', $filters) && $filters['status'] !== '' && $filters['status'] !== 'all') {
            $conditions[] = 's.status = ?';
            try {
                $params[] = $this->normalizeStatus($filters['status']);
            } catch (OrganizationPositionValidationException $error) {
                throw new OrganizationStoreValidationException('门店状态必须为启用或停用');
            }
        }

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            if (mb_strlen($keyword) > 100) {
                throw new OrganizationStoreValidationException('搜索关键词不能超过 100 个字符');
            }
            $conditions[] = '(s.store_code LIKE ? OR s.name LIKE ?)';
            $likeKeyword = '%' . $keyword . '%';
            $params[] = $likeKeyword;
            $params[] = $likeKeyword;
        }

        $sql = $this->storeSelectSql();
        if ($conditions) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY s.sort_order ASC, s.id ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map([$this, 'formatStore'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function getStore(int $storeId): array
    {
        if ($storeId <= 0) {
            throw new OrganizationStoreValidationException('门店 ID 无效');
        }

        $stmt = $this->pdo->prepare($this->storeSelectSql() . ' WHERE s.id = ? LIMIT 1');
        $stmt->execute([$storeId]);
        $store = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$store) {
            throw new OrganizationStoreValidationException('门店不存在');
        }

        return $this->formatStore($store);
    }

    public function createStore(array $input, array $operatorUser, array $operatorStaff = []): array
    {
        $data = $this->normalizeStoreInput($input);
        ensureAdminOperationLogsTable($this->pdo);

        $this->pdo->beginTransaction();
        try {
            $this->assertStoreCodeAvailable($data['store_code']);
            $manager = $this->resolveActiveManager($data['manager_staff_id']);
            $managerName = (string) ($manager['name'] ?? '');

            $stmt = $this->pdo->prepare(
                'INSERT INTO stores '
                . '(store_code, name, manager_staff_id, manager_name, sort_order, status) '
                . 'VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $data['store_code'],
                $data['name'],
                $data['manager_staff_id'],
                $managerName,
                $data['sort_order'],
                $data['status'],
            ]);

            $storeId = (int) $this->pdo->lastInsertId();
            $after = $this->getStore($storeId);
            adminRecordOperation(
                $this->pdo,
                $operatorUser,
                $operatorStaff ?: null,
                [
                    'module' => 'organization',
                    'action' => 'store.create',
                    'target_type' => 'store',
                    'target_id' => (string) $storeId,
                    'before' => null,
                    'after' => $after,
                ]
            );
            $this->pdo->commit();

            return $after;
        } catch (Throwable $error) {
            $this->rollBackIfNeeded();
            if ($this->isDuplicateKeyError($error)) {
                throw new OrganizationStoreConflictException('门店编码已存在', 'store_code');
            }
            throw $error;
        }
    }

    public function updateStore(
        int $storeId,
        array $input,
        array $operatorUser,
        array $operatorStaff = []
    ): array {
        if ($storeId <= 0) {
            throw new OrganizationStoreValidationException('门店 ID 无效');
        }
        ensureAdminOperationLogsTable($this->pdo);

        $this->pdo->beginTransaction();
        try {
            $this->lockStore($storeId);
            $before = $this->getStore($storeId);
            $data = $this->normalizeStoreInput($input, $before);
            $this->assertStoreCodeAvailable($data['store_code'], $storeId);
            $manager = $data['manager_changed']
                ? $this->resolveActiveManager($data['manager_staff_id'])
                : null;
            $managerName = $data['manager_changed']
                ? (string) ($manager['name'] ?? '')
                : $before['manager_name'];

            if ($before['status'] === 1 && $data['status'] === 0) {
                $references = $this->getStoreReferenceSummary($storeId);
                if ($references['current_staff_count'] > 0 || $references['current_assignment_count'] > 0) {
                    throw new OrganizationStoreReferenceException($references);
                }
            }

            $stmt = $this->pdo->prepare(
                'UPDATE stores SET '
                . 'store_code = ?, name = ?, manager_staff_id = ?, manager_name = ?, sort_order = ?, status = ? '
                . 'WHERE id = ?'
            );
            $stmt->execute([
                $data['store_code'],
                $data['name'],
                $data['manager_staff_id'],
                $managerName,
                $data['sort_order'],
                $data['status'],
                $storeId,
            ]);

            $after = $this->getStore($storeId);
            adminRecordOperation(
                $this->pdo,
                $operatorUser,
                $operatorStaff ?: null,
                [
                    'module' => 'organization',
                    'action' => 'store.update',
                    'target_type' => 'store',
                    'target_id' => (string) $storeId,
                    'before' => $before,
                    'after' => $after,
                ]
            );
            $this->pdo->commit();

            return $after;
        } catch (Throwable $error) {
            $this->rollBackIfNeeded();
            if ($this->isDuplicateKeyError($error)) {
                throw new OrganizationStoreConflictException('门店编码已存在', 'store_code');
            }
            throw $error;
        }
    }

    public function setStoreStatus(
        int $storeId,
        $status,
        array $operatorUser,
        array $operatorStaff = []
    ): array {
        try {
            $normalizedStatus = $this->normalizeStatus($status);
        } catch (OrganizationPositionValidationException $error) {
            throw new OrganizationStoreValidationException('门店状态必须为启用或停用');
        }

        return $this->updateStore(
            $storeId,
            ['status' => $normalizedStatus],
            $operatorUser,
            $operatorStaff
        );
    }

    public function getStoreReferenceSummary(int $storeId): array
    {
        $staffStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM staffs "
            . "WHERE store_id = ? AND status = 1 AND lifecycle_status = 'active'"
        );
        $staffStmt->execute([$storeId]);

        $assignmentStmt = $this->pdo->prepare(
            'SELECT '
            . "SUM(CASE WHEN assigned_staff.status = 1 AND assigned_staff.lifecycle_status = 'active' "
            . 'AND store_assignment.start_date <= CURDATE() '
            . 'AND (store_assignment.end_date IS NULL OR store_assignment.end_date >= CURDATE()) THEN 1 ELSE 0 END) '
            . 'AS current_count, COUNT(*) AS total_count '
            . 'FROM staff_assignments store_assignment '
            . 'INNER JOIN staffs assigned_staff ON assigned_staff.id = store_assignment.staff_id '
            . 'WHERE store_assignment.store_id = ?'
        );
        $assignmentStmt->execute([$storeId]);
        $assignmentCounts = $assignmentStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'current_staff_count' => (int) $staffStmt->fetchColumn(),
            'current_assignment_count' => (int) ($assignmentCounts['current_count'] ?? 0),
            'historical_assignment_count' => max(
                0,
                (int) ($assignmentCounts['total_count'] ?? 0) - (int) ($assignmentCounts['current_count'] ?? 0)
            ),
        ];
    }

    public function changePrimaryAssignment(
        int $staffId,
        array $input,
        array $operatorUser,
        array $operatorStaff = []
    ): array {
        $data = $this->normalizeAssignmentInput($input, false);
        ensureAdminOperationLogsTable($this->pdo);

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $staff = $this->lockActiveStaff($staffId);
            $this->requireActiveAssignmentOrganization(
                $data['store_id'],
                $data['position_id'],
                $data['system_role']
            );
            $assignments = $this->lockStaffAssignments($staffId);
            $effectiveAssignments = array_values(array_filter(
                $assignments,
                static fn(array $assignment): bool => $assignment['assignment_type'] === 'primary'
                    && $assignment['start_date'] <= $data['start_date']
                    && ($assignment['end_date'] === null || $assignment['end_date'] >= $data['start_date'])
            ));

            if (count($effectiveAssignments) > 1) {
                throw new OrganizationAssignmentConflictException(
                    '该员工在生效日期存在多个主岗，请先修复任职数据',
                    array_map([$this, 'formatAssignment'], $effectiveAssignments)
                );
            }

            $current = $effectiveAssignments[0] ?? null;
            if ($current !== null && $this->assignmentMatches($current, $data)) {
                return $this->commitIdempotentAssignment($current, $ownsTransaction);
            }
            if ($current !== null && $current['end_date'] !== null && $current['end_date'] < date('Y-m-d')) {
                throw new OrganizationAssignmentValidationException('已结束的历史任职不可修改');
            }
            if ($current !== null && $current['start_date'] === $data['start_date']) {
                throw new OrganizationAssignmentConflictException(
                    '同一生效日期已存在不同的主岗任职',
                    [$this->formatAssignment($current)]
                );
            }

            $nextPrimary = null;
            foreach ($assignments as $assignment) {
                if ($assignment['assignment_type'] !== 'primary' || $assignment['start_date'] <= $data['start_date']) {
                    continue;
                }
                if ($nextPrimary === null || $assignment['start_date'] < $nextPrimary['start_date']) {
                    $nextPrimary = $assignment;
                }
            }

            if ($current !== null) {
                $closeStmt = $this->pdo->prepare(
                    'UPDATE staff_assignments SET end_date = ? WHERE id = ?'
                );
                $closeStmt->execute([$this->previousDate($data['start_date']), $current['id']]);
            }

            $newEndDate = $nextPrimary === null ? null : $this->previousDate($nextPrimary['start_date']);
            $assignmentId = $this->insertAssignment(
                $staffId,
                $data,
                'primary',
                $newEndDate,
                (int) ($operatorStaff['id'] ?? 0)
            );

            if ($data['start_date'] <= date('Y-m-d')) {
                $this->synchronizeCurrentPrimaryFields($staffId);
            }

            $after = $this->getAssignment($assignmentId);
            adminRecordOperation(
                $this->pdo,
                $operatorUser,
                $operatorStaff ?: null,
                [
                    'module' => 'organization',
                    'action' => 'assignment.primary.change',
                    'target_type' => 'staff_assignment',
                    'target_id' => (string) $assignmentId,
                    'before' => [
                        'staff' => $this->formatAssignmentStaff($staff),
                        'assignment' => $current === null ? null : $this->formatAssignment($current),
                    ],
                    'after' => $after,
                ]
            );
            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return $after + ['idempotent' => false];
        } catch (Throwable $error) {
            if ($ownsTransaction) {
                $this->rollBackIfNeeded();
            }
            throw $error;
        }
    }

    public function createSecondaryAssignment(
        int $staffId,
        array $input,
        array $operatorUser,
        array $operatorStaff = []
    ): array {
        $data = $this->normalizeAssignmentInput($input, true);
        ensureAdminOperationLogsTable($this->pdo);

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $staff = $this->lockActiveStaff($staffId);
            $this->requireActiveAssignmentOrganization(
                $data['store_id'],
                $data['position_id'],
                $data['system_role']
            );
            $assignments = $this->lockStaffAssignments($staffId);
            $conflicts = [];
            foreach ($assignments as $assignment) {
                if ($assignment['assignment_type'] !== 'secondary'
                    || (int) $assignment['store_id'] !== $data['store_id']
                    || (int) $assignment['position_id'] !== $data['position_id']
                    || $assignment['system_role'] !== $data['system_role']
                    || !$this->dateRangesOverlap(
                        $assignment['start_date'],
                        $assignment['end_date'],
                        $data['start_date'],
                        $data['end_date']
                    )) {
                    continue;
                }
                if ($assignment['start_date'] === $data['start_date']
                    && $assignment['end_date'] === $data['end_date']) {
                    return $this->commitIdempotentAssignment($assignment, $ownsTransaction);
                }
                $conflicts[] = $this->formatAssignment($assignment);
            }
            if ($conflicts) {
                throw new OrganizationAssignmentConflictException('相同职责的兼岗任职区间发生重叠', $conflicts);
            }

            $assignmentId = $this->insertAssignment(
                $staffId,
                $data,
                'secondary',
                $data['end_date'],
                (int) ($operatorStaff['id'] ?? 0)
            );
            $after = $this->getAssignment($assignmentId);
            adminRecordOperation(
                $this->pdo,
                $operatorUser,
                $operatorStaff ?: null,
                [
                    'module' => 'organization',
                    'action' => 'assignment.secondary.create',
                    'target_type' => 'staff_assignment',
                    'target_id' => (string) $assignmentId,
                    'before' => null,
                    'after' => $after,
                ]
            );
            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return $after + ['idempotent' => false];
        } catch (Throwable $error) {
            if ($ownsTransaction) {
                $this->rollBackIfNeeded();
            }
            throw $error;
        }
    }

    public function endSecondaryAssignment(
        int $assignmentId,
        string $effectiveDate,
        string $changeReason,
        array $operatorUser,
        array $operatorStaff = []
    ): array {
        $effectiveDate = $this->normalizeDate($effectiveDate, '兼岗结束生效日期');
        $changeReason = $this->normalizeChangeReason($changeReason);
        ensureAdminOperationLogsTable($this->pdo);

        $this->pdo->beginTransaction();
        try {
            $assignmentStaffId = $this->findAssignmentStaffId($assignmentId);
            $this->lockActiveStaff($assignmentStaffId);
            $assignments = $this->lockStaffAssignments($assignmentStaffId);
            $assignment = null;
            foreach ($assignments as $candidate) {
                if ($candidate['id'] === $assignmentId) {
                    $assignment = $candidate;
                    break;
                }
            }
            if ($assignment === null) {
                throw new OrganizationAssignmentValidationException('任职记录不存在');
            }
            if ($assignment['assignment_type'] !== 'secondary') {
                throw new OrganizationAssignmentValidationException('仅兼岗任职可使用兼岗结束操作');
            }
            $today = date('Y-m-d');
            if ($assignment['end_date'] !== null && $assignment['end_date'] < $today) {
                throw new OrganizationAssignmentValidationException('已结束的历史任职不可修改');
            }
            if ($effectiveDate <= $assignment['start_date']) {
                throw new OrganizationAssignmentValidationException('兼岗结束生效日期必须晚于开始日期');
            }

            $endDate = $this->previousDate($effectiveDate);
            if ($assignment['end_date'] === $endDate) {
                return $this->commitIdempotentAssignment($assignment);
            }
            if ($assignment['end_date'] !== null && $endDate > $assignment['end_date']) {
                throw new OrganizationAssignmentValidationException('结束操作不能延长原任职区间');
            }

            $stmt = $this->pdo->prepare(
                'UPDATE staff_assignments SET end_date = ?, change_reason = ?, operator_staff_id = ? WHERE id = ?'
            );
            $stmt->execute([
                $endDate,
                $changeReason,
                ((int) ($operatorStaff['id'] ?? 0)) ?: null,
                $assignmentId,
            ]);
            $after = $this->getAssignment($assignmentId);
            adminRecordOperation(
                $this->pdo,
                $operatorUser,
                $operatorStaff ?: null,
                [
                    'module' => 'organization',
                    'action' => 'assignment.secondary.end',
                    'target_type' => 'staff_assignment',
                    'target_id' => (string) $assignmentId,
                    'before' => $this->formatAssignment($assignment),
                    'after' => $after,
                ]
            );
            $this->pdo->commit();

            return $after + ['idempotent' => false];
        } catch (Throwable $error) {
            $this->rollBackIfNeeded();
            throw $error;
        }
    }

    public function getAssignment(int $assignmentId): array
    {
        if ($assignmentId <= 0) {
            throw new OrganizationAssignmentValidationException('任职记录 ID 无效');
        }

        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.staff_id, a.store_id, a.position_id, a.system_role, a.assignment_type, '
            . 'a.start_date, a.end_date, a.change_reason, a.operator_staff_id, '
            . 's.name AS store_name, p.position_name '
            . 'FROM staff_assignments a '
            . 'INNER JOIN stores s ON s.id = a.store_id '
            . 'INNER JOIN organization_positions p ON p.id = a.position_id '
            . 'WHERE a.id = ? LIMIT 1'
        );
        $stmt->execute([$assignmentId]);
        $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$assignment) {
            throw new OrganizationAssignmentValidationException('任职记录不存在');
        }

        return $this->formatAssignment($assignment);
    }

    public function getOrganizationTree(): array
    {
        $businessDate = (string) $this->pdo->query('SELECT CURDATE()')->fetchColumn();
        $stores = $this->listStores(['status' => 1]);
        $positions = $this->listPositions(['status' => 1]);

        $storeGroups = [];
        foreach ($stores as $store) {
            $storeGroups[$store['id']] = [
                'id' => 'store:' . $store['id'],
                'type' => 'store',
                'entity_id' => $store['id'],
                'code' => $store['store_code'],
                'name' => $store['name'],
                'manager_staff_id' => $store['manager_staff_id'],
                'manager_name' => $store['manager_name'],
                'staff_count' => 0,
                'positions' => [],
                'staff_ids' => [],
            ];
        }

        $stmt = $this->pdo->prepare(
            'SELECT a.id AS assignment_id, a.staff_id, a.store_id, a.position_id, '
            . 'a.system_role, a.assignment_type, a.start_date, a.end_date, '
            . 'staff.employee_no, staff.name AS staff_name, '
            . 'store.store_code, store.name AS store_name, store.sort_order AS store_sort_order, '
            . 'position.position_code, position.position_name, position.sort_order AS position_sort_order '
            . 'FROM staff_assignments a '
            . 'INNER JOIN staffs staff ON staff.id = a.staff_id '
            . 'INNER JOIN stores store ON store.id = a.store_id AND store.status = 1 '
            . 'INNER JOIN organization_positions position ON position.id = a.position_id AND position.status = 1 '
            . "WHERE staff.status = 1 AND staff.lifecycle_status = 'active' "
            . 'AND a.start_date <= ? AND (a.end_date IS NULL OR a.end_date >= ?) '
            . "ORDER BY store.sort_order ASC, store.id ASC, position.sort_order ASC, position.id ASC, "
            . "CASE a.assignment_type WHEN 'primary' THEN 0 ELSE 1 END ASC, staff.name ASC, staff.id ASC, a.id ASC"
        );
        $stmt->execute([$businessDate, $businessDate]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $staffList = [];
        $allStaffIds = [];
        $primaryCount = 0;
        $secondaryCount = 0;
        foreach ($rows as $row) {
            $storeId = (int) $row['store_id'];
            $positionId = (int) $row['position_id'];
            if (!isset($storeGroups[$storeId])) {
                continue;
            }

            if (!isset($storeGroups[$storeId]['positions'][$positionId])) {
                $storeGroups[$storeId]['positions'][$positionId] = [
                    'id' => 'store:' . $storeId . ':position:' . $positionId,
                    'type' => 'position',
                    'entity_id' => $positionId,
                    'code' => (string) $row['position_code'],
                    'name' => (string) $row['position_name'],
                    'staff_count' => 0,
                    'children' => [],
                    'staff_ids' => [],
                ];
            }

            $assignment = [
                'id' => 'assignment:' . (int) $row['assignment_id'],
                'type' => 'staff',
                'entity_id' => (int) $row['staff_id'],
                'assignment_id' => (int) $row['assignment_id'],
                'employee_no' => (string) $row['employee_no'],
                'name' => (string) $row['staff_name'],
                'store_id' => $storeId,
                'store_name' => (string) $row['store_name'],
                'position_id' => $positionId,
                'position_name' => (string) $row['position_name'],
                'system_role' => (string) $row['system_role'],
                'assignment_type' => (string) $row['assignment_type'],
                'start_date' => (string) $row['start_date'],
                'end_date' => $row['end_date'] === null ? null : (string) $row['end_date'],
            ];
            $staffList[] = $assignment;
            $storeGroups[$storeId]['positions'][$positionId]['children'][] = $assignment;
            $staffId = (int) $row['staff_id'];
            $storeGroups[$storeId]['positions'][$positionId]['staff_ids'][$staffId] = true;
            $storeGroups[$storeId]['staff_ids'][$staffId] = true;
            $allStaffIds[$staffId] = true;
            if ($row['assignment_type'] === 'primary') {
                $primaryCount++;
            } else {
                $secondaryCount++;
            }
        }

        $storeNodes = [];
        $representedPositionIds = [];
        foreach ($storeGroups as $storeGroup) {
            $positionNodes = [];
            foreach ($storeGroup['positions'] as $positionGroup) {
                $positionGroup['staff_count'] = count($positionGroup['staff_ids']);
                unset($positionGroup['staff_ids']);
                $representedPositionIds[$positionGroup['entity_id']] = true;
                $positionNodes[] = $positionGroup;
            }
            $storeGroup['staff_count'] = count($storeGroup['staff_ids']);
            $storeGroup['children'] = $positionNodes;
            unset($storeGroup['positions'], $storeGroup['staff_ids']);
            $storeNodes[] = $storeGroup;
        }

        return [
            'business_date' => $businessDate,
            'tree' => [
                'id' => 'headquarters',
                'type' => 'headquarters',
                'name' => '总部',
                'staff_count' => count($allStaffIds),
                'children' => $storeNodes,
            ],
            'list' => [
                'stores' => $stores,
                'positions' => $positions,
                'staff' => $staffList,
            ],
            'summary' => [
                'store_count' => count($stores),
                'position_count' => count($positions),
                'represented_position_count' => count($representedPositionIds),
                'staff_count' => count($allStaffIds),
                'assignment_count' => count($staffList),
                'primary_assignment_count' => $primaryCount,
                'secondary_assignment_count' => $secondaryCount,
            ],
        ];
    }

    private function normalizeAssignmentInput(array $input, bool $allowEndDate): array
    {
        $storeId = filter_var($input['store_id'] ?? null, FILTER_VALIDATE_INT);
        $positionId = filter_var($input['position_id'] ?? null, FILTER_VALIDATE_INT);
        if ($storeId === false || $storeId <= 0 || $positionId === false || $positionId <= 0) {
            throw new OrganizationAssignmentValidationException('门店和岗位 ID 必须为正整数');
        }

        $systemRole = appRoleCode(trim((string) ($input['system_role'] ?? $input['role'] ?? '')));
        if (!preg_match('/^[a-z][a-z0-9_-]{1,49}$/', $systemRole)) {
            throw new OrganizationAssignmentValidationException('系统角色编码无效');
        }

        $startDate = $this->normalizeDate(
            (string) ($input['effective_date'] ?? $input['start_date'] ?? ''),
            '任职生效日期'
        );
        $endDate = null;
        if ($allowEndDate && array_key_exists('end_date', $input) && trim((string) $input['end_date']) !== '') {
            $endDate = $this->normalizeDate((string) $input['end_date'], '任职结束日期');
            if ($endDate < $startDate) {
                throw new OrganizationAssignmentValidationException('任职结束日期不能早于开始日期');
            }
        }

        return [
            'store_id' => (int) $storeId,
            'position_id' => (int) $positionId,
            'system_role' => $systemRole,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'change_reason' => $this->normalizeChangeReason((string) ($input['change_reason'] ?? '')),
        ];
    }

    private function normalizeDate(string $value, string $label): string
    {
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new OrganizationAssignmentValidationException($label . '格式无效');
        }

        return $value;
    }

    private function normalizeChangeReason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new OrganizationAssignmentValidationException('变更原因不能为空且不能超过 500 个字符');
        }

        return $reason;
    }

    private function lockActiveStaff(int $staffId): array
    {
        if ($staffId <= 0) {
            throw new OrganizationAssignmentValidationException('员工 ID 无效');
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, store_id, primary_position_id, role, job_title, status, lifecycle_status '
            . 'FROM staffs WHERE id = ? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$staffId]);
        $staff = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$staff || (int) $staff['status'] !== 1 || $staff['lifecycle_status'] !== 'active') {
            throw new OrganizationAssignmentValidationException('员工不存在或当前不在职');
        }

        return $staff;
    }

    private function requireActiveAssignmentOrganization(int $storeId, int $positionId, string $role): array
    {
        $storeStmt = $this->pdo->prepare(
            'SELECT id, name FROM stores WHERE id = ? AND status = 1 LIMIT 1 FOR UPDATE'
        );
        $storeStmt->execute([$storeId]);
        $store = $storeStmt->fetch(PDO::FETCH_ASSOC);
        if (!$store) {
            throw new OrganizationAssignmentValidationException('门店不存在或已停用');
        }

        $positionStmt = $this->pdo->prepare(
            'SELECT id, position_name, applicable_roles_json FROM organization_positions '
            . 'WHERE id = ? AND status = 1 LIMIT 1 FOR UPDATE'
        );
        $positionStmt->execute([$positionId]);
        $position = $positionStmt->fetch(PDO::FETCH_ASSOC);
        if (!$position) {
            throw new OrganizationAssignmentValidationException('岗位不存在或已停用');
        }
        $roles = json_decode((string) ($position['applicable_roles_json'] ?? '[]'), true);
        $roles = is_array($roles) ? array_map('appRoleCode', $roles) : [];
        if ($roles && !in_array($role, $roles, true)) {
            throw new OrganizationAssignmentValidationException('系统角色不属于岗位适用角色');
        }

        return ['store' => $store, 'position' => $position];
    }

    private function lockStaffAssignments(int $staffId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, staff_id, store_id, position_id, system_role, assignment_type, '
            . 'start_date, end_date, change_reason, operator_staff_id '
            . 'FROM staff_assignments WHERE staff_id = ? ORDER BY start_date ASC, id ASC FOR UPDATE'
        );
        $stmt->execute([$staffId]);

        return array_map([$this, 'normalizeAssignmentRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function findAssignmentStaffId(int $assignmentId): int
    {
        if ($assignmentId <= 0) {
            throw new OrganizationAssignmentValidationException('任职记录 ID 无效');
        }
        $stmt = $this->pdo->prepare(
            'SELECT staff_id FROM staff_assignments WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$assignmentId]);
        $staffId = $stmt->fetchColumn();
        if ($staffId === false) {
            throw new OrganizationAssignmentValidationException('任职记录不存在');
        }

        return (int) $staffId;
    }

    private function insertAssignment(
        int $staffId,
        array $data,
        string $assignmentType,
        ?string $endDate,
        int $operatorStaffId
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO staff_assignments '
            . '(staff_id, store_id, position_id, system_role, assignment_type, start_date, end_date, '
            . 'change_reason, operator_staff_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $staffId,
            $data['store_id'],
            $data['position_id'],
            $data['system_role'],
            $assignmentType,
            $data['start_date'],
            $endDate,
            $data['change_reason'],
            $operatorStaffId ?: null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function synchronizeCurrentPrimaryFields(int $staffId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.store_id, a.position_id, a.system_role, p.position_name '
            . 'FROM staff_assignments a '
            . 'INNER JOIN organization_positions p ON p.id = a.position_id '
            . "WHERE a.staff_id = ? AND a.assignment_type = 'primary' "
            . 'AND a.start_date <= CURDATE() AND (a.end_date IS NULL OR a.end_date >= CURDATE()) '
            . 'ORDER BY a.start_date DESC, a.id DESC LIMIT 1'
        );
        $stmt->execute([$staffId]);
        $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$assignment) {
            throw new OrganizationAssignmentConflictException('当前日期缺少有效主岗任职');
        }

        $updateStmt = $this->pdo->prepare(
            'UPDATE staffs SET store_id = ?, primary_position_id = ?, role = ?, job_title = ? WHERE id = ?'
        );
        $updateStmt->execute([
            $assignment['store_id'],
            $assignment['position_id'],
            $assignment['system_role'],
            $assignment['position_name'],
            $staffId,
        ]);
    }

    private function assignmentMatches(array $assignment, array $data): bool
    {
        return (int) $assignment['store_id'] === $data['store_id']
            && (int) $assignment['position_id'] === $data['position_id']
            && $assignment['system_role'] === $data['system_role'];
    }

    private function dateRangesOverlap(
        string $leftStart,
        ?string $leftEnd,
        string $rightStart,
        ?string $rightEnd
    ): bool {
        return ($leftEnd === null || $rightStart <= $leftEnd)
            && ($rightEnd === null || $leftStart <= $rightEnd);
    }

    private function previousDate(string $date): string
    {
        return (new DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d');
    }

    private function commitIdempotentAssignment(array $assignment, bool $commitTransaction = true): array
    {
        $result = $this->getAssignment((int) $assignment['id']);
        if ($commitTransaction) {
            $this->pdo->commit();
        }

        return $result + ['idempotent' => true];
    }

    private function normalizeAssignmentRow(array $assignment): array
    {
        $assignment['id'] = (int) $assignment['id'];
        $assignment['staff_id'] = (int) $assignment['staff_id'];
        $assignment['store_id'] = (int) $assignment['store_id'];
        $assignment['position_id'] = (int) $assignment['position_id'];
        $assignment['end_date'] = $assignment['end_date'] === null ? null : (string) $assignment['end_date'];
        $assignment['operator_staff_id'] = empty($assignment['operator_staff_id'])
            ? null
            : (int) $assignment['operator_staff_id'];

        return $assignment;
    }

    private function formatAssignment(array $assignment): array
    {
        $assignment = $this->normalizeAssignmentRow($assignment);

        return [
            'id' => $assignment['id'],
            'staff_id' => $assignment['staff_id'],
            'store_id' => $assignment['store_id'],
            'store_name' => isset($assignment['store_name']) ? (string) $assignment['store_name'] : null,
            'position_id' => $assignment['position_id'],
            'position_name' => isset($assignment['position_name']) ? (string) $assignment['position_name'] : null,
            'system_role' => (string) $assignment['system_role'],
            'assignment_type' => (string) $assignment['assignment_type'],
            'start_date' => (string) $assignment['start_date'],
            'end_date' => $assignment['end_date'],
            'change_reason' => (string) ($assignment['change_reason'] ?? ''),
            'operator_staff_id' => $assignment['operator_staff_id'],
        ];
    }

    private function formatAssignmentStaff(array $staff): array
    {
        return [
            'id' => (int) $staff['id'],
            'store_id' => (int) $staff['store_id'],
            'primary_position_id' => empty($staff['primary_position_id'])
                ? null
                : (int) $staff['primary_position_id'],
            'role' => (string) $staff['role'],
            'job_title' => (string) ($staff['job_title'] ?? ''),
        ];
    }

    private function storeSelectSql(): string
    {
        return 'SELECT s.id, s.store_code, s.name, s.manager_staff_id, s.manager_name, '
            . 's.sort_order, s.status, manager.name AS linked_manager_name, '
            . 'manager.status AS manager_status, manager.lifecycle_status AS manager_lifecycle_status, '
            . '(SELECT COUNT(*) FROM staffs current_staff '
            . "WHERE current_staff.store_id = s.id AND current_staff.status = 1 "
            . "AND current_staff.lifecycle_status = 'active') AS current_staff_count, "
            . '(SELECT COUNT(*) FROM staff_assignments current_assignment '
            . 'INNER JOIN staffs assigned_staff ON assigned_staff.id = current_assignment.staff_id '
            . 'WHERE current_assignment.store_id = s.id '
            . "AND assigned_staff.status = 1 AND assigned_staff.lifecycle_status = 'active' "
            . 'AND current_assignment.start_date <= CURDATE() '
            . 'AND (current_assignment.end_date IS NULL OR current_assignment.end_date >= CURDATE())) '
            . 'AS current_assignment_count, '
            . '(SELECT COUNT(*) FROM staff_assignments all_assignment WHERE all_assignment.store_id = s.id) '
            . 'AS total_assignment_count '
            . 'FROM stores s LEFT JOIN staffs manager ON manager.id = s.manager_staff_id';
    }

    private function lockStore(int $storeId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, store_code, name, manager_staff_id, manager_name, sort_order, status '
            . 'FROM stores WHERE id = ? FOR UPDATE'
        );
        $stmt->execute([$storeId]);
        $store = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$store) {
            throw new OrganizationStoreValidationException('门店不存在');
        }

        return $store;
    }

    private function assertStoreCodeAvailable(string $storeCode, ?int $excludeId = null): void
    {
        $sql = 'SELECT id FROM stores WHERE store_code = ?';
        $params = [$storeCode];
        if ($excludeId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }
        $sql .= ' LIMIT 1 FOR UPDATE';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->fetchColumn() !== false) {
            throw new OrganizationStoreConflictException('门店编码已存在', 'store_code');
        }
    }

    private function resolveActiveManager(?int $managerStaffId): ?array
    {
        if ($managerStaffId === null) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, name, status, lifecycle_status FROM staffs WHERE id = ? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$managerStaffId]);
        $manager = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$manager || (int) $manager['status'] !== 1 || $manager['lifecycle_status'] !== 'active') {
            throw new OrganizationStoreValidationException('负责人必须是当前在职员工');
        }

        return $manager;
    }

    private function normalizeStoreInput(array $input, ?array $before = null): array
    {
        $storeCode = array_key_exists('store_code', $input)
            ? strtoupper(trim((string) $input['store_code']))
            : (string) ($before['store_code'] ?? '');
        if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{1,63}$/', $storeCode)) {
            throw new OrganizationStoreValidationException('门店编码需为 2-64 位大写字母、数字、下划线或连字符');
        }

        $name = array_key_exists('name', $input)
            ? trim((string) $input['name'])
            : (string) ($before['name'] ?? '');
        if ($name === '' || mb_strlen($name) > 100) {
            throw new OrganizationStoreValidationException('门店名称不能为空且不能超过 100 个字符');
        }

        $beforeManagerStaffId = !empty($before['manager_staff_id']) ? (int) $before['manager_staff_id'] : null;
        $managerStaffId = $beforeManagerStaffId;
        if (array_key_exists('manager_staff_id', $input)) {
            $rawManagerId = $input['manager_staff_id'];
            if ($rawManagerId === null || $rawManagerId === '' || $rawManagerId === 0 || $rawManagerId === '0') {
                $managerStaffId = null;
            } else {
                $managerStaffId = filter_var($rawManagerId, FILTER_VALIDATE_INT);
                if ($managerStaffId === false || $managerStaffId <= 0) {
                    throw new OrganizationStoreValidationException('负责人员工 ID 无效');
                }
            }
        }

        $sortOrder = array_key_exists('sort_order', $input)
            ? filter_var($input['sort_order'], FILTER_VALIDATE_INT)
            : (int) ($before['sort_order'] ?? 0);
        if ($sortOrder === false || abs((int) $sortOrder) > self::MAX_SORT_ORDER) {
            throw new OrganizationStoreValidationException('排序值需为 -1000000 至 1000000 的整数');
        }

        if (array_key_exists('status', $input)) {
            try {
                $status = $this->normalizeStatus($input['status']);
            } catch (OrganizationPositionValidationException $error) {
                throw new OrganizationStoreValidationException('门店状态必须为启用或停用');
            }
        } else {
            $status = (int) ($before['status'] ?? 1);
        }

        return [
            'store_code' => $storeCode,
            'name' => $name,
            'manager_staff_id' => $managerStaffId === null ? null : (int) $managerStaffId,
            'manager_changed' => $before === null || $managerStaffId !== $beforeManagerStaffId,
            'sort_order' => (int) $sortOrder,
            'status' => $status,
        ];
    }

    private function formatStore(array $store): array
    {
        $currentAssignments = (int) ($store['current_assignment_count'] ?? 0);
        $totalAssignments = (int) ($store['total_assignment_count'] ?? $currentAssignments);
        $managerStaffId = !empty($store['manager_staff_id']) ? (int) $store['manager_staff_id'] : null;
        $managerName = trim((string) ($store['linked_manager_name'] ?? ''));
        if ($managerName === '') {
            $managerName = trim((string) ($store['manager_name'] ?? ''));
        }

        return [
            'id' => (int) $store['id'],
            'store_code' => (string) $store['store_code'],
            'name' => (string) $store['name'],
            'manager_staff_id' => $managerStaffId,
            'manager_name' => $managerName,
            'manager_active' => $managerStaffId !== null
                && (int) ($store['manager_status'] ?? 0) === 1
                && ($store['manager_lifecycle_status'] ?? '') === 'active',
            'sort_order' => (int) $store['sort_order'],
            'status' => (int) $store['status'],
            'reference_summary' => [
                'current_staff_count' => (int) ($store['current_staff_count'] ?? 0),
                'current_assignment_count' => $currentAssignments,
                'historical_assignment_count' => max(0, $totalAssignments - $currentAssignments),
            ],
        ];
    }

    private function positionSelectSql(): string
    {
        return 'SELECT p.id, p.position_code, p.position_name, p.applicable_roles_json, '
            . 'p.sort_order, p.status, '
            . '(SELECT COUNT(*) FROM staffs s '
            . "WHERE s.primary_position_id = p.id AND s.status = 1 AND s.lifecycle_status = 'active') "
            . 'AS current_staff_count, '
            . '(SELECT COUNT(*) FROM staff_assignments a '
            . 'WHERE a.position_id = p.id AND a.start_date <= CURDATE() '
            . 'AND (a.end_date IS NULL OR a.end_date >= CURDATE())) AS current_assignment_count, '
            . '(SELECT COUNT(*) FROM staff_assignments a WHERE a.position_id = p.id) '
            . 'AS total_assignment_count '
            . 'FROM organization_positions p';
    }

    private function lockPosition(int $positionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, position_code, position_name, applicable_roles_json, sort_order, status '
            . 'FROM organization_positions WHERE id = ? FOR UPDATE'
        );
        $stmt->execute([$positionId]);
        $position = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$position) {
            throw new OrganizationPositionValidationException('岗位不存在');
        }

        return $position;
    }

    private function assertPositionCodeAvailable(string $positionCode, ?int $excludeId = null): void
    {
        $sql = 'SELECT id FROM organization_positions WHERE position_code = ?';
        $params = [$positionCode];
        if ($excludeId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }
        $sql .= ' LIMIT 1 FOR UPDATE';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->fetchColumn() !== false) {
            throw new OrganizationPositionConflictException('岗位编码已存在', 'position_code');
        }
    }

    private function normalizePositionInput(array $input, ?array $before = null): array
    {
        $positionCode = array_key_exists('position_code', $input)
            ? strtolower(trim((string) $input['position_code']))
            : (string) ($before['position_code'] ?? '');
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $positionCode)) {
            throw new OrganizationPositionValidationException('岗位编码需为 2-64 位小写字母、数字、下划线或连字符');
        }

        $positionName = array_key_exists('position_name', $input)
            ? trim((string) $input['position_name'])
            : (string) ($before['position_name'] ?? '');
        if ($positionName === '' || mb_strlen($positionName) > 100) {
            throw new OrganizationPositionValidationException('岗位名称不能为空且不能超过 100 个字符');
        }

        $rolesProvided = array_key_exists('applicable_roles', $input) || array_key_exists('roles', $input);
        $rolesInput = $input['applicable_roles'] ?? $input['roles'] ?? ($before['applicable_roles'] ?? []);
        $applicableRoles = $this->normalizeRoles($rolesInput);
        if (!$rolesProvided && $before === null && !$applicableRoles) {
            throw new OrganizationPositionValidationException('至少选择一个适用系统角色');
        }

        $sortOrder = array_key_exists('sort_order', $input)
            ? filter_var($input['sort_order'], FILTER_VALIDATE_INT)
            : (int) ($before['sort_order'] ?? 0);
        if ($sortOrder === false || abs((int) $sortOrder) > self::MAX_SORT_ORDER) {
            throw new OrganizationPositionValidationException('排序值需为 -1000000 至 1000000 的整数');
        }

        $status = array_key_exists('status', $input)
            ? $this->normalizeStatus($input['status'])
            : (int) ($before['status'] ?? 1);

        return [
            'position_code' => $positionCode,
            'position_name' => $positionName,
            'applicable_roles' => $applicableRoles,
            'sort_order' => (int) $sortOrder,
            'status' => $status,
        ];
    }

    private function normalizeRoles($roles): array
    {
        if (is_string($roles)) {
            $trimmedRoles = trim($roles);
            if ($trimmedRoles !== '' && substr($trimmedRoles, 0, 1) === '[') {
                $decodedRoles = json_decode($trimmedRoles, true);
                if (!is_array($decodedRoles)) {
                    throw new OrganizationPositionValidationException('适用系统角色格式无效');
                }
                $roles = $decodedRoles;
            } else {
                $roles = $trimmedRoles === '' ? [] : explode(',', $trimmedRoles);
            }
        }
        if (!is_array($roles)) {
            throw new OrganizationPositionValidationException('适用系统角色格式无效');
        }

        $normalizedRoles = [];
        foreach ($roles as $role) {
            if (!is_scalar($role)) {
                throw new OrganizationPositionValidationException('适用系统角色格式无效');
            }
            $normalizedRole = appRoleCode(trim((string) $role));
            if (!preg_match('/^[a-z][a-z0-9_-]{1,49}$/', $normalizedRole)) {
                throw new OrganizationPositionValidationException('适用系统角色编码无效');
            }
            $normalizedRoles[$normalizedRole] = true;
        }

        $result = array_keys($normalizedRoles);
        sort($result, SORT_STRING);
        if (!$result) {
            throw new OrganizationPositionValidationException('至少选择一个适用系统角色');
        }

        return $result;
    }

    private function normalizeStatus($status): int
    {
        if ($status === true || $status === 1 || $status === '1' || $status === 'true' || $status === 'on') {
            return 1;
        }
        if ($status === false || $status === 0 || $status === '0' || $status === 'false' || $status === 'off') {
            return 0;
        }

        throw new OrganizationPositionValidationException('岗位状态必须为启用或停用');
    }

    private function formatPosition(array $position): array
    {
        $roles = json_decode((string) ($position['applicable_roles_json'] ?? '[]'), true);
        if (!is_array($roles)) {
            $roles = [];
        }

        $currentAssignments = (int) ($position['current_assignment_count'] ?? 0);
        $totalAssignments = (int) ($position['total_assignment_count'] ?? $currentAssignments);

        return [
            'id' => (int) $position['id'],
            'position_code' => (string) $position['position_code'],
            'position_name' => (string) $position['position_name'],
            'applicable_roles' => array_values($roles),
            'sort_order' => (int) $position['sort_order'],
            'status' => (int) $position['status'],
            'reference_summary' => [
                'current_staff_count' => (int) ($position['current_staff_count'] ?? 0),
                'current_assignment_count' => $currentAssignments,
                'historical_assignment_count' => max(0, $totalAssignments - $currentAssignments),
            ],
        ];
    }

    private function encodeRoles(array $roles): string
    {
        $encodedRoles = json_encode(array_values($roles), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encodedRoles === false) {
            throw new RuntimeException('适用系统角色保存失败');
        }

        return $encodedRoles;
    }

    private function rollBackIfNeeded(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function isDuplicateKeyError(Throwable $error): bool
    {
        if (!$error instanceof PDOException) {
            return false;
        }

        return isset($error->errorInfo[1]) && (int) $error->errorInfo[1] === 1062;
    }
}
