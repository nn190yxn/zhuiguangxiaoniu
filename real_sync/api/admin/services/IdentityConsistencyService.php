<?php

declare(strict_types=1);

final class IdentityConsistencyValidationException extends RuntimeException {}

final class IdentityConsistencyService {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function synchronizeRole(int $staffId, string $targetRole, bool $rotateSession = true): array {
        if ($staffId <= 0) {
            throw new IdentityConsistencyValidationException('staff ID is invalid');
        }
        $targetRole = appRoleCode($targetRole);
        if (!in_array($targetRole, ['sales', 'coach', 'manager', 'operation', 'finance', 'admin', 'ceo', 'staff'], true)) {
            throw new IdentityConsistencyValidationException('system role is invalid');
        }

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $staff = $this->lockStaff($staffId);
            $userId = (int)($staff['user_id'] ?? 0);
            if ($userId <= 0) {
                throw new IdentityConsistencyValidationException('linked account does not exist');
            }
            $this->lockAccount($userId);
            $metadata = $this->lockRoleMetadata($userId);
            $targetWordPressRole = $this->wordpressRole($targetRole);
            $currentWordPressRole = $this->currentWordPressRole($metadata['wp_capabilities'] ?? null);
            $needsSync = (string)$staff['role'] !== $targetRole || $currentWordPressRole !== $targetWordPressRole;

            if ($needsSync || $rotateSession) {
                $sql = 'UPDATE staffs SET role = ?, updated_at = NOW()';
                if ($rotateSession) {
                    $sql .= ', session_version = session_version + 1';
                }
                $this->db->prepare($sql . ' WHERE id = ?')->execute([$targetRole, $staffId]);
                $this->upsertMetadata($userId, 'wp_capabilities', serialize([$targetWordPressRole => true]), $metadata);
                $this->upsertMetadata($userId, 'wp_user_level', $targetWordPressRole === 'administrator' ? '10' : '0', $metadata);
            }

            $result = [
                'changed' => $needsSync || $rotateSession,
                'staff_id' => $staffId,
                'user_id' => $userId,
                'role' => $targetRole,
                'wordpress_role' => $targetWordPressRole,
                'session_version' => (int)$staff['session_version'] + ($rotateSession ? 1 : 0),
            ];
            if ($ownsTransaction) {
                $this->db->commit();
            }
            return $result;
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    private function lockStaff(int $staffId): array {
        $stmt = $this->db->prepare('SELECT id, user_id, role, session_version FROM staffs WHERE id = ? FOR UPDATE');
        $stmt->execute([$staffId]);
        $staff = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$staff) {
            throw new IdentityConsistencyValidationException('staff does not exist');
        }
        return $staff;
    }

    private function lockAccount(int $userId): void {
        $stmt = $this->db->prepare('SELECT ID FROM wp_users WHERE ID = ? FOR UPDATE');
        $stmt->execute([$userId]);
        if (!$stmt->fetchColumn()) {
            throw new IdentityConsistencyValidationException('linked account does not exist');
        }
    }

    private function lockRoleMetadata(int $userId): array {
        $stmt = $this->db->prepare(
            "SELECT meta_key, meta_value FROM wp_usermeta WHERE user_id = ? AND meta_key IN ('wp_capabilities', 'wp_user_level') FOR UPDATE"
        );
        $stmt->execute([$userId]);
        $metadata = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $metadata[(string)$row['meta_key']] = (string)$row['meta_value'];
        }
        return $metadata;
    }

    private function wordpressRole(string $role): string {
        if ($role === 'admin') {
            return 'administrator';
        }
        return $role === 'manager' ? 'zgxn_store_manager' : 'zgxn_staff';
    }

    private function currentWordPressRole(?string $capabilities): string {
        if ($capabilities === null || $capabilities === '') {
            return '';
        }
        $roles = @unserialize($capabilities, ['allowed_classes' => false]);
        if (!is_array($roles)) {
            return '';
        }
        foreach (['administrator', 'zgxn_store_manager', 'zgxn_staff'] as $role) {
            if (!empty($roles[$role])) {
                return $role;
            }
        }
        return '';
    }

    private function upsertMetadata(int $userId, string $key, string $value, array $metadata): void {
        if (array_key_exists($key, $metadata)) {
            $stmt = $this->db->prepare('UPDATE wp_usermeta SET meta_value = ? WHERE user_id = ? AND meta_key = ?');
            $stmt->execute([$value, $userId, $key]);
            return;
        }
        $stmt = $this->db->prepare('INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (?, ?, ?)');
        $stmt->execute([$userId, $key, $value]);
    }
}
