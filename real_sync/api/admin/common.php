<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/common/context.php';
require_once dirname(__DIR__) . '/common/PasswordPolicy.php';
require_once dirname(__DIR__) . '/kernel/bootstrap.php';

function adminRoleTokens(array $user = null, array $staff = null): array {
    return appRoleTokensFromUser($user, $staff);
}

function adminIsWhitelistedHeadquarter(array $staff = null): bool {
    return false;
}

function adminCanAccessHeadquarter(array $user = null, array $staff = null): bool {
    return appCanAccessHeadquarter($user, $staff);
}

function adminCanAccessPerformance(array $user = null, array $staff = null): bool {
    return appCanAccessPerformance($user, $staff);
}

function adminCanAccessWorkload(array $user = null, array $staff = null): bool {
    return appCanAccessWorkload($user, $staff);
}

function isSuperAdminUser(array $user = null, array $staff = null): bool {
    if (!$user && $staff === null) {
        return false;
    }
    if ($staff === null && !empty($user['user_id'])) {
        $staff = getStaffByUserId((int)$user['user_id']);
    }
    return appIsSuperAdmin($user, $staff);
}

function adminEffectiveRole(array $user = null, array $staff = null): string {
    $staffRole = trim((string)($staff['role'] ?? ''));
    if ($staffRole !== '') {
        return appRoleCode($staffRole);
    }
    return appRoleCode((string)($user['role'] ?? ''));
}

function adminPermissionsForRole(string $role): array {
    $staffManagement = [
        'staff.view_all',
        'staff.create',
        'staff.edit',
        'staff.offboard',
        'staff.restore',
        'staff.reset_password',
        'staff.purge',
        'organization.manage',
        'workload.standard_manage',
        'role.manage_privileged',
        'staff.audit_view',
        'drill.content_manage',
        'drill.knowledge_manage',
        'drill.rubric_calibrate',
        'drill.plan_publish',
        'drill.review',
        'drill.coaching',
        'drill.analytics_all',
        'drill.migration_manage',
    ];
    $recruitmentManagement = [
        'recruitment.requirement_manage',
        'recruitment.requirement_approve',
        'recruitment.rule_manage',
        'recruitment.rule_publish',
        'recruitment.resume_upload',
        'recruitment.resume_view',
        'recruitment.resume_original_view',
        'recruitment.resume_phone_view',
        'recruitment.resume_contact',
        'recruitment.hire_approve',
        'recruitment.hire_convert',
        'recruitment.resume_export',
        'recruitment.audit_view',
        'recruitment.retention_manage',
        'recruitment.retention_execute',
    ];
    $recruitmentOperation = [
        'recruitment.requirement_manage',
        'recruitment.rule_manage',
        'recruitment.resume_upload',
        'recruitment.resume_view',
        'recruitment.resume_original_view',
        'recruitment.resume_phone_view',
        'recruitment.resume_contact',
        'recruitment.hire_approve',
        'recruitment.hire_convert',
        'recruitment.resume_export',
        'recruitment.audit_view',
    ];
    $recruitmentStore = [
        'recruitment.requirement_manage',
        'recruitment.resume_upload',
        'recruitment.resume_view',
        'recruitment.resume_contact',
    ];
    $policyManagement = ['policy.notify_send'];
    $operationalManagement = ['reminder.manage', 'wecom.sync'];
    $legacyEndpointGovernance = [
        'legacy_endpoint.view',
        'legacy_endpoint.manage',
        'legacy_endpoint.retirement_submit',
        'legacy_endpoint.retirement_approve',
    ];

    $role = appRoleCode($role);
    if ($role === 'admin') {
        return array_merge($staffManagement, $recruitmentManagement, $policyManagement, $operationalManagement, $legacyEndpointGovernance, ['system.settings']);
    }
    if ($role === 'ceo') {
        return array_merge($staffManagement, $recruitmentManagement, $policyManagement, $operationalManagement, $legacyEndpointGovernance, ['system.settings']);
    }
    if ($role === 'operation') {
        return array_merge($staffManagement, $recruitmentOperation, $operationalManagement);
    }
    if ($role === 'finance') {
        return $operationalManagement;
    }
    // Store managers and designated reviewers are limited again by the drill API scope policy.
    if ($role === 'manager') {
        return array_merge(['drill.review', 'drill.coaching', 'drill.analytics_all'], $recruitmentStore);
    }
    if ($role === 'reviewer') {
        return ['drill.review', 'drill.coaching'];
    }
    return [];
}

function adminHasPermission(string $permission, array $user = null, array $staff = null): bool {
    return in_array($permission, adminPermissionsForRole(adminEffectiveRole($user, $staff)), true);
}

function adminRequirePermission(string $permission): array {
    return adminRequireAuth(static function ($user, $staff) use ($permission): bool {
        return adminHasPermission($permission, $user, $staff);
    });
}

function adminRequireAuth(callable $checker): array {
    $userId = getCurrentUserId();
    if (!$userId) {
        jsonResponse(401, '请先登录');
    }

    $user = getJwtCurrentUser();
    $staff = getStaffByUserId($userId);
    if (!$checker($user, $staff)) {
        jsonResponse(403, '你没有权限访问该后台模块');
    }

    return [$userId, $user, $staff];
}

function adminTableExists(PDO $db, string $table): bool {
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    $quoted = $db->quote($table);
    $stmt = $db->query('SHOW TABLES LIKE ' . $quoted);
    $cache[$table] = (bool)($stmt ? $stmt->fetchColumn() : false);
    return $cache[$table];
}

function adminStoreNameById(PDO $db): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $stmt = $db->query("SELECT id, name FROM stores WHERE status = 1");
    $cache = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cache[(int)$row['id']] = (string)$row['name'];
    }
    return $cache;
}

function adminColumnExists(PDO $db, string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    if (!adminTableExists($db, $table)) {
        $cache[$key] = false;
        return false;
    }
    $quoted = $db->quote($column);
    $sql = 'SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '` LIKE ' . $quoted;
    $stmt = $db->query($sql);
    $cache[$key] = (bool)($stmt ? $stmt->fetchColumn() : false);
    return $cache[$key];
}

function adminJsonInput(): array {
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function getClientIpAddress(): ?string {
    $forwardedFor = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($forwardedFor !== '') {
        $address = trim(explode(',', $forwardedFor)[0]);
        if (filter_var($address, FILTER_VALIDATE_IP)) {
            return $address;
        }
    }

    foreach (['HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
        $address = trim((string)($_SERVER[$key] ?? ''));
        if (filter_var($address, FILTER_VALIDATE_IP)) {
            return $address;
        }
    }

    return null;
}

function getRequestUserAgent(): ?string {
    $userAgent = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    return $userAgent === '' ? null : mb_substr($userAgent, 0, 500, 'UTF-8');
}

function ensureAdminOperationLogsTable(PDO $db): void {
    static $initialized = false;
    if ($initialized) {
        return;
    }
    if (!adminTableExists($db, 'admin_operation_logs')) {
        throw new RuntimeException('Missing database migration 202607240003_admin_operation_audit.sql');
    }
    $initialized = true;
}

function ensureLoginAuditTable(PDO $db): void {
    static $initialized = false;
    if ($initialized) {
        return;
    }
    platformRequireMigrationReadiness($db, ['202607310005']);
    $initialized = true;
}

function adminRecordLoginAudit(PDO $db, array $payload): void {
    ensureLoginAuditTable($db);
    $stmt = $db->prepare("INSERT INTO login_audit_logs
        (user_id, staff_id, login_type, login_status, source, ip_address, user_agent, message, device_id, device_fingerprint, is_new_device, risk_level)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        isset($payload['user_id']) ? (int)$payload['user_id'] : null,
        isset($payload['staff_id']) ? (int)$payload['staff_id'] : null,
        mb_substr((string)($payload['login_type'] ?? 'admin'), 0, 40),
        mb_substr((string)($payload['login_status'] ?? 'success'), 0, 20),
        mb_substr((string)($payload['source'] ?? 'admin'), 0, 60),
        $payload['ip_address'] ?? getClientIpAddress(),
        mb_substr((string)($payload['user_agent'] ?? getRequestUserAgent()), 0, 500),
        mb_substr((string)($payload['message'] ?? ''), 0, 255),
        isset($payload['device_id']) ? mb_substr((string)$payload['device_id'], 0, 120) : null,
        isset($payload['device_fingerprint']) ? mb_substr((string)$payload['device_fingerprint'], 0, 120) : null,
        !empty($payload['is_new_device']) ? 1 : 0,
        mb_substr((string)($payload['risk_level'] ?? 'normal'), 0, 20),
    ]);
}

function adminMaskSensitiveValue($value) {
    if ($value === null) {
        return null;
    }
    if (is_string($value)) {
        $len = mb_strlen($value, 'UTF-8');
        if ($len <= 2) {
            return str_repeat('*', $len);
        }
        return mb_substr($value, 0, 1, 'UTF-8') . str_repeat('*', max(1, $len - 2)) . mb_substr($value, -1, 1, 'UTF-8');
    }
    if (is_numeric($value)) {
        $str = (string)$value;
        $len = strlen($str);
        if ($len <= 2) {
            return str_repeat('*', $len);
        }
        return substr($str, 0, 1) . str_repeat('*', max(1, $len - 2)) . substr($str, -1);
    }
    return '[masked]';
}

function adminSanitizeOperationPayload($value) {
    $sensitiveKeys = [
        'password', 'new_password', 'old_password', 'user_pass', 'token',
        'jwt', 'authorization', 'auth', 'secret', 'openid', 'phone', 'mobile'
    ];

    if (is_array($value)) {
        $sanitized = [];
        foreach ($value as $key => $item) {
            $lowerKey = is_string($key) ? strtolower($key) : '';
            if ($lowerKey !== '' && in_array($lowerKey, $sensitiveKeys, true)) {
                $sanitized[$key] = adminMaskSensitiveValue($item);
                continue;
            }
            $sanitized[$key] = adminSanitizeOperationPayload($item);
        }
        return $sanitized;
    }

    if (is_object($value)) {
        return adminSanitizeOperationPayload((array)$value);
    }

    return $value;
}

function adminRecordOperation(PDO $db, array $operatorUser, array $operatorStaff = null, array $payload = []): void {
    try {
        ensureAdminOperationLogsTable($db);
        $beforePayload = isset($payload['before']) ? adminSanitizeOperationPayload($payload['before']) : null;
        $afterPayload = isset($payload['after']) ? adminSanitizeOperationPayload($payload['after']) : null;
        $stmt = $db->prepare("INSERT INTO admin_operation_logs
            (operator_user_id, operator_staff_id, module, action, target_type, target_id, before_json, after_json, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            isset($operatorUser['user_id']) ? (int)$operatorUser['user_id'] : null,
            isset($operatorStaff['id']) ? (int)$operatorStaff['id'] : null,
            (string)($payload['module'] ?? 'admin'),
            (string)($payload['action'] ?? 'update'),
            (string)($payload['target_type'] ?? 'record'),
            isset($payload['target_id']) ? (string)$payload['target_id'] : null,
            $beforePayload !== null ? json_encode($beforePayload, JSON_UNESCAPED_UNICODE) : null,
            $afterPayload !== null ? json_encode($afterPayload, JSON_UNESCAPED_UNICODE) : null,
            $payload['ip_address'] ?? getClientIpAddress(),
            $payload['user_agent'] ?? getRequestUserAgent(),
        ]);
    } catch (Throwable $error) {
        error_log('[admin.audit] ' . $error->getMessage());
    }
}

function adminPasswordHash(string $password): string {
    return PasswordPolicy::hash($password);
}
