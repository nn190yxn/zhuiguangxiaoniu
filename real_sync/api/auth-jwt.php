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

        if ($username === '' || $password === '') {
            json_response(400, '用户名和密码不能为空');
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

        // 生成JWT
        $token = generate_jwt($user['ID'], $user['user_login'], $role);
        recordLoginAudit($db, (int)$user['ID'], $staff ? (int)$staff['id'] : null, 'password', 'success', 'jwt_login', 'success');

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
            recordLoginAudit($db, null, null, 'wechat', 'failure', 'wxlogin', 'unbound_openid');
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
        updateDeviceLogin($db, $staff['id'], $openid, $device_id, $device_fingerprint);

        // 生成JWT
        $token = generate_jwt($staff['user_id'], $staff['user_login'], $role);
        recordLoginAudit($db, (int)$staff['user_id'], (int)$staff['id'], 'wechat', 'success', 'wxlogin', 'success');

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
        $password = isset($input['password']) ? $input['password'] : '';

        if (empty($code) || empty($employee_no) || empty($password)) {
            json_response(400, '参数不完整');
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
                WHERE s.employee_no = ? LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([$employee_no]);
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

        json_response(0, '绑定成功', ['openid' => substr($openid, 0, 20) . '...']);
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
    $now = date('Y-m-d H:i:s');

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
    } else {
        $insert_sql = "INSERT INTO device_logins
            (staff_id, openid, device_id, device_fingerprint, login_count, first_login, last_login)
            VALUES (?, ?, ?, ?, 1, ?, ?)";
        $stmt = $db->prepare($insert_sql);
        $stmt->execute([$staff_id, $openid, $device_id, $device_fingerprint, $now, $now]);
    }
}

function recordLoginAudit(PDO $db, ?int $userId, ?int $staffId, string $loginType, string $loginStatus, string $source, string $message): void {
    try {
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
        $stmt = $db->prepare("INSERT INTO login_audit_logs (user_id, staff_id, login_type, login_status, source, ip_address, user_agent, message) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $userId && $userId > 0 ? $userId : null,
            $staffId && $staffId > 0 ? $staffId : null,
            mb_substr($loginType, 0, 40),
            mb_substr($loginStatus, 0, 20),
            mb_substr($source, 0, 60),
            $_SERVER['REMOTE_ADDR'] ?? null,
            mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            mb_substr($message, 0, 255),
        ]);
    } catch (Throwable $e) {
        error_log('[auth.login_audit] ' . $e->getMessage());
    }
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
