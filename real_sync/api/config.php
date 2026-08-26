<?php
/**
 * API配置文件
 */

require_once __DIR__ . '/drill/TrainingAccessPolicy.php';

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

function failMissingConfiguration($key) {
    error_log('CRITICAL: ' . $key . ' is not set in env or .env.local.php');
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Configuration error: ' . $key . " is not set\n");
        exit(1);
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'code' => 'server_configuration_error',
        'message' => '服务配置不完整，请联系管理员处理',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 数据库配置
define('DB_NAME', configValue('DB_NAME', '_122_51_223_46'));
define('DB_USER', configValue('DB_USER', '_122_51_223_46'));
$requiredDbPassword = configValue('DB_PASSWORD', '');
if ($requiredDbPassword === '') {
    failMissingConfiguration('DB_PASSWORD');
}
define('DB_PASSWORD', $requiredDbPassword);
define('DB_HOST', configValue('DB_HOST', 'localhost'));
define('DB_CHARSET', configValue('DB_CHARSET', 'utf8mb4'));

// CORS配置
define('ALLOWED_ORIGINS', configValue('ALLOWED_ORIGINS', 'https://supercalf.com'));

// JWT配置
$requiredJwtSecret = configValue('JWT_SECRET', '');
if ($requiredJwtSecret === '') {
    failMissingConfiguration('JWT_SECRET');
}
define('JWT_SECRET', $requiredJwtSecret);
define('JWT_EXPIRE', 7 * 24 * 60 * 60); // 7天
define('JWT_ACCESS_EXPIRE', 15 * 60);
define('SESSION_REFRESH_EXPIRE', 30 * 24 * 60 * 60);

// WordPress Cookie名称
define('LOGGED_IN_USER_COOKIE', 'wordpress_logged_in_' . md5('zgnn') . '_cookie');

// API基础路径
define('API_BASE_URL', configValue('API_BASE_URL', 'https://supercalf.com/api'));

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
    $wechatBound = !empty($staff['openid']) || in_array(strtolower((string)($payload['login_channel'] ?? '')), ['wechat', 'wecom'], true);
    $wecomBound = !empty($staff['wecom_userid']);

    return [
        'user_id' => (int)$payload['user_id'],
        'username' => $payload['username'] ?? '',
        'role' => $payload['role'] ?? 'staff',
        'staff_id' => $staff ? (int)$staff['id'] : null,
        'wechat_bound' => $wechatBound,
        'wecom_bound' => $wecomBound,
        'wecom_userid' => $staff['wecom_userid'] ?? '',
        'wecom_name' => $staff['wecom_name'] ?? '',
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
            $staffAllowed = (int)($staff['status'] ?? 0) === 1
                && (string)($staff['lifecycle_status'] ?? 'active') === 'active'
                && array_key_exists('session_version', $payload)
                && (int)$payload['session_version'] === (int)($staff['session_version'] ?? 0);
            return $staffAllowed && isPlatformSessionAllowed($payload, (int)($staff['session_version'] ?? 0));
        }

        return $role === 'admin' && isPlatformSessionAllowed($payload, (int)($payload['session_version'] ?? 0));
    } catch (Throwable $e) {
        error_log('JWT user status check failed: ' . $e->getMessage());
        return false;
    }
}

function isPlatformSessionAllowed(array $payload, int $currentSessionVersion): bool {
    $sessionId = (string)($payload['session_id'] ?? '');
    if ($sessionId === '') {
        return true;
    }
    if (!preg_match('/^[a-f0-9]{32}$/', $sessionId)) {
        return false;
    }

    $db = getDB();
    $stmt = $db->prepare(
        "SELECT user_id, session_version, status, expires_at FROM platform_sessions WHERE id = ? LIMIT 1"
    );
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    return $session
        && (int)$session['user_id'] === (int)($payload['user_id'] ?? 0)
        && (int)$session['session_version'] === $currentSessionVersion
        && (string)$session['status'] === 'active'
        && strtotime((string)$session['expires_at']) > time();
}

function isJwtManager($user) {
    if (!$user || empty($user['user_id'])) {
        return false;
    }
    return in_array($user['role'] ?? 'staff', ['admin', 'manager'], true);
}

/** Resolve the trusted WordPress role used by the legacy PHP session path. */
function getWordPressAuthRole($userId) {
    if ((int)$userId <= 0) {
        return 'staff';
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT meta_value FROM wp_usermeta WHERE user_id = ? AND meta_key = 'wp_capabilities' LIMIT 1");
    $stmt->execute([(int)$userId]);
    $serialized = $stmt->fetchColumn();
    if (!is_string($serialized) || $serialized === '') {
        return 'staff';
    }

    $capabilities = @unserialize($serialized, ['allowed_classes' => false]);
    if (!is_array($capabilities)) {
        return 'staff';
    }
    if (!empty($capabilities['administrator'])) {
        return 'admin';
    }
    if (!empty($capabilities['editor'])) {
        return 'manager';
    }
    return 'staff';
}

/** Build one training context for either JWT or the existing trusted PHP session. */
function getCurrentTrainingAccessContext() {
    $user = getJwtCurrentUser();
    if (!$user) {
        $userId = getCurrentUserId();
        if ($userId <= 0) {
            return ['authenticated' => false, 'user_id' => 0, 'jwt_role' => '', 'staff_role' => '',
                'module_role' => '', 'is_management' => false, 'user' => null];
        }
        $staff = getStaffByUserId($userId);
        $user = [
            'user_id' => $userId,
            'role' => getWordPressAuthRole($userId),
            'staff_id' => $staff ? (int)$staff['id'] : null,
        ];
    }

    $jwtRole = strtolower(trim((string)($user['role'] ?? 'staff')));
    $staffRole = getEffectiveStaffRole($user);
    return [
        'authenticated' => true,
        'user_id' => (int)$user['user_id'],
        'jwt_role' => $jwtRole,
        'staff_role' => $staffRole,
        'module_role' => TrainingAccessPolicy::moduleRoleForStaff($staffRole),
        'is_management' => TrainingAccessPolicy::isManagementJwtRole($jwtRole),
        'user' => $user,
    ];
}

function requireTrainingAccessContext() {
    $context = getCurrentTrainingAccessContext();
    if (empty($context['authenticated'])) {
        jsonResponse(401, '请先登录');
    }
    return $context;
}

function canAccessTrainingModule(array $context, array $module) {
    return !empty($module['status'])
        && TrainingAccessPolicy::canAccessModule($context, $module['role_code'] ?? null);
}

function requireTrainingModuleAccess(array $context, array $module) {
    if (!canAccessTrainingModule($context, $module)) {
        jsonResponse(403, '无权访问该培训资源');
    }
}

function getTrainingModuleAccessSql(array $context, $alias = 'tm') {
    if (!empty($context['is_management'])) {
        return ['sql' => '1 = 1', 'params' => []];
    }
    return [
        'sql' => "($alias.role_code IS NULL OR $alias.role_code = '' OR $alias.role_code = ?)",
        'params' => [(string)($context['module_role'] ?? '')],
    ];
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
function generate_jwt($userId, $username, $role = 'staff', array $claims = [], ?int $ttl = null) {
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $issuedAt = time();
    $effectiveTtl = $ttl === null ? JWT_EXPIRE : max(60, min($ttl, JWT_EXPIRE));
    $payload = [
        'user_id' => (int)$userId,
        'username' => $username,
        'role' => $role,
        'iat' => $issuedAt,
        'exp' => $issuedAt + $effectiveTtl,
        'jti' => bin2hex(random_bytes(16)),
    ];
    $staff = getStaffByUserId((int)$userId);
    if ($staff) {
        $payload['session_version'] = (int)($staff['session_version'] ?? 0);
    }
    foreach (['session_id', 'session_family', 'client'] as $claim) {
        if (array_key_exists($claim, $claims)) {
            $payload[$claim] = $claims[$claim];
        }
    }

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
    } elseif ($code === 409) {
        $httpCode = 409;
    } elseif ($code === 429) {
        $httpCode = 429;
    } elseif (is_int($code) && $code >= 400 && $code <= 599) {
        $httpCode = $code;
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
        require_once __DIR__ . '/kernel/bootstrap.php';
        platformRequireMigrationReadiness($db, ['202607310008']);

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
 * 获取知识库受控资源 URL，只允许本站 HTTPS 或固定上传目录。
 */
function getKnowledgeResourceUrl($path) {
    $value = trim((string)$path);
    if ($value === '' || preg_match('/[\x00-\x1F\x7F\\\\]/', $value)) {
        return '';
    }

    $parsed = parse_url($value);
    if ($parsed !== false && isset($parsed['scheme'])) {
        $apiHost = strtolower((string)parse_url(API_BASE_URL, PHP_URL_HOST));
        $host = strtolower((string)($parsed['host'] ?? ''));
        if (strtolower((string)$parsed['scheme']) !== 'https' || $host === '' || $host !== $apiHost) {
            return '';
        }
        $resourcePath = (string)($parsed['path'] ?? '');
        $decodedPath = rawurldecode($resourcePath);
        if (!preg_match('#^/uploads/knowledge/[A-Za-z0-9][A-Za-z0-9._/-]*$#', $decodedPath)
            || strpos($decodedPath, '..') !== false || strpos($decodedPath, '\\') !== false) {
            return '';
        }
        return $value;
    }

    $relative = ltrim($value, '/');
    if (!preg_match('#^uploads/knowledge/[A-Za-z0-9][A-Za-z0-9._/-]*$#', $relative)
        || strpos($relative, '..') !== false) {
        return '';
    }
    return API_BASE_URL . '/../' . $relative;
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
    header('Access-Control-Allow-Headers: Content-Type, Authorization, Idempotency-Key, X-Request-ID, X-CSRF-Token');
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

// WeChat Mini Program configuration
if (!defined('WECHAT_APPID')) {
    define('WECHAT_APPID', configValue('WECHAT_APPID', ''));
}
if (!defined('WECHAT_APP_SECRET')) {
    define('WECHAT_APP_SECRET', configValue('WECHAT_APP_SECRET', ''));
}
if (!defined('WECOM_CORP_ID')) {
    define('WECOM_CORP_ID', configValue('WECOM_CORP_ID', ''));
}
if (!defined('WECOM_AGENT_ID')) {
    define('WECOM_AGENT_ID', configValue('WECOM_AGENT_ID', ''));
}
if (!defined('WECOM_APPID')) {
    define('WECOM_APPID', configValue('WECOM_APPID', ''));
}
if (!defined('WECOM_APP_SECRET')) {
    define('WECOM_APP_SECRET', configValue('WECOM_APP_SECRET', ''));
}
if (!defined('WECOM_AGENT_SECRET')) {
    define('WECOM_AGENT_SECRET', configValue('WECOM_AGENT_SECRET', WECOM_APP_SECRET));
}
if (!defined('WECOM_MINI_PROGRAM_SECRET')) {
    define('WECOM_MINI_PROGRAM_SECRET', configValue('WECOM_MINI_PROGRAM_SECRET', WECOM_APP_SECRET));
}
if (!defined('WECOM_ENABLED')) {
    define('WECOM_ENABLED', configValue('WECOM_ENABLED', '0'));
}
if (!defined('WECOM_SYNC_ROOT_DEPARTMENT_ID')) {
    define('WECOM_SYNC_ROOT_DEPARTMENT_ID', configValue('WECOM_SYNC_ROOT_DEPARTMENT_ID', '1'));
}
if (!defined('WECOM_CALLBACK_TOKEN')) {
    define('WECOM_CALLBACK_TOKEN', configValue('WECOM_CALLBACK_TOKEN', ''));
}
if (!defined('WECOM_CALLBACK_AES_KEY')) {
    define('WECOM_CALLBACK_AES_KEY', configValue('WECOM_CALLBACK_AES_KEY', ''));
}

function isTruthyConfigValue($value) {
    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
}

function isWecomEnabled() {
    return isTruthyConfigValue(WECOM_ENABLED)
        && WECOM_CORP_ID !== ''
        && WECOM_AGENT_ID !== ''
        && WECOM_APPID !== ''
        && WECOM_AGENT_SECRET !== ''
        && WECOM_MINI_PROGRAM_SECRET !== '';
}
