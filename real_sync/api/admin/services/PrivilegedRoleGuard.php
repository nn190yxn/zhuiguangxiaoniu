<?php

declare(strict_types=1);

final class PrivilegedRoleValidationException extends RuntimeException {}
final class PrivilegedRoleConflictException extends RuntimeException {}

final class PrivilegedRoleGuard {
    private const TOKEN_TTL_SECONDS = 300;

    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function issueConfirmation(array $input, array $approverUser, array $approverStaff): array {
        $approverUserId = (int)($approverUser['user_id'] ?? $approverUser['ID'] ?? 0);
        $approverStaffId = (int)($approverStaff['id'] ?? 0);
        if ($approverUserId <= 0 || $approverStaffId <= 0 || appRoleCode((string)($approverStaff['role'] ?? '')) !== 'admin') {
            throw new PrivilegedRoleValidationException('system administrator approval is required');
        }

        $requesterUserId = (int)($input['requester_user_id'] ?? 0);
        $targetStaffId = (int)($input['staff_id'] ?? $input['target_staff_id'] ?? 0);
        $targetRole = appRoleCode((string)($input['target_role'] ?? $input['role'] ?? ''));
        if ($requesterUserId <= 0 || $targetStaffId <= 0 || $targetRole === '') {
            throw new PrivilegedRoleValidationException('requester, target staff, and target role are required');
        }
        if ($requesterUserId === $approverUserId) {
            throw new PrivilegedRoleValidationException('a different system administrator must approve this change');
        }

        $requester = $this->loadActiveRequester($requesterUserId);
        $target = $this->loadTarget($targetStaffId);
        $currentRole = appRoleCode((string)$target['role']);
        if ($currentRole === $targetRole || ($currentRole !== 'admin' && $targetRole !== 'admin')) {
            throw new PrivilegedRoleValidationException('approval is only available for privileged role changes');
        }

        $issuedAt = time();
        $expiresAt = $issuedAt + self::TOKEN_TTL_SECONDS;
        $payload = [
            'typ' => 'staff-privileged-role-confirm',
            'ver' => 1,
            'action' => 'change_privileged_staff_role',
            'requester_user_id' => $requesterUserId,
            'requester_staff_id' => (int)$requester['id'],
            'approver_user_id' => $approverUserId,
            'approver_staff_id' => $approverStaffId,
            'target_staff_id' => $targetStaffId,
            'target_session_version' => (int)$target['session_version'],
            'from_role' => $currentRole,
            'to_role' => $targetRole,
            'iat' => $issuedAt,
            'nbf' => $issuedAt,
            'exp' => $expiresAt,
            'jti' => bin2hex(random_bytes(16)),
        ];
        $token = $this->encodeToken(['alg' => 'HS256', 'typ' => 'STAFF_PRIVILEGED_ROLE_CONFIRM'], $payload);

        return [
            'confirmation_token' => $token,
            'confirmation_expires_at' => gmdate('c', $expiresAt),
            'approval' => $this->approvalSnapshot($payload),
        ];
    }

    public function assertRoleChangeAllowed(
        array $target,
        string $targetRole,
        array $input,
        array $operatorUser,
        array $operatorStaff
    ): ?array {
        $currentRole = appRoleCode((string)($target['role'] ?? ''));
        $targetRole = appRoleCode($targetRole);
        if ($currentRole === $targetRole || ($currentRole !== 'admin' && $targetRole !== 'admin')) {
            return null;
        }

        $token = trim((string)($input['privileged_role_confirmation_token'] ?? $input['confirmation_token'] ?? ''));
        if ($token === '') {
            throw new PrivilegedRoleValidationException('privileged role confirmation token is required');
        }
        $payload = $this->decodeToken($token);
        $operatorUserId = (int)($operatorUser['user_id'] ?? $operatorUser['ID'] ?? 0);
        $operatorStaffId = (int)($operatorStaff['id'] ?? 0);
        $now = time();
        $valid = ($payload['typ'] ?? '') === 'staff-privileged-role-confirm'
            && ($payload['action'] ?? '') === 'change_privileged_staff_role'
            && (int)($payload['requester_user_id'] ?? 0) === $operatorUserId
            && (int)($payload['requester_staff_id'] ?? 0) === $operatorStaffId
            && (int)($payload['approver_user_id'] ?? 0) !== $operatorUserId
            && (int)($payload['target_staff_id'] ?? 0) === (int)($target['id'] ?? 0)
            && (int)($payload['target_session_version'] ?? -1) === (int)($target['session_version'] ?? 0)
            && ($payload['from_role'] ?? '') === $currentRole
            && ($payload['to_role'] ?? '') === $targetRole
            && (int)($payload['nbf'] ?? PHP_INT_MAX) <= $now
            && (int)($payload['exp'] ?? 0) >= $now;
        if (!$valid) {
            throw new PrivilegedRoleValidationException('privileged role confirmation token is expired or no longer matches current state');
        }
        return $this->approvalSnapshot($payload);
    }

    public function protectLastAdministrator(array $target, string $targetRole, int $targetStatus, string $targetLifecycle): void {
        $removesActiveAdmin = appRoleCode((string)($target['role'] ?? '')) === 'admin'
            && (int)($target['status'] ?? 0) === 1
            && (string)($target['lifecycle_status'] ?? '') === 'active'
            && (appRoleCode($targetRole) !== 'admin' || $targetStatus !== 1 || $targetLifecycle !== 'active');
        if (!$removesActiveAdmin) {
            return;
        }

        $stmt = $this->db->query(
            "SELECT id, role FROM staffs WHERE status = 1 AND lifecycle_status = 'active' ORDER BY id FOR UPDATE"
        );
        $activeAdministratorIds = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $staff) {
            if (appRoleCode((string)$staff['role']) === 'admin') {
                $activeAdministratorIds[] = (int)$staff['id'];
            }
        }
        if (count($activeAdministratorIds) <= 1 && in_array((int)$target['id'], $activeAdministratorIds, true)) {
            throw new PrivilegedRoleConflictException('the last active system administrator cannot be disabled, offboarded, or demoted');
        }
    }

    public function permissionChangeSnapshot(string $beforeRole, string $afterRole, ?array $approval): array {
        return [
            'before_role' => appRoleCode($beforeRole),
            'after_role' => appRoleCode($afterRole),
            'before_permissions' => adminPermissionsForRole($beforeRole),
            'after_permissions' => adminPermissionsForRole($afterRole),
            'approval' => $approval,
        ];
    }

    private function loadActiveRequester(int $userId): array {
        $stmt = $this->db->prepare(
            "SELECT id, user_id, role FROM staffs WHERE user_id = ? AND status = 1 AND lifecycle_status = 'active' LIMIT 1"
        );
        $stmt->execute([$userId]);
        $requester = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$requester || !in_array(appRoleCode((string)$requester['role']), ['operation', 'admin'], true)) {
            throw new PrivilegedRoleValidationException('requester cannot manage privileged roles');
        }
        return $requester;
    }

    private function loadTarget(int $staffId): array {
        $stmt = $this->db->prepare('SELECT id, role, session_version FROM staffs WHERE id = ? LIMIT 1');
        $stmt->execute([$staffId]);
        $target = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$target) {
            throw new PrivilegedRoleValidationException('target staff does not exist');
        }
        return $target;
    }

    private function approvalSnapshot(array $payload): array {
        return [
            'requester_user_id' => (int)$payload['requester_user_id'],
            'requester_staff_id' => (int)$payload['requester_staff_id'],
            'approver_user_id' => (int)$payload['approver_user_id'],
            'approver_staff_id' => (int)$payload['approver_staff_id'],
            'jti' => (string)$payload['jti'],
            'issued_at' => gmdate('c', (int)$payload['iat']),
        ];
    }

    private function encodeToken(array $header, array $payload): string {
        $headerEncoded = $this->base64UrlEncode($this->json($header));
        $payloadEncoded = $this->base64UrlEncode($this->json($payload));
        $secret = hash_hmac('sha256', 'staff-privileged-role-confirm-v1', JWT_SECRET, true);
        $signature = hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, $secret, true);
        return $headerEncoded . '.' . $payloadEncoded . '.' . $this->base64UrlEncode($signature);
    }

    private function decodeToken(string $token): array {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new PrivilegedRoleValidationException('privileged role confirmation token is invalid');
        }
        [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;
        $signature = $this->base64UrlDecode($signatureEncoded);
        $secret = hash_hmac('sha256', 'staff-privileged-role-confirm-v1', JWT_SECRET, true);
        $expected = hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, $secret, true);
        if (!hash_equals($expected, $signature)) {
            throw new PrivilegedRoleValidationException('privileged role confirmation token signature is invalid');
        }
        $header = json_decode($this->base64UrlDecode($headerEncoded), true);
        $payload = json_decode($this->base64UrlDecode($payloadEncoded), true);
        if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256' || !is_array($payload)) {
            throw new PrivilegedRoleValidationException('privileged role confirmation token is invalid');
        }
        return $payload;
    }

    private function json(array $value): string {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new PrivilegedRoleValidationException('privileged role confirmation encoding failed');
        }
        return $encoded;
    }

    private function base64UrlEncode(string $value): string {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string {
        if ($value === '' || preg_match('/[^A-Za-z0-9_-]/', $value)) {
            throw new PrivilegedRoleValidationException('privileged role confirmation token encoding is invalid');
        }
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new PrivilegedRoleValidationException('privileged role confirmation token encoding is invalid');
        }
        return $decoded;
    }
}
