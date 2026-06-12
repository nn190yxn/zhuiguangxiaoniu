<?php
/**
 * JWT认证API
 * POST /api/auth-jwt.php - 登录获取Token
 * GET /api/auth-jwt.php?action=verify - 验证Token
 * GET /api/auth-jwt.php?action=refresh - 刷新Token
 * POST /api/auth-jwt.php?action=wxlogin - 微信登录
 * POST /api/auth-jwt.php?action=wxbind - 绑定微信OpenID
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
handleCORS();
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

$requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($requestMethod === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$db = getDB();

$action = $_GET['action'] ?? 'login';
$input = getRequestInput();

switch ($action) {
    case 'login':
        // 登录
        $username = isset($input['username']) ? trim($input['username']) : '';
        $password = isset($input['password']) ? $input['password'] : '';
        $device_id = isset($input['device_id']) ? trim($input['device_id']) : '';
        $device_fingerprint = normalizeDeviceFingerprint($device_id, isset($input['device_fingerprint']) ? trim($input['device_fingerprint']) : '');

        if ($username === '' || $password === '') {
            json_response(400, '用户名和密码不能为空');
        }

        if ($device_id === '' || $device_fingerprint === '') {
            recordLoginAudit($db, null, null, 'password', 'failure', 'jwt_login', 'missing_device', [
                'device_id' => $device_id,
                'device_fingerprint' => $device_fingerprint,
                'risk_level' => 'high',
            ]);
            json_response(400, '无法识别设备，请重新打开小程序后再试');
        }

        // 从WordPress验证用户
        $sql = "SELECT ID, user_login, user_pass FROM wp_users WHERE user_login = ? OR user_email = ? LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            recordLoginAudit($db, null, null, 'password', 'failure', 'jwt_login', 'invalid_credentials');
            json_response(401, '用户名或密码错误');
        }

        // 验证WordPress密码
        if (!wp_verify_password($password, $user['user_pass'])) {
            $staffForAudit = getStaffForLogin($db, (int)$user['ID']);
            recordLoginAudit($db, (int)$user['ID'], $staffForAudit ? (int)$staffForAudit['id'] : null, 'password', 'failure', 'jwt_login', 'invalid_credentials');
            json_response(401, '用户名或密码错误');
        }

        $role = getUserRole($db, (int)$user['ID']);
        $staff = getStaffForLogin($db, (int)$user['ID']);
        if ($role !== 'admin' && (!$staff || (int)$staff['status'] !== 1)) {
            recordLoginAudit($db, (int)$user['ID'], $staff ? (int)$staff['id'] : null, 'password', 'failure', 'jwt_login', 'disabled_staff');
            json_response(403, '账号未开通或已停用，请联系管理员');
        }

        if ($role !== 'admin' && empty($staff['openid'])) {
            recordLoginAudit($db, (int)$user['ID'], $staff ? (int)$staff['id'] : null, 'password', 'failure', 'jwt_login', 'wechat_unbound', [
                'device_id' => $device_id,
                'device_fingerprint' => $device_fingerprint,
                'risk_level' => 'medium',
            ]);
            json_response(409, '员工账号必须先绑定本人微信', [
                'need_bind' => true,
                'staff_id' => $staff ? (int)$staff['id'] : null,
                'username' => $username,
            ]);
        }

        $deviceResult = $staff ? updateDeviceLogin($db, (int)$staff['id'], (string)($staff['openid'] ?? ''), $device_id, $device_fingerprint) : ['is_new_device' => false];

        // 生成JWT
        $token = generate_jwt($user['ID'], $user['user_login'], $role);
        recordLoginAudit($db, (int)$user['ID'], $staff ? (int)$staff['id'] : null, 'password', 'success', 'jwt_login', $deviceResult['is_new_device'] ? 'new_device' : 'success', [
            'device_id' => $device_id,
            'device_fingerprint' => $device_fingerprint,
            'is_new_device' => !empty($deviceResult['is_new_device']),
            'risk_level' => !empty($deviceResult['is_new_device']) ? 'medium' : 'normal',
        ]);

        json_response(0, 'success', [
            'token' => $token,
            'user' => [
                'id' => $user['ID'],
                'username' => $user['user_login'],
                'role' => $role,
                'staff_id' => $staff ? (int)$staff['id'] : null
            ],
            'expire' => JWT_EXPIRE
        ]);
        break;

    case 'wxlogin':
        // 微信一键登录
        $code = isset($input['code']) ? trim($input['code']) : '';
        $device_id = isset($input['device_id']) ? trim($input['device_id']) : '';
        $device_fingerprint = isset($input['device_fingerprint']) ? trim($input['device_fingerprint']) : '';

        if (empty($code)) {
            json_response(400, '微信授权码不能为空');
        }

        $device_fingerprint = normalizeDeviceFingerprint($device_id, $device_fingerprint);
        if ($device_id === '' || $device_fingerprint === '') {
            recordLoginAudit($db, null, null, 'wechat', 'failure', 'wxlogin', 'missing_device', [
                'device_id' => $device_id,
                'device_fingerprint' => $device_fingerprint,
                'risk_level' => 'high',
            ]);
            json_response(400, '无法识别设备，请重新打开小程序后再试');
        }

        // 通过code换取openid（需调用微信接口）
        $openid = getWeChatOpenId($code);
        if (!$openid) {
            json_response(401, '微信授权失败，无法获取用户信息');
        }

        // 查询openid是否已绑定员工
        $sql = "SELECT s.id, s.user_id, s.employee_no, s.name, s.role, s.status, s.openid, u.ID, u.user_login
                FROM staffs s
                LEFT JOIN wp_users u ON s.user_id = u.ID
                WHERE s.openid = ? LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([$openid]);
        $staff = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$staff) {
            recordLoginAudit($db, null, null, 'wechat', 'failure', 'wxlogin', 'unbound_openid', [
                'device_id' => $device_id,
                'device_fingerprint' => $device_fingerprint,
                'risk_level' => 'medium',
            ]);
            json_response(401, '该微信未绑定员工账号，请联系管理员绑定', ['need_bind' => true]);
        }

        if ((int)$staff['status'] !== 1) {
            recordLoginAudit($db, (int)($staff['user_id'] ?? 0), (int)$staff['id'], 'wechat', 'failure', 'wxlogin', 'disabled_staff');
            json_response(403, '员工账号已停用，请联系管理员');
        }

        if (!$staff['user_id'] || !$staff['ID']) {
            recordLoginAudit($db, (int)($staff['user_id'] ?? 0), (int)$staff['id'], 'wechat', 'failure', 'wxlogin', 'missing_user');
            json_response(401, '未找到关联的系统账号');
        }

        $role = getUserRole($db, (int)$staff['user_id']);

        // 更新设备登录记录
        $deviceResult = updateDeviceLogin($db, $staff['id'], $openid, $device_id, $device_fingerprint);

        // 生成JWT
        $token = generate_jwt($staff['user_id'], $staff['user_login'], $role);
        recordLoginAudit($db, (int)$staff['user_id'], (int)$staff['id'], 'wechat', 'success', 'wxlogin', $deviceResult['is_new_device'] ? 'new_device' : 'success', [
            'device_id' => $device_id,
            'device_fingerprint' => $device_fingerprint,
            'is_new_device' => !empty($deviceResult['is_new_device']),
            'risk_level' => !empty($deviceResult['is_new_device']) ? 'medium' : 'normal',
        ]);

        json_response(0, 'success', [
            'token' => $token,
            'user' => [
                'id' => (int)$staff['user_id'],
                'username' => $staff['user_login'],
                'role' => $role,
                'staff_id' => $staff['id']
            ],
            'expire' => JWT_EXPIRE
        ]);
        break;

    case 'wxbind':
        // 绑定微信OpenID到员工账号
        $code = isset($input['code']) ? trim($input['code']) : '';
        $employee_no = isset($input['employee_no']) ? trim($input['employee_no']) : '';
        $username = isset($input['username']) ? trim($input['username']) : $employee_no;
        $password = isset($input['password']) ? $input['password'] : '';
        $device_id = isset($input['device_id']) ? trim($input['device_id']) : '';
        $device_fingerprint = normalizeDeviceFingerprint($device_id, isset($input['device_fingerprint']) ? trim($input['device_fingerprint']) : '');

        if (empty($code) || empty($username) || empty($password)) {
            json_response(400, '参数不完整');
        }

        if ($device_id === '' || $device_fingerprint === '') {
            json_response(400, '无法识别设备，请重新打开小程序后再试');
        }

        // 通过code换取openid
        $openid = getWeChatOpenId($code);
        if (!$openid) {
            json_response(401, '微信授权失败');
        }

        // 验证员工工号和密码
        $sql = "SELECT s.id, s.status, s.openid, u.ID as user_id, u.user_login, u.user_pass
                FROM staffs s
                LEFT JOIN wp_users u ON s.user_id = u.ID
                WHERE s.employee_no = ? OR s.phone = ? OR u.user_login = ? OR u.user_email = ? LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([$username, $username, $username, $username]);
        $staff = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$staff) {
            json_response(401, '用户名或密码错误');
        }

        // 验证密码
        if ((int)$staff['status'] !== 1) {
            json_response(403, '员工账号已停用，请联系管理员');
        }

        if (!$staff['user_id'] || !wp_verify_password($password, $staff['user_pass'])) {
            json_response(401, '用户名或密码错误');
        }

        // 检查openid是否已被其他员工绑定
        $other_sql = "SELECT id FROM staffs WHERE openid = ? AND id <> ? LIMIT 1";
        $stmt = $db->prepare($other_sql);
        $stmt->execute([$openid, $staff['id']]);
        $other_result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($other_result) {
            json_response(403, '该微信已被其他账号绑定');
        }

        if ($staff['openid'] && $staff['openid'] !== $openid) {
            json_response(403, '该微信已被其他账号绑定');
        }

        // 绑定openid
        $bind_sql = "UPDATE staffs SET openid = ?, openid_bound_at = NOW() WHERE id = ?";
        $stmt = $db->prepare($bind_sql);
        $stmt->execute([$openid, $staff['id']]);

        $role = getUserRole($db, (int)$staff['user_id']);
        $deviceResult = updateDeviceLogin($db, (int)$staff['id'], $openid, $device_id, $device_fingerprint);
        recordLoginAudit($db, (int)$staff['user_id'], (int)$staff['id'], 'wechat_bind', 'success', 'wxbind', $deviceResult['is_new_device'] ? 'bind_new_device' : 'bind_success', [
            'device_id' => $device_id,
            'device_fingerprint' => $device_fingerprint,
            'is_new_device' => !empty($deviceResult['is_new_device']),
            'risk_level' => !empty($deviceResult['is_new_device']) ? 'medium' : 'normal',
        ]);

        $token = generate_jwt($staff['user_id'], $staff['user_login'], $role);

        json_response(0, '绑定成功', [
            'openid' => substr($openid, 0, 20) . '...',
            'token' => $token,
            'user' => [
                'id' => (int)$staff['user_id'],
                'username' => $staff['user_login'],
                'role' => $role,
                'staff_id' => (int)$staff['id'],
            ],
            'expire' => JWT_EXPIRE,
        ]);
        break;

    case 'verify':
        // 验证Token
        $user = getJwtCurrentUser();
        if (!$user) {
            json_response(401, 'Token无效或已过期');
        }

        json_response(0, 'success', [
            'user' => [
                'id' => $user['user_id'],
                'username' => $user['username'],
                'role' => $user['role']
            ]
        ]);
        break;

    case 'refresh':
        // 刷新Token
        $user = getJwtCurrentUser();
        if (!$user) {
            json_response(401, 'Token无效或已过期');
        }

        $new_token = generate_jwt($user['user_id'], $user['username'], $user['role']);

        json_response(0, 'success', [
            'token' => $new_token,
            'expire' => JWT_EXPIRE
        ]);
        break;

    default:
        json_response(400, '未知操作');
}

/**
 * 通过微信授权码获取OpenID
 */
function getWeChatOpenId($code) {
    $appid = defined('WECHAT_APPID') ? WECHAT_APPID : getenv('WECHAT_APPID');
    $secret = defined('WECHAT_APP_SECRET') ? WECHAT_APP_SECRET : getenv('WECHAT_APP_SECRET');

    if (empty($appid) || empty($secret)) {
        error_log('WeChat AppID or Secret not configured');
        return null;
    }

    $url = "https://api.weixin.qq.com/sns/jscode2session?appid={$appid}&secret={$secret}&js_code={$code}&grant_type=authorization_code";

    $context = stream_context_create([
        'http' => [
            'timeout' => 5,
            'ignore_errors' => true
        ]
    ]);

    $response = @file_get_contents($url, false, $context);
    if (!$response) {
        error_log('WeChat API request failed');
        return null;
    }

    $data = json_decode($response, true);
    if (isset($data['errcode']) && $data['errcode'] !== 0) {
        error_log('WeChat API error: ' . $data['errmsg']);
        return null;
    }

    return $data['openid'] ?? null;
}

/**
 * 更新设备登录记录
 */
function updateDeviceLogin($db, $staff_id, $openid, $device_id, $device_fingerprint) {
    ensureDeviceLoginsTable($db);
    $now = date('Y-m-d H:i:s');
    $device_fingerprint = normalizeDeviceFingerprint((string)$device_id, (string)$device_fingerprint);
    if ($device_id === '' || $device_fingerprint === '') {
        throw new InvalidArgumentException('missing_device');
    }

    // 检查是否已有记录
    $sql = "SELECT id, login_count FROM device_logins WHERE staff_id = ? AND device_fingerprint = ? LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->execute([$staff_id, $device_fingerprint]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $login_count = $row['login_count'] + 1;
        $update_sql = "UPDATE device_logins SET
            login_count = ?,
            last_login = ?,
            is_active = 1,
            openid = COALESCE(?, openid)
            WHERE id = ?";
        $stmt = $db->prepare($update_sql);
        $stmt->execute([$login_count, $now, $openid, $row['id']]);
        return ['id' => (int)$row['id'], 'is_new_device' => false, 'login_count' => $login_count];
    } else {
        $insert_sql = "INSERT INTO device_logins
            (staff_id, openid, device_id, device_fingerprint, login_count, first_login, last_login)
            VALUES (?, ?, ?, ?, 1, ?, ?)";
        $stmt = $db->prepare($insert_sql);
        $stmt->execute([$staff_id, $openid, $device_id, $device_fingerprint, $now, $now]);
        return ['id' => (int)$db->lastInsertId(), 'is_new_device' => true, 'login_count' => 1];
    }
}

function recordLoginAudit(PDO $db, ?int $userId, ?int $staffId, string $loginType, string $loginStatus, string $source, string $message, array $extra = []): void {
    try {
        ensureLoginAuditTableForAuth($db);
        $stmt = $db->prepare("INSERT INTO login_audit_logs (user_id, staff_id, login_type, login_status, source, ip_address, user_agent, message, device_id, device_fingerprint, is_new_device, risk_level) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $userId && $userId > 0 ? $userId : null,
            $staffId && $staffId > 0 ? $staffId : null,
            mb_substr($loginType, 0, 40),
            mb_substr($loginStatus, 0, 20),
            mb_substr($source, 0, 60),
            $_SERVER['REMOTE_ADDR'] ?? null,
            mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            mb_substr($message, 0, 255),
            isset($extra['device_id']) ? mb_substr((string)$extra['device_id'], 0, 120) : null,
            isset($extra['device_fingerprint']) ? mb_substr((string)$extra['device_fingerprint'], 0, 120) : null,
            !empty($extra['is_new_device']) ? 1 : 0,
            isset($extra['risk_level']) ? mb_substr((string)$extra['risk_level'], 0, 20) : 'normal',
        ]);
    } catch (Throwable $e) {
        error_log('[auth.login_audit] ' . $e->getMessage());
    }
}

function ensureLoginAuditTableForAuth(PDO $db): void {
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
            device_id VARCHAR(120) DEFAULT NULL,
            device_fingerprint VARCHAR(120) DEFAULT NULL,
            is_new_device TINYINT(1) NOT NULL DEFAULT 0,
            risk_level VARCHAR(20) NOT NULL DEFAULT 'normal',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_created (created_at),
            KEY idx_staff_created (staff_id, created_at),
            KEY idx_status_created (login_status, created_at),
            KEY idx_source_created (source, created_at),
            KEY idx_device_created (device_fingerprint, created_at),
            KEY idx_risk_created (risk_level, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    foreach ([
        'device_id' => "ALTER TABLE login_audit_logs ADD COLUMN device_id VARCHAR(120) DEFAULT NULL AFTER message",
        'device_fingerprint' => "ALTER TABLE login_audit_logs ADD COLUMN device_fingerprint VARCHAR(120) DEFAULT NULL AFTER device_id",
        'is_new_device' => "ALTER TABLE login_audit_logs ADD COLUMN is_new_device TINYINT(1) NOT NULL DEFAULT 0 AFTER device_fingerprint",
        'risk_level' => "ALTER TABLE login_audit_logs ADD COLUMN risk_level VARCHAR(20) NOT NULL DEFAULT 'normal' AFTER is_new_device",
    ] as $column => $sql) {
        if (!tableColumnExists($db, 'login_audit_logs', $column)) {
            $db->exec($sql);
        }
    }
}

function ensureDeviceLoginsTable(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS device_logins (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        staff_id BIGINT UNSIGNED NOT NULL,
        openid VARCHAR(128) DEFAULT NULL,
        device_id VARCHAR(120) DEFAULT NULL,
        device_fingerprint VARCHAR(120) NOT NULL,
        device_name VARCHAR(120) DEFAULT NULL,
        device_model VARCHAR(120) DEFAULT NULL,
        os_version VARCHAR(120) DEFAULT NULL,
        app_version VARCHAR(60) DEFAULT NULL,
        screen_width INT DEFAULT 0,
        screen_height INT DEFAULT 0,
        login_count INT NOT NULL DEFAULT 0,
        is_trusted TINYINT(1) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        first_login DATETIME DEFAULT NULL,
        last_login DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_staff_device (staff_id, device_fingerprint),
        KEY idx_last_login (last_login)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    foreach ([
        'openid' => "ALTER TABLE device_logins ADD COLUMN openid VARCHAR(128) DEFAULT NULL AFTER staff_id",
        'device_id' => "ALTER TABLE device_logins ADD COLUMN device_id VARCHAR(120) DEFAULT NULL AFTER openid",
        'device_fingerprint' => "ALTER TABLE device_logins ADD COLUMN device_fingerprint VARCHAR(120) NOT NULL DEFAULT '' AFTER device_id",
        'device_name' => "ALTER TABLE device_logins ADD COLUMN device_name VARCHAR(120) DEFAULT NULL AFTER device_fingerprint",
        'device_model' => "ALTER TABLE device_logins ADD COLUMN device_model VARCHAR(120) DEFAULT NULL AFTER device_name",
        'os_version' => "ALTER TABLE device_logins ADD COLUMN os_version VARCHAR(120) DEFAULT NULL AFTER device_model",
        'app_version' => "ALTER TABLE device_logins ADD COLUMN app_version VARCHAR(60) DEFAULT NULL AFTER os_version",
        'screen_width' => "ALTER TABLE device_logins ADD COLUMN screen_width INT DEFAULT 0 AFTER app_version",
        'screen_height' => "ALTER TABLE device_logins ADD COLUMN screen_height INT DEFAULT 0 AFTER screen_width",
        'login_count' => "ALTER TABLE device_logins ADD COLUMN login_count INT NOT NULL DEFAULT 0 AFTER screen_height",
        'is_trusted' => "ALTER TABLE device_logins ADD COLUMN is_trusted TINYINT(1) NOT NULL DEFAULT 0 AFTER login_count",
        'is_active' => "ALTER TABLE device_logins ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER is_trusted",
        'first_login' => "ALTER TABLE device_logins ADD COLUMN first_login DATETIME DEFAULT NULL AFTER is_active",
        'last_login' => "ALTER TABLE device_logins ADD COLUMN last_login DATETIME DEFAULT NULL AFTER first_login",
    ] as $column => $sql) {
        if (!tableColumnExists($db, 'device_logins', $column)) {
            $db->exec($sql);
        }
    }
}

function normalizeDeviceFingerprint(string $deviceId, string $deviceFingerprint): string {
    $deviceFingerprint = trim($deviceFingerprint);
    if ($deviceFingerprint !== '') {
        return mb_substr($deviceFingerprint, 0, 120);
    }
    $deviceId = trim($deviceId);
    return $deviceId !== '' ? mb_substr($deviceId, 0, 120) : '';
}

function tableColumnExists(PDO $db, string $table, string $column): bool {
    $stmt = $db->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '` LIKE ' . $db->quote($column));
    return (bool)($stmt ? $stmt->fetchColumn() : false);
}

function getStaffForLogin($db, $userId) {
    $sql = "SELECT * FROM staffs WHERE user_id = ? LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->execute([$userId]);
    $staff = $stmt->fetch(PDO::FETCH_ASSOC);
    return $staff ?: null;
}

function getUserRole($db, $userId) {
    $role_sql = "SELECT meta_value FROM wp_usermeta WHERE user_id = ? AND meta_key = 'wp_capabilities' LIMIT 1";
    $stmt = $db->prepare($role_sql);
    $stmt->execute([$userId]);
    $role_meta = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($role_meta) {
        $caps = maybe_unserialize($role_meta['meta_value']);
        if (isset($caps['administrator'])) {
            return 'admin';
        }
        if (isset($caps['editor'])) {
            return 'manager';
        }
    }
    return 'staff';
}

/**
 * WordPress密码验证
 */
function wp_verify_password($password, $hash) {
    if (str_starts_with((string)$hash, '$wp')) {
        $password_to_verify = base64_encode(hash_hmac('sha384', $password, 'wp-sha384', true));
        return password_verify($password_to_verify, substr($hash, 3));
    }

    if (str_starts_with((string)$hash, '$2y$') || str_starts_with((string)$hash, '$2a$') || str_starts_with((string)$hash, '$argon2')) {
        return password_verify($password, $hash);
    }

    $phpass = dirname(__FILE__) . '/../class-phpass.php';
    if (is_file($phpass)) {
        require_once $phpass;
        $wp_hasher = new PasswordHash(8, true);
        return $wp_hasher->CheckPassword($password, $hash);
    }

    return false;
}

/**
 * 简化版WordPress密码验证（兼容PHP 7.4+）
 */
function maybe_unserialize($data) {
    if (is_serialized($data)) {
        return unserialize($data);
    }
    return $data;
}

function is_serialized($data) {
    if (!is_string($data)) {
        return false;
    }
    $data = trim($data);
    if ('N;' === $data) {
        return true;
    }
    if (strlen($data) < 4) {
        return false;
    }
    if (':' !== $data[1]) {
        return false;
    }
    $last = substr($data, -1);
    if (';' !== $last && '}' !== $last) {
        return false;
    }
    return true;
}
