<?php
require_once dirname(__DIR__) . '/config.php';

function adminRoleTokens(array $user = null, array $staff = null): array {
    $rawRole = strtolower(trim((string)($user['role'] ?? '')));
    $staffRole = strtolower(trim((string)($staff['role'] ?? '')));
    return array_values(array_unique(array_filter([
        $rawRole,
        normalizeStaffRoleCode($rawRole),
        $staffRole,
        normalizeStaffRoleCode($staffRole),
    ])));
}

function adminIsWhitelistedHeadquarter(array $staff = null): bool {
    $staffName = trim((string)($staff['name'] ?? ''));
    $staffPhone = trim((string)($staff['phone'] ?? ''));
    if (in_array($staffName, ['何梓辛', '周颖', '陈琪琪', '姚修宁'], true)) {
        return true;
    }
    return in_array($staffPhone, ['18285031172', '18685147960', '13885135551', '13668501068'], true);
}

function adminCanAccessHeadquarter(array $user = null, array $staff = null): bool {
    if (!$user) {
        return false;
    }
    if (adminIsWhitelistedHeadquarter($staff)) {
        return true;
    }
    $tokens = adminRoleTokens($user, $staff);
    foreach (['admin', 'ops', 'operation', 'operations', 'operator', 'finance', 'ceo'] as $allowed) {
        if (in_array($allowed, $tokens, true)) {
            return true;
        }
    }
    return false;
}

function adminCanAccessPerformance(array $user = null, array $staff = null): bool {
    return adminCanAccessHeadquarter($user, $staff) || in_array('manager', adminRoleTokens($user, $staff), true);
}

function adminCanAccessWorkload(array $user = null, array $staff = null): bool {
    $tokens = adminRoleTokens($user, $staff);
    return adminCanAccessHeadquarter($user, $staff) || in_array('manager', $tokens, true);
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

function ensureAdminOperationLogsTable(PDO $db): void {
    static $initialized = false;
    if ($initialized) {
        return;
    }
    $db->exec("CREATE TABLE IF NOT EXISTS admin_operation_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        operator_user_id INT UNSIGNED DEFAULT NULL,
        operator_staff_id INT UNSIGNED DEFAULT NULL,
        module VARCHAR(60) NOT NULL,
        action VARCHAR(60) NOT NULL,
        target_type VARCHAR(60) NOT NULL,
        target_id VARCHAR(120) DEFAULT NULL,
        before_json LONGTEXT DEFAULT NULL,
        after_json LONGTEXT DEFAULT NULL,
        ip_address VARCHAR(45) DEFAULT NULL,
        user_agent VARCHAR(500) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_module_created (module, created_at),
        KEY idx_operator_created (operator_user_id, created_at),
        KEY idx_target_lookup (target_type, target_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $initialized = true;
}

function ensureLoginAuditTable(PDO $db): void {
    static $initialized = false;
    if ($initialized) {
        return;
    }
    $db->exec("CREATE TABLE IF NOT EXISTS login_audit_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED DEFAULT NULL,
        staff_id INT UNSIGNED DEFAULT NULL,
        login_type VARCHAR(40) NOT NULL DEFAULT 'password',
        login_status VARCHAR(20) NOT NULL DEFAULT 'success',
        source VARCHAR(60) DEFAULT NULL,
        ip_address VARCHAR(45) DEFAULT NULL,
        user_agent VARCHAR(500) DEFAULT NULL,
        message VARCHAR(255) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_created (created_at),
        KEY idx_staff_created (staff_id, created_at),
        KEY idx_status_created (login_status, created_at),
        KEY idx_source_created (source, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $initialized = true;
}

function adminMaskSensitiveValue($value) {
    if ($value === null) {
        return null;
    }
    if (is_string($value)) {
        $len = strlen($value);
        if ($len <= 2) {
            return str_repeat('*', $len);
        }
        return substr($value, 0, 1) . str_repeat('*', max(1, $len - 2)) . substr($value, -1);
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
}

function adminPasswordHash(string $password): string {
    $passwordToHash = base64_encode(hash_hmac('sha384', $password, 'wp-sha384', true));
    return '$wp' . password_hash($passwordToHash, PASSWORD_BCRYPT);
}
