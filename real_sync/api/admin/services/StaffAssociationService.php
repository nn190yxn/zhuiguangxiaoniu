<?php

declare(strict_types=1);

final class StaffAssociationValidationException extends RuntimeException {}

final class StaffAssociationService {
    private const TOKEN_TTL_SECONDS = 300;

    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function inspectForPurge(
        int $staffId,
        array $operatorUser,
        array $operatorStaff,
        bool $issueToken = true
    ): array {
        if ($staffId <= 0) {
            throw new StaffAssociationValidationException('staff ID is invalid');
        }
        $staff = $this->staffIdentity($staffId);
        $userId = (int)($staff['user_id'] ?? 0);
        $categories = [];
        $complete = true;
        $blockingTotal = 0;

        foreach ($this->associationSpecs() as $spec) {
            $category = $spec['category'];
            if (!isset($categories[$category])) {
                $categories[$category] = ['count' => 0, 'blocking_count' => 0, 'checks' => []];
            }
            $value = $spec['domain'] === 'user' ? $userId : $staffId;
            $check = $this->countAssociation($spec, $value);
            $categories[$category]['checks'][] = $check;
            $categories[$category]['count'] += $check['count'];
            $categories[$category]['blocking_count'] += $check['blocking_count'];
            $blockingTotal += $check['blocking_count'];
            if ($check['status'] === 'schema_incompatible' || $check['status'] === 'error') {
                $complete = false;
            }
        }

        $digestPayload = [
            'staff_id' => $staffId,
            'user_id' => $userId,
            'session_version' => (int)$staff['session_version'],
            'complete' => $complete,
            'blocking_total' => $blockingTotal,
            'categories' => $categories,
        ];
        $associationDigest = hash('sha256', $this->canonicalJson($digestPayload));
        $eligible = $complete && $blockingTotal === 0;
        $token = null;
        $expiresAt = null;
        if ($eligible && $issueToken) {
            [$token, $expiresAt] = $this->issueConfirmationToken(
                $staff,
                $operatorUser,
                $operatorStaff,
                $associationDigest
            );
        }

        return [
            'staff' => [
                'id' => $staffId,
                'employee_no' => (string)$staff['employee_no'],
                'name' => (string)$staff['name'],
                'lifecycle_status' => (string)$staff['lifecycle_status'],
                'user_id' => $userId,
                'session_version' => (int)$staff['session_version'],
            ],
            'complete' => $complete,
            'eligible_for_purge' => $eligible,
            'blocking_total' => $blockingTotal,
            'categories' => $categories,
            'association_digest' => $associationDigest,
            'recommendation' => $eligible ? 'purge' : 'offboard',
            'confirmation_token' => $token,
            'confirmation_expires_at' => $expiresAt,
        ];
    }

    public function validateConfirmationToken(
        string $token,
        array $staff,
        array $operatorUser,
        array $operatorStaff,
        string $associationDigest
    ): array {
        $parts = explode('.', trim($token));
        if (count($parts) !== 3) {
            throw new StaffAssociationValidationException('purge confirmation token is invalid');
        }
        [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;
        $header = json_decode($this->base64UrlDecode($headerEncoded), true);
        $payload = json_decode($this->base64UrlDecode($payloadEncoded), true);
        if (!is_array($header) || !is_array($payload)
            || ($header['alg'] ?? '') !== 'HS256'
            || ($header['typ'] ?? '') !== 'STAFF_PURGE_CONFIRM') {
            throw new StaffAssociationValidationException('purge confirmation token is invalid');
        }
        $secret = hash_hmac('sha256', 'staff-purge-confirm-v1', JWT_SECRET, true);
        $expectedSignature = hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, $secret, true);
        $providedSignature = $this->base64UrlDecode($signatureEncoded);
        if (!hash_equals($expectedSignature, $providedSignature)) {
            throw new StaffAssociationValidationException('purge confirmation token signature is invalid');
        }

        $now = time();
        $operatorUserId = (int)($operatorUser['user_id'] ?? $operatorUser['ID'] ?? 0);
        $operatorStaffId = (int)($operatorStaff['id'] ?? 0);
        $valid = ($payload['typ'] ?? '') === 'staff-miscreation-confirm'
            && (int)($payload['ver'] ?? 0) === 1
            && ($payload['action'] ?? '') === 'purge_miscreated_staff'
            && (int)($payload['operator_user_id'] ?? 0) === $operatorUserId
            && (int)($payload['operator_staff_id'] ?? 0) === $operatorStaffId
            && (int)($payload['staff_id'] ?? 0) === (int)($staff['id'] ?? 0)
            && (int)($payload['linked_user_id'] ?? 0) === (int)($staff['user_id'] ?? 0)
            && hash_equals((string)($payload['employee_no_hash'] ?? ''), hash('sha256', (string)($staff['employee_no'] ?? '')))
            && (int)($payload['staff_session_version'] ?? -1) === (int)($staff['session_version'] ?? 0)
            && hash_equals((string)($payload['association_digest'] ?? ''), $associationDigest)
            && (int)($payload['iat'] ?? 0) <= $now + 30
            && (int)($payload['nbf'] ?? 0) <= $now
            && (int)($payload['exp'] ?? 0) > $now
            && is_string($payload['jti'] ?? null)
            && preg_match('/^[a-f0-9]{32}$/', (string)$payload['jti']);
        if (!$valid) {
            throw new StaffAssociationValidationException('purge confirmation token is expired or no longer matches current state');
        }
        return $payload;
    }

    private function staffIdentity(int $staffId): array {
        $stmt = $this->db->prepare(
            'SELECT id, user_id, employee_no, name, lifecycle_status, session_version FROM staffs WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$staffId]);
        $staff = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$staff) {
            throw new StaffAssociationValidationException('staff does not exist');
        }
        if ((int)($staff['user_id'] ?? 0) <= 0) {
            throw new StaffAssociationValidationException('linked account does not exist');
        }
        return $staff;
    }

    private function countAssociation(array $spec, int $value): array {
        $table = $spec['table'];
        $column = $spec['column'];
        $label = (string)($spec['source'] ?? ($table . '.' . $column));
        if (!adminTableExists($this->db, $table)) {
            return ['source' => $label, 'status' => 'absent', 'count' => 0, 'blocking_count' => 0];
        }
        if (!adminColumnExists($this->db, $table, $column)) {
            return ['source' => $label, 'status' => 'schema_incompatible', 'count' => 0, 'blocking_count' => 0];
        }
        try {
            $sql = 'SELECT COUNT(*) FROM `' . $table . '` WHERE `' . $column . '` = ?';
            if (!empty($spec['condition'])) {
                $sql .= ' AND ' . $spec['condition'];
            }
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$value]);
            $count = (int)$stmt->fetchColumn();
            $blockingCount = max(0, $count - (int)($spec['allowance'] ?? 0));
            return [
                'source' => $label,
                'status' => 'checked',
                'count' => $count,
                'blocking_count' => $blockingCount,
            ];
        } catch (Throwable $error) {
            error_log('[staff.association.check] ' . $label . ': ' . $error->getMessage());
            return ['source' => $label, 'status' => 'error', 'count' => 0, 'blocking_count' => 0];
        }
    }

    private function associationSpecs(): array {
        return [
            ['category' => 'identity_baseline', 'table' => 'staff_assignments', 'column' => 'staff_id', 'domain' => 'staff', 'condition' => "assignment_type = 'primary'", 'allowance' => 1, 'source' => 'staff_assignments.primary'],
            ['category' => 'identity_baseline', 'table' => 'staff_assignments', 'column' => 'staff_id', 'domain' => 'staff', 'condition' => "assignment_type = 'secondary'", 'source' => 'staff_assignments.secondary'],
            ['category' => 'login_devices', 'table' => 'device_logins', 'column' => 'staff_id', 'domain' => 'staff'],
            ['category' => 'login_devices', 'table' => 'login_audit_logs', 'column' => 'staff_id', 'domain' => 'staff'],
            ['category' => 'login_devices', 'table' => 'login_audit_logs', 'column' => 'user_id', 'domain' => 'user'],
            ['category' => 'workload', 'table' => 'workload_daily_reports', 'column' => 'staff_id', 'domain' => 'staff'],
            ['category' => 'workload', 'table' => 'workload_evidences', 'column' => 'staff_id', 'domain' => 'staff'],
            ['category' => 'workload', 'table' => 'workload_audit_tasks', 'column' => 'staff_id', 'domain' => 'staff'],
            ['category' => 'workload', 'table' => 'workload_audit_tasks', 'column' => 'auditor_staff_id', 'domain' => 'staff'],
            ['category' => 'workload', 'table' => 'workload_submission_obligations', 'column' => 'staff_id', 'domain' => 'staff'],
            ['category' => 'workload', 'table' => 'workload_metric_versions', 'column' => 'created_by_staff_id', 'domain' => 'staff'],
            ['category' => 'workload', 'table' => 'workload_role_rule_versions', 'column' => 'created_by_staff_id', 'domain' => 'staff'],
            ['category' => 'workload', 'table' => 'workload_alert_events', 'column' => 'staff_id', 'domain' => 'staff'],
            ['category' => 'workload', 'table' => 'workload_alert_events', 'column' => 'handled_by_staff_id', 'domain' => 'staff'],
            ['category' => 'workload', 'table' => 'workload_export_jobs', 'column' => 'requested_by_staff_id', 'domain' => 'staff'],
            ['category' => 'workload', 'table' => 'workload_report_corrections', 'column' => 'requested_by_staff_id', 'domain' => 'staff'],
            ['category' => 'workload', 'table' => 'workload_report_corrections', 'column' => 'operated_by_staff_id', 'domain' => 'staff'],
            ['category' => 'workload', 'table' => 'workload_audit_logs', 'column' => 'operator_staff_id', 'domain' => 'staff'],
            ['category' => 'learning_pass', 'table' => 'user_course_progress', 'column' => 'user_id', 'domain' => 'user'],
            ['category' => 'learning_pass', 'table' => 'user_lesson_progress', 'column' => 'user_id', 'domain' => 'user'],
            ['category' => 'learning_pass', 'table' => 'user_knowledge_progress', 'column' => 'user_id', 'domain' => 'user'],
            ['category' => 'learning_pass', 'table' => 'exam_records', 'column' => 'user_id', 'domain' => 'user'],
            ['category' => 'learning_pass', 'table' => 'user_pass_progress', 'column' => 'user_id', 'domain' => 'user'],
            ['category' => 'learning_pass', 'table' => 'pass_voice_records', 'column' => 'user_id', 'domain' => 'user'],
            ['category' => 'learning_pass', 'table' => 'pass_certificates', 'column' => 'user_id', 'domain' => 'user'],
            ['category' => 'learning_pass', 'table' => 'user_achievements', 'column' => 'user_id', 'domain' => 'user'],
            ['category' => 'drill_review', 'table' => 'user_drill_tasks', 'column' => 'user_id', 'domain' => 'user'],
            ['category' => 'drill_review', 'table' => 'drill_records', 'column' => 'user_id', 'domain' => 'user'],
            ['category' => 'drill_review', 'table' => 'script_analysis_records', 'column' => 'user_id', 'domain' => 'user'],
            ['category' => 'drill_review', 'table' => 'drill_conversations', 'column' => 'user_id', 'domain' => 'user'],
            ['category' => 'drill_review', 'table' => 'script_ai_feedback', 'column' => 'user_id', 'domain' => 'user'],
            ['category' => 'drill_review', 'table' => 'skill_review_records', 'column' => 'staff_id', 'domain' => 'staff'],
            ['category' => 'drill_review', 'table' => 'skill_review_records', 'column' => 'user_id', 'domain' => 'user'],
            ['category' => 'notifications_messages', 'table' => 'policy_notifications', 'column' => 'user_id', 'domain' => 'user'],
            ['category' => 'notifications_messages', 'table' => 'policy_read_history', 'column' => 'user_id', 'domain' => 'user'],
            ['category' => 'notifications_messages', 'table' => 'policy_subscriptions', 'column' => 'user_id', 'domain' => 'user'],
            ['category' => 'notifications_messages', 'table' => 'mini_user_notifications', 'column' => 'user_id', 'domain' => 'user'],
            ['category' => 'notifications_messages', 'table' => 'mini_user_subscriptions', 'column' => 'staff_id', 'domain' => 'staff'],
            ['category' => 'notifications_messages', 'table' => 'mini_user_subscriptions', 'column' => 'user_id', 'domain' => 'user'],
            ['category' => 'notifications_messages', 'table' => 'mini_reminder_jobs', 'column' => 'target_staff_id', 'domain' => 'staff'],
            ['category' => 'notifications_messages', 'table' => 'mini_reminder_jobs', 'column' => 'target_user_id', 'domain' => 'user'],
            ['category' => 'notifications_messages', 'table' => 'wecom_message_logs', 'column' => 'target_staff_id', 'domain' => 'staff'],
            ['category' => 'notifications_messages', 'table' => 'wecom_message_logs', 'column' => 'target_user_id', 'domain' => 'user'],
            ['category' => 'points', 'table' => 'user_points', 'column' => 'user_id', 'domain' => 'user'],
            ['category' => 'points', 'table' => 'points_records', 'column' => 'user_id', 'domain' => 'user'],
            ['category' => 'points', 'table' => 'points_exchange_records', 'column' => 'user_id', 'domain' => 'user'],
            ['category' => 'other_business', 'table' => 'summer_camp_assessments', 'column' => 'staff_id', 'domain' => 'staff'],
            ['category' => 'other_business', 'table' => 'surveys', 'column' => 'creator_id', 'domain' => 'staff'],
            ['category' => 'other_business', 'table' => 'stores', 'column' => 'manager_staff_id', 'domain' => 'staff'],
            ['category' => 'other_business', 'table' => 'staffs', 'column' => 'offboarded_by', 'domain' => 'staff'],
            ['category' => 'other_business', 'table' => 'staff_import_batches', 'column' => 'requested_by_staff_id', 'domain' => 'staff'],
            ['category' => 'other_business', 'table' => 'staff_import_rows', 'column' => 'staff_id', 'domain' => 'staff'],
            ['category' => 'other_business', 'table' => 'staff_profile_correction_requests', 'column' => 'staff_id', 'domain' => 'staff'],
            ['category' => 'other_business', 'table' => 'staff_profile_correction_requests', 'column' => 'handled_by_staff_id', 'domain' => 'staff'],
            ['category' => 'audit_actor_history', 'table' => 'staff_assignments', 'column' => 'operator_staff_id', 'domain' => 'staff'],
            ['category' => 'audit_actor_history', 'table' => 'admin_operation_logs', 'column' => 'operator_staff_id', 'domain' => 'staff'],
            ['category' => 'audit_actor_history', 'table' => 'admin_operation_logs', 'column' => 'operator_user_id', 'domain' => 'user'],
            ['category' => 'audit_actor_history', 'table' => 'wecom_sync_logs', 'column' => 'operator_staff_id', 'domain' => 'staff'],
            ['category' => 'audit_actor_history', 'table' => 'wecom_sync_logs', 'column' => 'operator_user_id', 'domain' => 'user'],
        ];
    }

    private function issueConfirmationToken(
        array $staff,
        array $operatorUser,
        array $operatorStaff,
        string $associationDigest
    ): array {
        $operatorUserId = (int)($operatorUser['user_id'] ?? $operatorUser['ID'] ?? 0);
        $operatorStaffId = (int)($operatorStaff['id'] ?? 0);
        if ($operatorUserId <= 0 || $operatorStaffId <= 0) {
            throw new StaffAssociationValidationException('operator identity is incomplete');
        }
        $issuedAt = time();
        $expiresAt = $issuedAt + self::TOKEN_TTL_SECONDS;
        $header = ['alg' => 'HS256', 'typ' => 'STAFF_PURGE_CONFIRM'];
        $payload = [
            'typ' => 'staff-miscreation-confirm',
            'ver' => 1,
            'action' => 'purge_miscreated_staff',
            'operator_user_id' => $operatorUserId,
            'operator_staff_id' => $operatorStaffId,
            'staff_id' => (int)$staff['id'],
            'linked_user_id' => (int)$staff['user_id'],
            'employee_no_hash' => hash('sha256', (string)$staff['employee_no']),
            'staff_session_version' => (int)$staff['session_version'],
            'association_digest' => $associationDigest,
            'iat' => $issuedAt,
            'nbf' => $issuedAt,
            'exp' => $expiresAt,
            'jti' => bin2hex(random_bytes(16)),
        ];
        $headerEncoded = $this->base64UrlEncode($this->canonicalJson($header));
        $payloadEncoded = $this->base64UrlEncode($this->canonicalJson($payload));
        $secret = hash_hmac('sha256', 'staff-purge-confirm-v1', JWT_SECRET, true);
        $signature = hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, $secret, true);
        return [
            $headerEncoded . '.' . $payloadEncoded . '.' . $this->base64UrlEncode($signature),
            gmdate('c', $expiresAt),
        ];
    }

    private function canonicalJson(array $value): string {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new StaffAssociationValidationException('association summary encoding failed');
        }
        return $encoded;
    }

    private function base64UrlEncode(string $value): string {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string {
        if ($value === '' || preg_match('/[^A-Za-z0-9_-]/', $value)) {
            throw new StaffAssociationValidationException('purge confirmation token encoding is invalid');
        }
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new StaffAssociationValidationException('purge confirmation token encoding is invalid');
        }
        return $decoded;
    }
}
