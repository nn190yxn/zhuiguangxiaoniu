<?php
/**
 * API配置文件
 */

function configValue($envKey, $defaultValue) {
    $value = getenv($envKey);
    if ($value !== false && $value !== '') {
        return $value;
    }
    // Fallback: load from .env.local.php for PHP-FPM environments
    static $localEnv = null;
    if ($localEnv === null) {
        $localEnvFile = __DIR__ . '/.env.local.php';
        if (is_file($localEnvFile)) {
            $localEnv = require $localEnvFile;
        } else {
            $localEnv = [];
        }
    }
    return isset($localEnv[$envKey]) ? $localEnv[$envKey] : $defaultValue;
}

// 数据库配置
define('DB_NAME', configValue('DB_NAME', '_122_51_223_46'));
define('DB_USER', configValue('DB_USER', '_122_51_223_46'));
$requiredDbPassword = configValue('DB_PASSWORD', '');
if ($requiredDbPassword === '') {
    error_log('CRITICAL: DB_PASSWORD is not set in env or .env.local.php');
    throw new Exception('数据库配置错误：请设置 DB_PASSWORD 环境变量');
}
define('DB_PASSWORD', $requiredDbPassword);
define('DB_HOST', configValue('DB_HOST', 'localhost'));
define('DB_CHARSET', configValue('DB_CHARSET', 'utf8mb4'));

// CORS配置
define('ALLOWED_ORIGINS', configValue('ALLOWED_ORIGINS', 'http://122.51.223.46,https://supercalf.com'));

// JWT配置
$requiredJwtSecret = configValue('JWT_SECRET', '');
if ($requiredJwtSecret === '') {
    error_log('CRITICAL: JWT_SECRET is not set in env or .env.local.php');
    throw new Exception('安全配置错误：请设置 JWT_SECRET 环境变量');
}
define('JWT_SECRET', $requiredJwtSecret);
define('JWT_EXPIRE', 7 * 24 * 60 * 60); // 7天

// WordPress Cookie名称
define('LOGGED_IN_USER_COOKIE', 'wordpress_logged_in_' . md5('zgnn') . '_cookie');

// API基础路径
define('API_BASE_URL', configValue('API_BASE_URL', 'http://122.51.223.46/api'));

/**
 * 获取数据库连接
 */
function getDB() {
    static $pdo = null;

    if ($pdo === null) {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASSWORD, $options);
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            throw new Exception('数据库连接失败');
        }
    }

    return $pdo;
}

/**
 * 获取当前用户ID（从JWT Token）
 * 安全的实现，不接受URL参数伪造
 */
function getCurrentUserId() {
    static $cachedUserId = null;

    if ($cachedUserId !== null) {
        return $cachedUserId;
    }

    // 优先从Authorization header获取JWT Token
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)/i', $authHeader, $matches)) {
        $token = $matches[1];
        $payload = jwtDecode($token);
        if ($payload && isset($payload['user_id']) && isJwtUserAllowed($payload)) {
            $cachedUserId = (int)$payload['user_id'];
            return $cachedUserId;
        }
    }

    // 其次从session获取
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', 1);
        ini_set('session.use_strict_mode', 1);
        session_start();
    }

    if (isset($_SESSION['wp_user_id'])) {
        $cachedUserId = (int)$_SESSION['wp_user_id'];
        return $cachedUserId;
    }

    // 游客用户返回0
    return 0;
}

/**
 * 获取当前用户信息
 */
function getCurrentUser() {
    $userId = getCurrentUserId();
    if (!$userId) {
        return null;
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT id, user_login as username, display_name as nickname, user_email as email FROM wp_users WHERE ID = ?");
    $stmt->execute([$userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * 获取当前JWT用户信息
 */
function getJwtCurrentUser() {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/Bearer\s+(.+)/i', $authHeader, $matches)) {
        return null;
    }

    $payload = jwtDecode($matches[1]);
    if (!$payload || !isset($payload['user_id'])) {
        return null;
    }

    if (!isJwtUserAllowed($payload)) {
        return null;
    }

    $staff = getStaffByUserId((int)$payload['user_id']);

    return [
        'user_id' => (int)$payload['user_id'],
        'username' => $payload['username'] ?? '',
        'role' => $payload['role'] ?? 'staff',
        'staff_id' => $staff ? (int)$staff['id'] : null
    ];
}

/**
 * 读取JSON或表单请求体。
 */
function getRequestInput() {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $data = json_decode((string)$raw, true);
        return is_array($data) ? $data : [];
    }
    return $_POST;
}

/**
 * 根据系统用户查找员工资料。
 */
function getStaffByUserId($userId) {
    if (!$userId) {
        return null;
    }
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM staffs WHERE user_id = ? LIMIT 1");
        $stmt->execute([(int)$userId]);
        $staff = $stmt->fetch(PDO::FETCH_ASSOC);
        return $staff ?: null;
    } catch (Throwable $e) {
        error_log('getStaffByUserId failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * 将员工角色统一映射为系统内可用角色。
 */
function normalizeStaffRoleCode($role) {
    $role = strtolower(trim((string)$role));
    $map = [
        'sales' => 'sales',
        'consultant' => 'sales',
        'coach' => 'coach',
        'manager' => 'manager',
        'newbie' => 'sales',
        'staff' => 'staff',
        'admin' => 'admin',
    ];
    return $map[$role] ?? $role;
}

/**
 * 训练模块角色需要兼容 consultant 历史数据。
 */
function getTrainingModuleRoleCode($role) {
    $role = normalizeStaffRoleCode($role);
    if ($role === 'sales') {
        return 'consultant';
    }
    return $role;
}

/**
 * 通关模块里销售和教练互通访问。
 */
function isPassStageRoleAllowed($stageRole, $userRole) {
    $stageRole = normalizeStaffRoleCode($stageRole);
    $userRole = normalizeStaffRoleCode($userRole);

    if ($stageRole === 'common') {
        return true;
    }

    if ($stageRole === $userRole) {
        return true;
    }

    $passSharedRoles = ['sales', 'coach'];
    return in_array($stageRole, $passSharedRoles, true) && in_array($userRole, $passSharedRoles, true);
}

/**
 * 优先使用员工档案中的岗位角色，避免 JWT 里只有 staff 时拿不到正确培训内容。
 */
function getEffectiveStaffRole($user = null) {
    if (!$user) {
        $user = getJwtCurrentUser();
    }
    if (!$user) {
        return 'sales';
    }

    $staff = null;
    if (!empty($user['staff_id'])) {
        $staff = getStaffByUserId((int)($user['user_id'] ?? 0));
    }

    if ($staff && !empty($staff['role'])) {
        return normalizeStaffRoleCode($staff['role']);
    }

    return normalizeStaffRoleCode($user['role'] ?? 'sales');
}

/**
 * JWT访问安全校验：员工必须仍为启用状态；管理员可无员工档案。
 */
function isJwtUserAllowed($payload) {
    $userId = (int)($payload['user_id'] ?? 0);
    $role = $payload['role'] ?? 'staff';
    if (!$userId) {
        return false;
    }
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT user_status FROM wp_users WHERE ID = ? LIMIT 1");
        $stmt->execute([$userId]);
        $wpStatus = $stmt->fetchColumn();
        if ($wpStatus === false || (int)$wpStatus !== 0) {
            return false;
        }

        $staff = getStaffByUserId($userId);
        if ($staff) {
            return (int)($staff['status'] ?? 0) === 1;
        }

        return $role === 'admin';
    } catch (Throwable $e) {
        error_log('JWT user status check failed: ' . $e->getMessage());
        return false;
    }
}

function isJwtManager($user) {
    if (!$user || empty($user['user_id'])) {
        return false;
    }
    return in_array($user['role'] ?? 'staff', ['admin', 'manager'], true);
}

function canAccessSurvey($user, array $survey) {
    if (!$user) {
        return false;
    }
    if (($user['role'] ?? '') === 'admin') {
        return true;
    }
    return (int)($user['staff_id'] ?? 0) > 0 && (int)($user['staff_id'] ?? 0) === (int)($survey['creator_id'] ?? 0);
}

function buildSurveyMiniProgramLink($shareCode) {
    return '/pages/survey/fill/fill?code=' . rawurlencode((string)$shareCode);
}

/**
 * JWT解码
 */
function jwtDecode($token) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }

    [$headerPart, $payloadPart, $signaturePart] = $parts;
    $header = base64UrlDecode($headerPart);
    $payload = base64UrlDecode($payloadPart);
    $signature = base64UrlDecode($signaturePart);

    if ($header === false || $payload === false || $signature === false) {
        return null;
    }

    $headerData = json_decode($header, true);
    if (!is_array($headerData) || ($headerData['alg'] ?? '') !== 'HS256') {
        return null;
    }

    $expectedSignature = hash_hmac('sha256', "$headerPart.$payloadPart", JWT_SECRET, true);
    if (!hash_equals($expectedSignature, $signature)) {
        return null;
    }

    if (!$payload) {
        return null;
    }

    $data = json_decode($payload, true);
    if (!$data) {
        return null;
    }

    // 检查过期
    if (isset($data['exp']) && $data['exp'] < time()) {
        return null;
    }

    return $data;
}

function base64UrlDecode($value) {
    $padding = strlen($value) % 4;
    if ($padding > 0) {
        $value .= str_repeat('=', 4 - $padding);
    }
    return base64_decode(strtr($value, '-_', '+/'), true);
}

/**
 * JWT编码
 */
function generate_jwt($userId, $username, $role = 'staff') {
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $payload = [
        'user_id' => (int)$userId,
        'username' => $username,
        'role' => $role,
        'iat' => time(),
        'exp' => time() + JWT_EXPIRE
    ];

    $headerEncoded = rtrim(strtr(base64_encode(json_encode($header)), '+/', '-_'), '=');
    $payloadEncoded = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
    $signature = hash_hmac('sha256', "$headerEncoded.$payloadEncoded", JWT_SECRET, true);
    $signatureEncoded = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

    return "$headerEncoded.$payloadEncoded.$signatureEncoded";
}

/**
 * 返回JSON响应（统一格式）
 */
function jsonResponse($code = 0, $message = '', $data = []) {
    $httpCode = 200;
    if ($code === 401) {
        $httpCode = 401;
    } elseif ($code === 403) {
        $httpCode = 403;
    } elseif ($code === 404) {
        $httpCode = 404;
    } elseif ($code !== 0) {
        $httpCode = 400;
    }
    http_response_code($httpCode);
    echo json_encode([
        'code' => $code,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 返回成功响应
 */
function jsonSuccess($data = [], $message = 'success') {
    jsonResponse(0, $message, $data);
}

/**
 * 返回错误响应
 */
function jsonError($code, $message = 'error') {
    jsonResponse($code, $message, null);
}

/**
 * JSON响应兼容函数（支持旧调用方式）
 * 用法: json_response($code, $message, $data)
 */
function json_response($code = 0, $message = '', $data = []) {
    // 兼容旧格式: json_response(0, 'success', $data)
    if ($code === 0 && $message === 'success') {
        jsonResponse(0, 'success', is_array($data) ? $data : []);
    }
    // 兼容旧格式: json_response(401, '错误信息')
    elseif (is_string($message) && ($data === null || $data === '')) {
        jsonResponse($code, $message, null);
    }
    // 兼容旧格式: json_response($code, $message, $data)
    else {
        jsonResponse($code, $message, $data);
    }
}

/**
 * 获取AI设置
 */
function ai_load_settings() {
    try {
        $db = getDB();
        $db->exec("CREATE TABLE IF NOT EXISTS ai_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT NULL,
            description VARCHAR(255) NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $stmt = $db->query("SELECT setting_key, setting_value FROM ai_settings");
        $settings = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['setting_value'] !== null && $row['setting_value'] !== '') {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        }
        if ($settings) {
            return $settings;
        }
    } catch (Throwable $e) {
        error_log('AI settings database load failed: ' . $e->getMessage());
    }

    $configPath = __DIR__ . '/ai-config.php';
    if (is_file($configPath)) {
        return require $configPath;
    }
    return [];
}

/**
 * 获取资源完整URL
 */
function getResourceUrl($path) {
    if (empty($path)) {
        return '';
    }
    if (strpos($path, 'http') === 0) {
        return $path;
    }
    return API_BASE_URL . '/../' . ltrim($path, '/');
}

/**
 * 获取当前请求的CORS来源
 */
function getRequestOrigin() {
    if (isset($_SERVER['HTTP_ORIGIN'])) {
        return $_SERVER['HTTP_ORIGIN'];
    }
    if (isset($_SERVER['HTTP_REFERER'])) {
        $parsed = parse_url($_SERVER['HTTP_REFERER']);
        return ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');
    }
    return '';
}

/**
 * 设置CORS头
 */
function setCORSHeaders() {
    $origin = getRequestOrigin();
    $allowed = explode(',', ALLOWED_ORIGINS);

    if (in_array($origin, $allowed)) {
        header("Access-Control-Allow-Origin: $origin");
    } else {
        header('Access-Control-Allow-Origin: ');
    }

    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Max-Age: 86400');
}

/**
 * 处理CORS预检请求
 */
function handleCORS() {
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        setCORSHeaders();
        http_response_code(204);
        exit;
    }
    setCORSHeaders();
}
