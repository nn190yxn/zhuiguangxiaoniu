<?php
declare(strict_types=1);

require_once __DIR__ . '/IdentityConsistencyService.php';
require_once __DIR__ . '/PrivilegedRoleGuard.php';

final class StaffLifecycleValidationException extends RuntimeException {}

final class StaffIdentityConflictException extends RuntimeException {
    private array $conflictFields;
    private array $profiles;

    public function __construct(array $conflictFields, array $profiles) {
        parent::__construct('员工身份信息已关联其他档案');
        $this->conflictFields = array_values(array_unique($conflictFields));
        $this->profiles = $profiles;
    }

    public function conflictFields(): array {
        return $this->conflictFields;
    }

    public function profiles(): array {
        return $this->profiles;
    }
}

final class StaffPurgeBlockedException extends RuntimeException {
    private array $associationSummary;

    public function __construct(array $associationSummary) {
        parent::__construct('staff has business associations or the association check is incomplete');
        $this->associationSummary = $associationSummary;
    }

    public function associationSummary(): array {
        return $this->associationSummary;
    }
}

final class StaffLifecycleService {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function create(array $input, array $operatorUser, array $operatorStaff): array {
        $data = $this->normalizeCreateInput($input);
        $this->validateCreateInput($data, false);
        if (!adminTableExists($this->db, 'admin_operation_logs')) {
            throw new RuntimeException('admin operation log schema is not ready');
        }

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $store = $this->requireActiveStore($data['store_id']);
            $position = $this->requireActivePosition($data['position_id'], $data['role']);
            if ($data['employee_no'] === '') {
                $data['employee_no'] = $this->generateEmployeeNumber();
            }
            $data['username'] = $data['username'] ?: $data['employee_no'];
            $data['email'] = $data['email'] ?: strtolower($data['username']) . '@staff.local';
            $this->validateCreateInput($data, true);
            $this->assertUniqueIdentity($data);
            $userId = $this->createWordPressUser($data);
            $staffId = $this->createStaff($data, $position, $userId);
            $identityConsistency = (new IdentityConsistencyService($this->db))->synchronizeRole(
                $staffId,
                $data['role'],
                false
            );
            $this->createPrimaryAssignment($data, $staffId, (int)($operatorStaff['id'] ?? 0));

            $created = [
                'id' => $staffId,
                'employee_no' => $data['employee_no'],
                'name' => $data['name'],
                'phone' => $data['phone'],
                'store_id' => $data['store_id'],
                'store_name' => (string)$store['name'],
                'position_id' => $data['position_id'],
                'position_name' => (string)$position['position_name'],
                'role' => $data['role'],
                'user_id' => $userId,
                'username' => $data['username'],
                'entry_date' => $data['entry_date'],
                'lifecycle_status' => 'active',
                'identity_consistency' => $identityConsistency,
            ];
            adminRecordOperation($this->db, $operatorUser, $operatorStaff, [
                'module' => 'staff',
                'action' => 'create',
                'target_type' => 'staff',
                'target_id' => (string)$staffId,
                'after' => $created,
            ]);
            if ($ownsTransaction) {
                $this->db->commit();
            }
            return $created;
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function update(int $staffId, array $input, array $operatorUser, array $operatorStaff): array {
        if ($staffId <= 0) {
            throw new StaffLifecycleValidationException('staff ID is invalid');
        }
        ensureAdminOperationLogsTable($this->db);

        $this->db->beginTransaction();
        try {
            $beforeRow = $this->lockStaffForUpdate($staffId);
            if ((string)$beforeRow['lifecycle_status'] === 'offboarded') {
                throw new StaffLifecycleValidationException('offboarded staff is read-only');
            }
            $before = $this->getStaffSnapshot($staffId);
            $data = $this->normalizeUpdateInput($input, $beforeRow);
            $privilegedRoleGuard = new PrivilegedRoleGuard($this->db);
            $privilegedRoleGuard->protectLastAdministrator(
                $beforeRow,
                $data['role'],
                $data['status'],
                $data['status'] === 1 ? 'active' : 'inactive'
            );
            $basicChanges = $this->changedBasicFields($data, $beforeRow);
            $organizationChanged = $data['store_id'] !== (int)$beforeRow['store_id']
                || $data['position_id'] !== (int)($beforeRow['primary_position_id'] ?? 0)
                || $data['role'] !== (string)$beforeRow['role'];
            $roleChanged = $data['role'] !== (string)$beforeRow['role'];
            $privilegedRoleApproval = $roleChanged
                ? $privilegedRoleGuard->assertRoleChangeAllowed(
                    $beforeRow,
                    $data['role'],
                    $input,
                    $operatorUser,
                    $operatorStaff
                )
                : null;
            $permissionChange = $roleChanged
                ? $privilegedRoleGuard->permissionChangeSnapshot(
                    (string)$beforeRow['role'],
                    $data['role'],
                    $privilegedRoleApproval
                )
                : null;
            if ($basicChanges === [] && !$organizationChanged) {
                throw new StaffLifecycleValidationException('no staff fields changed');
            }
            if ($organizationChanged && $data['status'] !== 1) {
                throw new StaffLifecycleValidationException('inactive staff cannot receive organization changes');
            }
            if ($data['phone'] !== (string)$beforeRow['phone']) {
                $this->assertUpdatePhoneAvailable($staffId, $data['phone']);
            }

            $assignment = null;
            if ($organizationChanged) {
                $organization = new OrganizationService($this->db);
                $assignment = $organization->changePrimaryAssignment(
                    $staffId,
                    [
                        'store_id' => $data['store_id'],
                        'position_id' => $data['position_id'],
                        'system_role' => $data['role'],
                        'effective_date' => $data['effective_date'],
                        'change_reason' => $data['change_reason'],
                    ],
                    $operatorUser,
                    $operatorStaff
                );
            }

            $identityConsistency = null;
            if ($roleChanged) {
                $identityConsistency = (new IdentityConsistencyService($this->db))->synchronizeRole(
                    $staffId,
                    $data['role'],
                    true
                );
            }

            if ($basicChanges !== []) {
                $sets = [];
                $params = [];
                foreach ($basicChanges as $field => $value) {
                    $sets[] = $field . ' = ?';
                    $params[] = $value;
                }
                if (array_key_exists('status', $basicChanges)) {
                    $sets[] = 'lifecycle_status = ?';
                    $params[] = $data['status'] === 1 ? 'active' : 'inactive';
                    $sets[] = 'session_version = session_version + 1';
                }
                $params[] = $staffId;
                $stmt = $this->db->prepare(
                    'UPDATE staffs SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = ?'
                );
                $stmt->execute($params);

                if (array_key_exists('status', $basicChanges) && (int)$beforeRow['user_id'] > 0) {
                    $stmt = $this->db->prepare('UPDATE wp_users SET user_status = ? WHERE ID = ?');
                    $stmt->execute([$data['status'] === 1 ? 0 : 1, (int)$beforeRow['user_id']]);
                }
            }

            $after = $this->getStaffSnapshot($staffId);
            adminRecordOperation($this->db, $operatorUser, $operatorStaff, [
                'module' => 'staff',
                'action' => 'update',
                'target_type' => 'staff',
                'target_id' => (string)$staffId,
                'before' => [
                    'staff' => $before,
                    'permissions' => $permissionChange['before_permissions'] ?? null,
                ],
                'after' => [
                    'staff' => $after,
                    'organization_assignment' => $assignment,
                    'identity_consistency' => $identityConsistency,
                    'effective_date' => $organizationChanged ? $data['effective_date'] : null,
                    'change_reason' => $organizationChanged ? $data['change_reason'] : null,
                    'permissions' => $permissionChange['after_permissions'] ?? null,
                    'privileged_role_approval' => $permissionChange['approval'] ?? null,
                ],
            ]);
            $this->db->commit();

            return [
                'item' => $after,
                'organization_assignment' => $assignment,
                'identity_consistency' => $identityConsistency,
            ];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function offboard(int $staffId, array $input, array $operatorUser, array $operatorStaff): array {
        if ($staffId <= 0) {
            throw new StaffLifecycleValidationException('staff ID is invalid');
        }
        $offboardDate = trim((string)($input['offboard_date'] ?? $input['effective_date'] ?? ''));
        $reason = trim((string)($input['offboard_reason'] ?? $input['reason'] ?? ''));
        $confirmed = in_array($input['confirmed'] ?? $input['confirm'] ?? false, [true, 1, '1', 'true'], true);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $offboardDate);
        if (!$date || $date->format('Y-m-d') !== $offboardDate || $offboardDate > date('Y-m-d')) {
            throw new StaffLifecycleValidationException('offboard date is invalid or in the future');
        }
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new StaffLifecycleValidationException('offboard reason is required and cannot exceed 500 characters');
        }
        if (!$confirmed) {
            throw new StaffLifecycleValidationException('offboard confirmation is required');
        }
        ensureAdminOperationLogsTable($this->db);

        $this->db->beginTransaction();
        try {
            $beforeRow = $this->lockStaffForUpdate($staffId);
            if ((string)$beforeRow['lifecycle_status'] === 'offboarded') {
                throw new StaffLifecycleValidationException('staff is already offboarded');
            }
            (new PrivilegedRoleGuard($this->db))->protectLastAdministrator(
                $beforeRow,
                (string)$beforeRow['role'],
                0,
                'offboarded'
            );
            $before = $this->getStaffSnapshot($staffId);
            $assignmentsBefore = $this->lockCurrentAssignments($staffId, $offboardDate);

            $stmt = $this->db->prepare(
                "UPDATE staff_assignments SET end_date = ?, change_reason = ?, operator_staff_id = ? "
                . 'WHERE staff_id = ? AND start_date <= ? AND (end_date IS NULL OR end_date > ?)'
            );
            $stmt->execute([
                $offboardDate,
                $reason,
                ((int)($operatorStaff['id'] ?? 0)) ?: null,
                $staffId,
                $offboardDate,
                $offboardDate,
            ]);

            $stmt = $this->db->prepare(
                "UPDATE staffs SET status = 0, lifecycle_status = 'offboarded', offboarded_at = ?, "
                . 'offboard_reason = ?, offboarded_by = ?, session_version = session_version + 1, updated_at = NOW() '
                . 'WHERE id = ?'
            );
            $stmt->execute([
                $offboardDate . ' 00:00:00',
                $reason,
                ((int)($operatorStaff['id'] ?? 0)) ?: null,
                $staffId,
            ]);
            $userId = (int)($beforeRow['user_id'] ?? 0);
            if ($userId > 0) {
                $this->db->prepare('UPDATE wp_users SET user_status = 1 WHERE ID = ?')->execute([$userId]);
            }

            $revocations = $this->revokeStaffAccess($staffId, $userId);
            $after = $this->getStaffSnapshot($staffId);
            $assignmentsAfter = $this->getAssignmentsByIds(array_column($assignmentsBefore, 'id'));
            adminRecordOperation($this->db, $operatorUser, $operatorStaff, [
                'module' => 'staff',
                'action' => 'offboard',
                'target_type' => 'staff',
                'target_id' => (string)$staffId,
                'before' => ['staff' => $before, 'assignments' => $assignmentsBefore],
                'after' => [
                    'staff' => $after,
                    'assignments' => $assignmentsAfter,
                    'offboard_date' => $offboardDate,
                    'offboard_reason' => $reason,
                    'revocations' => $revocations,
                ],
            ]);
            $this->db->commit();

            return [
                'item' => $after,
                'closed_assignments' => $assignmentsAfter,
                'revocations' => $revocations,
            ];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function restore(int $staffId, array $input, array $operatorUser, array $operatorStaff): array {
        if ($staffId <= 0) {
            throw new StaffLifecycleValidationException('staff ID is invalid');
        }
        $restoreDate = trim((string)($input['restore_date'] ?? $input['effective_date'] ?? ''));
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $restoreDate);
        if (!$date || $date->format('Y-m-d') !== $restoreDate || $restoreDate > date('Y-m-d')) {
            throw new StaffLifecycleValidationException('restore date is invalid or in the future');
        }
        $reason = trim((string)($input['restore_reason'] ?? $input['change_reason'] ?? $input['reason'] ?? ''));
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new StaffLifecycleValidationException('restore reason is required and cannot exceed 500 characters');
        }
        if (!array_key_exists('account_status', $input)
            || !in_array($input['account_status'], [true, 1, '1', 'active', 'enabled'], true)) {
            throw new StaffLifecycleValidationException('account status must be explicitly confirmed as active');
        }
        if (!array_key_exists('secondary_assignments', $input) || !is_array($input['secondary_assignments'])) {
            throw new StaffLifecycleValidationException('secondary assignments must be explicitly confirmed as an array');
        }
        $storeId = filter_var($input['store_id'] ?? null, FILTER_VALIDATE_INT);
        $positionId = filter_var($input['position_id'] ?? $input['primary_position_id'] ?? null, FILTER_VALIDATE_INT);
        $role = appRoleCode(trim((string)($input['role'] ?? $input['system_role'] ?? '')));
        if ($storeId === false || $storeId <= 0 || $positionId === false || $positionId <= 0 || $role === '') {
            throw new StaffLifecycleValidationException('store, primary position, and role are required');
        }
        ensureAdminOperationLogsTable($this->db);

        $this->db->beginTransaction();
        try {
            $beforeRow = $this->lockStaffForUpdate($staffId);
            if ((string)$beforeRow['lifecycle_status'] !== 'offboarded') {
                throw new StaffLifecycleValidationException('only offboarded staff can be restored');
            }
            $offboardDate = substr((string)($beforeRow['offboarded_at'] ?? ''), 0, 10);
            if ($offboardDate !== '' && $restoreDate <= $offboardDate) {
                throw new StaffLifecycleValidationException('restore date must be later than the offboard date');
            }
            $userId = (int)($beforeRow['user_id'] ?? 0);
            if ($userId <= 0) {
                throw new StaffLifecycleValidationException('linked account does not exist');
            }
            $accountStmt = $this->db->prepare('SELECT ID, user_status FROM wp_users WHERE ID = ? FOR UPDATE');
            $accountStmt->execute([$userId]);
            if (!$accountStmt->fetch(PDO::FETCH_ASSOC)) {
                throw new StaffLifecycleValidationException('linked account does not exist');
            }

            $before = $this->getStaffSnapshot($staffId);
            $privilegedRoleGuard = new PrivilegedRoleGuard($this->db);
            $privilegedRoleApproval = $privilegedRoleGuard->assertRoleChangeAllowed(
                $beforeRow,
                $role,
                $input,
                $operatorUser,
                $operatorStaff
            );
            $permissionChange = appRoleCode((string)$beforeRow['role']) !== $role
                ? $privilegedRoleGuard->permissionChangeSnapshot(
                    (string)$beforeRow['role'],
                    $role,
                    $privilegedRoleApproval
                )
                : null;
            $assignmentsBefore = $this->lockAllStaffAssignments($staffId);
            $this->db->prepare(
                "UPDATE staffs SET status = 1, lifecycle_status = 'active', offboarded_at = NULL, "
                . 'offboard_reason = NULL, offboarded_by = NULL, session_version = session_version + 1, updated_at = NOW() '
                . 'WHERE id = ?'
            )->execute([$staffId]);
            $this->db->prepare('UPDATE wp_users SET user_status = 0 WHERE ID = ?')->execute([$userId]);

            $organization = new OrganizationService($this->db);
            $primaryAssignment = $organization->changePrimaryAssignment(
                $staffId,
                [
                    'store_id' => (int)$storeId,
                    'position_id' => (int)$positionId,
                    'system_role' => $role,
                    'effective_date' => $restoreDate,
                    'change_reason' => $reason,
                ],
                $operatorUser,
                $operatorStaff
            );
            $secondaryAssignments = [];
            foreach ($input['secondary_assignments'] as $secondary) {
                if (!is_array($secondary)) {
                    throw new StaffLifecycleValidationException('each secondary assignment must be an object');
                }
                $secondaryAssignments[] = $organization->createSecondaryAssignment(
                    $staffId,
                    [
                        'store_id' => $secondary['store_id'] ?? null,
                        'position_id' => $secondary['position_id'] ?? null,
                        'system_role' => $secondary['system_role'] ?? $secondary['role'] ?? '',
                        'effective_date' => $restoreDate,
                        'end_date' => $secondary['end_date'] ?? null,
                        'change_reason' => $reason,
                    ],
                    $operatorUser,
                    $operatorStaff
                );
            }
            $identityConsistency = (new IdentityConsistencyService($this->db))->synchronizeRole(
                $staffId,
                $role,
                false
            );

            $after = $this->getStaffSnapshot($staffId);
            adminRecordOperation($this->db, $operatorUser, $operatorStaff, [
                'module' => 'staff',
                'action' => 'restore',
                'target_type' => 'staff',
                'target_id' => (string)$staffId,
                'before' => [
                    'staff' => $before,
                    'assignments' => $assignmentsBefore,
                    'permissions' => $permissionChange['before_permissions'] ?? null,
                ],
                'after' => [
                    'staff' => $after,
                    'restore_date' => $restoreDate,
                    'restore_reason' => $reason,
                    'account_status' => 'active',
                    'primary_assignment' => $primaryAssignment,
                    'secondary_assignments' => $secondaryAssignments,
                    'identity_consistency' => $identityConsistency,
                    'permissions' => $permissionChange['after_permissions'] ?? null,
                    'privileged_role_approval' => $permissionChange['approval'] ?? null,
                ],
            ]);
            $this->db->commit();

            return [
                'item' => $after,
                'primary_assignment' => $primaryAssignment,
                'secondary_assignments' => $secondaryAssignments,
                'identity_consistency' => $identityConsistency,
            ];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function purgeMiscreated(int $staffId, array $input, array $operatorUser, array $operatorStaff): array {
        if ($staffId <= 0) {
            throw new StaffLifecycleValidationException('staff ID is invalid');
        }
        $reason = trim((string)($input['purge_reason'] ?? $input['reason'] ?? ''));
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new StaffLifecycleValidationException('purge reason is required and cannot exceed 500 characters');
        }
        $confirmed = in_array($input['confirmed'] ?? $input['confirm'] ?? false, [true, 1, '1', 'true'], true);
        if (!$confirmed) {
            throw new StaffLifecycleValidationException('purge confirmation is required');
        }
        $token = trim((string)($input['confirmation_token'] ?? ''));
        if ($token === '') {
            throw new StaffLifecycleValidationException('purge confirmation token is required');
        }
        $operatorUserId = (int)($operatorUser['user_id'] ?? $operatorUser['ID'] ?? 0);
        $operatorStaffId = (int)($operatorStaff['id'] ?? 0);
        if ($operatorUserId <= 0 || $operatorStaffId <= 0) {
            throw new StaffLifecycleValidationException('operator identity is incomplete');
        }
        if ($operatorStaffId === $staffId) {
            throw new StaffLifecycleValidationException('operators cannot purge their own staff identity');
        }
        ensureAdminOperationLogsTable($this->db);

        $this->db->beginTransaction();
        try {
            $lockedStaff = $this->lockStaffForUpdate($staffId);
            $userId = (int)($lockedStaff['user_id'] ?? 0);
            if ($userId <= 0 || $userId === $operatorUserId) {
                throw new StaffLifecycleValidationException('linked account is invalid for purge');
            }
            $accountStmt = $this->db->prepare(
                'SELECT ID, user_login, user_email, user_status, user_registered, display_name '
                . 'FROM wp_users WHERE ID = ? FOR UPDATE'
            );
            $accountStmt->execute([$userId]);
            $account = $accountStmt->fetch(PDO::FETCH_ASSOC);
            if (!$account) {
                throw new StaffLifecycleValidationException('linked account does not exist');
            }
            $staffBefore = $this->getStaffSnapshot($staffId);
            $assignmentsBefore = $this->lockAllStaffAssignments($staffId);

            $associationService = new StaffAssociationService($this->db);
            $associationSummary = $associationService->inspectForPurge(
                $staffId,
                $operatorUser,
                $operatorStaff,
                false
            );
            if (!$associationSummary['eligible_for_purge']) {
                throw new StaffPurgeBlockedException($associationSummary);
            }
            $tokenPayload = $associationService->validateConfirmationToken(
                $token,
                $lockedStaff,
                $operatorUser,
                $operatorStaff,
                (string)$associationSummary['association_digest']
            );

            $assignmentDelete = $this->db->prepare('DELETE FROM staff_assignments WHERE staff_id = ?');
            $assignmentDelete->execute([$staffId]);
            if ($assignmentDelete->rowCount() !== count($assignmentsBefore)) {
                throw new RuntimeException('assignment purge count changed during the transaction');
            }
            $staffDelete = $this->db->prepare('DELETE FROM staffs WHERE id = ?');
            $staffDelete->execute([$staffId]);
            if ($staffDelete->rowCount() !== 1) {
                throw new RuntimeException('staff purge failed');
            }
            $metaDelete = $this->db->prepare('DELETE FROM wp_usermeta WHERE user_id = ?');
            $metaDelete->execute([$userId]);
            $accountDelete = $this->db->prepare('DELETE FROM wp_users WHERE ID = ?');
            $accountDelete->execute([$userId]);
            if ($accountDelete->rowCount() !== 1) {
                throw new RuntimeException('account purge failed');
            }

            $deletedCounts = [
                'staff_assignments' => $assignmentDelete->rowCount(),
                'staffs' => $staffDelete->rowCount(),
                'wp_usermeta' => $metaDelete->rowCount(),
                'wp_users' => $accountDelete->rowCount(),
            ];
            adminRecordOperation($this->db, $operatorUser, $operatorStaff, [
                'module' => 'staff',
                'action' => 'purge_miscreated',
                'target_type' => 'staff',
                'target_id' => (string)$staffId,
                'before' => [
                    'staff' => $staffBefore,
                    'account' => $account,
                    'assignments' => $assignmentsBefore,
                    'association_summary' => $associationSummary,
                ],
                'after' => [
                    'purged' => true,
                    'purge_reason' => $reason,
                    'deleted_counts' => $deletedCounts,
                    'association_digest' => $associationSummary['association_digest'],
                    'confirmation_jti' => $tokenPayload['jti'],
                ],
            ]);
            $this->db->commit();

            return [
                'purged' => true,
                'staff_id' => $staffId,
                'user_id' => $userId,
                'employee_no' => (string)$lockedStaff['employee_no'],
                'deleted_counts' => $deletedCounts,
                'association_digest' => $associationSummary['association_digest'],
            ];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    private function lockAllStaffAssignments(int $staffId): array {
        $stmt = $this->db->prepare(
            'SELECT id, staff_id, store_id, position_id, system_role, assignment_type, start_date, end_date, '
            . 'change_reason, operator_staff_id FROM staff_assignments '
            . 'WHERE staff_id = ? ORDER BY start_date ASC, id ASC FOR UPDATE'
        );
        $stmt->execute([$staffId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function lockCurrentAssignments(int $staffId, string $businessDate): array {
        $stmt = $this->db->prepare(
            'SELECT id, staff_id, store_id, position_id, system_role, assignment_type, start_date, end_date, '
            . 'change_reason, operator_staff_id FROM staff_assignments '
            . 'WHERE staff_id = ? AND start_date <= ? AND (end_date IS NULL OR end_date >= ?) '
            . 'ORDER BY start_date ASC, id ASC FOR UPDATE'
        );
        $stmt->execute([$staffId, $businessDate, $businessDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getAssignmentsByIds(array $assignmentIds): array {
        $assignmentIds = array_values(array_filter(array_map('intval', $assignmentIds), static fn(int $id): bool => $id > 0));
        if ($assignmentIds === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($assignmentIds), '?'));
        $stmt = $this->db->prepare(
            'SELECT id, staff_id, store_id, position_id, system_role, assignment_type, start_date, end_date, '
            . 'change_reason, operator_staff_id FROM staff_assignments WHERE id IN (' . $placeholders . ') '
            . 'ORDER BY start_date ASC, id ASC'
        );
        $stmt->execute($assignmentIds);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function revokeStaffAccess(int $staffId, int $userId): array {
        $revocations = ['devices' => 0, 'mini_subscriptions' => 0, 'policy_subscriptions' => 0];
        if (adminTableExists($this->db, 'device_logins')) {
            $stmt = $this->db->prepare(
                'UPDATE device_logins SET is_trusted = 0, is_active = 0 WHERE staff_id = ? AND (is_trusted = 1 OR is_active = 1)'
            );
            $stmt->execute([$staffId]);
            $revocations['devices'] = $stmt->rowCount();
        }
        if (adminTableExists($this->db, 'mini_user_subscriptions')) {
            $stmt = $this->db->prepare(
                "UPDATE mini_user_subscriptions SET accept_status = 'revoked', updated_at = NOW() "
                . "WHERE staff_id = ? AND accept_status <> 'revoked'"
            );
            $stmt->execute([$staffId]);
            $revocations['mini_subscriptions'] = $stmt->rowCount();
        }
        if ($userId > 0 && adminTableExists($this->db, 'policy_subscriptions')) {
            $stmt = $this->db->prepare('UPDATE policy_subscriptions SET enabled = 0 WHERE user_id = ? AND enabled <> 0');
            $stmt->execute([$userId]);
            $revocations['policy_subscriptions'] = $stmt->rowCount();
        }
        return $revocations;
    }

    private function lockStaffForUpdate(int $staffId): array {
        $stmt = $this->db->prepare(
            'SELECT id, employee_no, name, phone, store_id, primary_position_id, role, job_title, '
            . 'stage, status, lifecycle_status, offboarded_at, session_version, user_id FROM staffs WHERE id = ? FOR UPDATE'
        );
        $stmt->execute([$staffId]);
        $staff = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$staff) {
            throw new StaffLifecycleValidationException('staff does not exist');
        }
        return $staff;
    }

    private function normalizeUpdateInput(array $input, array $before): array {
        $name = array_key_exists('name', $input) ? trim((string)$input['name']) : (string)$before['name'];
        $phone = array_key_exists('phone', $input)
            ? preg_replace('/\s+/', '', trim((string)$input['phone']))
            : (string)$before['phone'];
        $stageChanged = array_key_exists('stage', $input);
        $stage = $stageChanged ? trim((string)$input['stage']) : (string)($before['stage'] ?? '');
        if ($name === '' || mb_strlen($name) > 100) {
            throw new StaffLifecycleValidationException('name is required and cannot exceed 100 characters');
        }
        if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
            throw new StaffLifecycleValidationException('phone format is invalid');
        }
        if ($stageChanged && ($stage === '' || mb_strlen($stage) > 50)) {
            throw new StaffLifecycleValidationException('stage is required and cannot exceed 50 characters');
        }

        $status = (int)$before['status'];
        if (array_key_exists('status', $input)) {
            $normalizedStatus = filter_var($input['status'], FILTER_VALIDATE_INT);
            if ($normalizedStatus === false || !in_array((int)$normalizedStatus, [0, 1], true)) {
                throw new StaffLifecycleValidationException('status must be 0 or 1');
            }
            $status = (int)$normalizedStatus;
        }
        $storeId = array_key_exists('store_id', $input) ? (int)$input['store_id'] : (int)$before['store_id'];
        $positionId = array_key_exists('position_id', $input)
            ? (int)$input['position_id']
            : (array_key_exists('primary_position_id', $input)
                ? (int)$input['primary_position_id']
                : (int)($before['primary_position_id'] ?? 0));
        $role = array_key_exists('role', $input) ? appRoleCode((string)$input['role']) : (string)$before['role'];
        if ($storeId <= 0 || $positionId <= 0 || $role === '') {
            throw new StaffLifecycleValidationException('store, position, and role are required');
        }
        $changeReason = trim((string)($input['change_reason'] ?? $input['reason'] ?? ''));
        if ($changeReason === '' || mb_strlen($changeReason) > 500) {
            throw new StaffLifecycleValidationException('change reason is required and cannot exceed 500 characters');
        }

        return [
            'name' => $name,
            'phone' => $phone,
            'stage' => $stage,
            'status' => $status,
            'store_id' => $storeId,
            'position_id' => $positionId,
            'role' => $role,
            'effective_date' => trim((string)($input['effective_date'] ?? '')),
            'change_reason' => $changeReason,
        ];
    }

    private function changedBasicFields(array $data, array $before): array {
        $changes = [];
        foreach (['name', 'phone', 'stage', 'status'] as $field) {
            $beforeValue = $field === 'status' ? (int)$before[$field] : (string)($before[$field] ?? '');
            if ($data[$field] !== $beforeValue) {
                $changes[$field] = $data[$field];
            }
        }
        return $changes;
    }

    private function assertUpdatePhoneAvailable(int $staffId, string $phone): void {
        $stmt = $this->db->prepare(
            'SELECT id, employee_no, name, phone, lifecycle_status, NULL AS user_login, NULL AS user_email '
            . 'FROM staffs WHERE phone = ? AND id <> ? FOR UPDATE'
        );
        $stmt->execute([$phone, $staffId]);
        $profiles = array_map([$this, 'maskConflictProfile'], $stmt->fetchAll(PDO::FETCH_ASSOC));
        if ($profiles !== []) {
            throw new StaffIdentityConflictException(['phone'], $profiles);
        }
    }

    private function getStaffSnapshot(int $staffId): array {
        $stmt = $this->db->prepare(
            'SELECT s.id, s.employee_no, s.name, s.phone, s.store_id, st.name AS store_name, '
            . 's.primary_position_id, p.position_name AS primary_position_name, s.role, s.job_title, '
            . 's.stage, s.status, s.lifecycle_status, s.offboarded_at, s.offboard_reason, '
            . 's.offboarded_by, s.session_version, s.user_id, u.user_status AS account_status '
            . 'FROM staffs s '
            . 'LEFT JOIN stores st ON st.id = s.store_id '
            . 'LEFT JOIN organization_positions p ON p.id = s.primary_position_id '
            . 'LEFT JOIN wp_users u ON u.ID = s.user_id '
            . 'WHERE s.id = ? LIMIT 1'
        );
        $stmt->execute([$staffId]);
        $staff = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$staff) {
            throw new StaffLifecycleValidationException('staff does not exist');
        }
        return [
            'id' => (int)$staff['id'],
            'employee_no' => (string)$staff['employee_no'],
            'name' => (string)$staff['name'],
            'phone' => (string)$staff['phone'],
            'store_id' => (int)$staff['store_id'],
            'store_name' => (string)($staff['store_name'] ?? ''),
            'primary_position_id' => (int)($staff['primary_position_id'] ?? 0),
            'primary_position_name' => (string)($staff['primary_position_name'] ?? ''),
            'role' => (string)$staff['role'],
            'job_title' => (string)($staff['job_title'] ?? ''),
            'stage' => (string)($staff['stage'] ?? ''),
            'status' => (int)$staff['status'],
            'lifecycle_status' => (string)$staff['lifecycle_status'],
            'offboarded_at' => $staff['offboarded_at'] === null ? null : (string)$staff['offboarded_at'],
            'offboard_reason' => $staff['offboard_reason'] === null ? null : (string)$staff['offboard_reason'],
            'offboarded_by' => empty($staff['offboarded_by']) ? null : (int)$staff['offboarded_by'],
            'session_version' => (int)$staff['session_version'],
            'user_id' => (int)($staff['user_id'] ?? 0),
            'account_status' => $staff['account_status'] === null ? null : (int)$staff['account_status'],
        ];
    }

    private function normalizeCreateInput(array $input): array {
        $employeeNo = trim((string)($input['employee_no'] ?? ''));
        return [
            'employee_no' => $employeeNo,
            'name' => trim((string)($input['name'] ?? '')),
            'phone' => preg_replace('/\s+/', '', trim((string)($input['phone'] ?? ''))),
            'store_id' => max(0, (int)($input['store_id'] ?? 0)),
            'position_id' => max(0, (int)($input['position_id'] ?? 0)),
            'role' => appRoleCode((string)($input['role'] ?? '')),
            'initial_password' => (string)($input['initial_password'] ?? $input['password'] ?? ''),
            'username' => trim((string)($input['username'] ?? '')),
            'email' => trim((string)($input['email'] ?? '')),
            'entry_date' => trim((string)($input['entry_date'] ?? '')) ?: date('Y-m-d'),
            'stage' => trim((string)($input['stage'] ?? '')) ?: 'intern',
        ];
    }

    private function validateCreateInput(array $data, bool $identityReady): void {
        foreach (['name' => 'name', 'phone' => 'phone', 'role' => 'role', 'initial_password' => 'initial password'] as $field => $label) {
            if ($data[$field] === '') {
                throw new StaffLifecycleValidationException($label . ' is required');
            }
        }
        if ($data['store_id'] <= 0 || $data['position_id'] <= 0) {
            throw new StaffLifecycleValidationException('store and position are required');
        }
        if ($data['employee_no'] !== '' && !preg_match('/^[A-Za-z0-9_-]{2,64}$/', $data['employee_no'])) {
            throw new StaffLifecycleValidationException('employee number format is invalid');
        }
        if (mb_strlen($data['name']) > 100) {
            throw new StaffLifecycleValidationException('name is too long');
        }
        if (!preg_match('/^1[3-9]\d{9}$/', $data['phone'])) {
            throw new StaffLifecycleValidationException('phone format is invalid');
        }
        if ($identityReady && !preg_match('/^[A-Za-z0-9_.@-]{2,60}$/', $data['username'])) {
            throw new StaffLifecycleValidationException('username format is invalid');
        }
        if ($identityReady && (!filter_var($data['email'], FILTER_VALIDATE_EMAIL) || strlen($data['email']) > 100)) {
            throw new StaffLifecycleValidationException('email format is invalid');
        }
        PasswordPolicy::validate($data['initial_password']);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $data['entry_date']);
        if (!$date || $date->format('Y-m-d') !== $data['entry_date']) {
            throw new StaffLifecycleValidationException('entry date format is invalid');
        }
    }

    private function generateEmployeeNumber(): string {
        if (!adminTableExists($this->db, 'staff_employee_number_sequences')) {
            throw new RuntimeException('Missing database migration 202607240004_staff_employee_number_sequence.sql');
        }
        $prefix = strtoupper(trim((string)configValue('STAFF_EMPLOYEE_NO_PREFIX', 'EMP')));
        $prefix = preg_replace('/[^A-Z0-9_-]/', '', $prefix) ?: 'EMP';
        $prefix = substr($prefix, 0, 48);
        $width = min(12, max(1, (int)configValue('STAFF_EMPLOYEE_NO_WIDTH', 6)));
        $start = max(1, (int)configValue('STAFF_EMPLOYEE_NO_START', 1));

        $stmt = $this->db->prepare('INSERT INTO staff_employee_number_sequences (sequence_key, current_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE sequence_key = VALUES(sequence_key)');
        $stmt->execute([$prefix, $start - 1]);
        $stmt = $this->db->prepare('SELECT current_value FROM staff_employee_number_sequences WHERE sequence_key = ? FOR UPDATE');
        $stmt->execute([$prefix]);
        $value = max($start - 1, (int)$stmt->fetchColumn());

        $exists = $this->db->prepare('SELECT 1 FROM staffs WHERE employee_no = ? LIMIT 1');
        do {
            $value++;
            $employeeNo = $prefix . str_pad((string)$value, $width, '0', STR_PAD_LEFT);
            if (strlen($employeeNo) > 64) {
                throw new StaffLifecycleValidationException('generated employee number is too long');
            }
            $exists->execute([$employeeNo]);
        } while ($exists->fetchColumn());

        $stmt = $this->db->prepare('UPDATE staff_employee_number_sequences SET current_value = ? WHERE sequence_key = ?');
        $stmt->execute([$value, $prefix]);
        return $employeeNo;
    }

    private function requireActiveStore(int $storeId): array {
        $stmt = $this->db->prepare('SELECT id, name FROM stores WHERE id = ? AND status = 1 LIMIT 1 FOR UPDATE');
        $stmt->execute([$storeId]);
        $store = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$store) {
            throw new StaffLifecycleValidationException('store is unavailable');
        }
        return $store;
    }

    private function requireActivePosition(int $positionId, string $role): array {
        $stmt = $this->db->prepare('SELECT id, position_name, applicable_roles_json FROM organization_positions WHERE id = ? AND status = 1 LIMIT 1 FOR UPDATE');
        $stmt->execute([$positionId]);
        $position = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$position) {
            throw new StaffLifecycleValidationException('position is unavailable');
        }
        $roles = json_decode((string)($position['applicable_roles_json'] ?? '[]'), true);
        $roles = is_array($roles) ? array_map('appRoleCode', $roles) : [];
        if ($roles && !in_array($role, $roles, true)) {
            throw new StaffLifecycleValidationException('role does not match the position');
        }
        return $position;
    }

    private function assertUniqueIdentity(array $data): void {
        $stmt = $this->db->prepare('SELECT s.id, s.employee_no, s.name, s.phone, s.lifecycle_status, u.ID AS account_id, u.user_login, u.user_email
            FROM staffs s
            LEFT JOIN wp_users u ON u.ID = s.user_id
            WHERE s.employee_no = ? OR s.phone = ? OR u.user_login = ? OR u.user_email = ?
            FOR UPDATE');
        $stmt->execute([$data['employee_no'], $data['phone'], $data['username'], $data['email']]);
        $profiles = [];
        $fields = [];
        $linkedAccounts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((string)$row['employee_no'] === $data['employee_no']) {
                $fields[] = 'employee_no';
            }
            if ((string)$row['phone'] === $data['phone']) {
                $fields[] = 'phone';
            }
            if ((string)($row['user_login'] ?? '') === $data['username'] || (string)($row['user_email'] ?? '') === $data['email']) {
                $fields[] = 'account';
                $linkedAccounts[] = (int)$row['account_id'];
            }
            $profiles[] = $this->maskConflictProfile($row);
        }

        $stmt = $this->db->prepare('SELECT ID, user_login, user_email FROM wp_users WHERE user_login = ? OR user_email = ? FOR UPDATE');
        $stmt->execute([$data['username'], $data['email']]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $fields[] = 'account';
            if (!in_array((int)$row['ID'], $linkedAccounts, true)) {
                $profiles[] = [
                    'staff_id' => null,
                    'employee_no' => null,
                    'name' => null,
                    'phone' => null,
                    'account' => adminMaskSensitiveValue((string)($row['user_login'] ?: $row['user_email'])),
                    'lifecycle_status' => null,
                ];
            }
        }
        if ($fields !== []) {
            throw new StaffIdentityConflictException($fields, $profiles);
        }
    }

    private function maskConflictProfile(array $row): array {
        return [
            'staff_id' => (int)$row['id'],
            'employee_no' => adminMaskSensitiveValue((string)$row['employee_no']),
            'name' => adminMaskSensitiveValue((string)$row['name']),
            'phone' => adminMaskSensitiveValue((string)$row['phone']),
            'account' => adminMaskSensitiveValue((string)($row['user_login'] ?: $row['user_email'])),
            'lifecycle_status' => (string)$row['lifecycle_status'],
        ];
    }

    private function createWordPressUser(array $data): int {
        $nicename = trim((string)preg_replace('/[^A-Za-z0-9_-]/', '-', strtolower($data['username'])), '-');
        $stmt = $this->db->prepare('INSERT INTO wp_users (user_login, user_pass, user_nicename, user_email, user_url, user_registered, user_activation_key, user_status, display_name) VALUES (?, ?, ?, ?, ?, NOW(), ?, 0, ?)');
        $stmt->execute([$data['username'], adminPasswordHash($data['initial_password']), $nicename ?: $data['employee_no'], $data['email'], '', '', $data['name']]);
        return (int)$this->db->lastInsertId();
    }

    private function createStaff(array $data, array $position, int $userId): int {
        $stmt = $this->db->prepare("INSERT INTO staffs
            (store_id, user_id, employee_no, name, role, job_title, phone, entry_date, stage, status, lifecycle_status, session_version, primary_position_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 'active', 1, ?)");
        $stmt->execute([
            $data['store_id'],
            $userId,
            $data['employee_no'],
            $data['name'],
            $data['role'],
            $position['position_name'],
            $data['phone'],
            $data['entry_date'],
            $data['stage'],
            $data['position_id'],
        ]);
        return (int)$this->db->lastInsertId();
    }

    private function createPrimaryAssignment(array $data, int $staffId, int $operatorStaffId): void {
        $stmt = $this->db->prepare("INSERT INTO staff_assignments
            (staff_id, store_id, position_id, system_role, assignment_type, start_date, change_reason, operator_staff_id)
            VALUES (?, ?, ?, ?, 'primary', ?, 'Initial staff creation', ?)");
        $stmt->execute([$staffId, $data['store_id'], $data['position_id'], $data['role'], $data['entry_date'], $operatorStaffId ?: null]);
    }
}
