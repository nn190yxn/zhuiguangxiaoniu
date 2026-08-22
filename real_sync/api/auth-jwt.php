<?php
/**
 * JWT认证API
 * POST /api/auth-jwt.php - 登录获取Token
 * GET /api/auth-jwt.php?action=verify - 验证Token
 * GET /api/auth-jwt.php?action=refresh - 刷新Token
 * POST /api/auth-jwt.php?action=wxlogin - 微信登录
 * POST /api/auth-jwt.php?action=wxbind - 绑定微信OpenID
 * POST /api/auth-jwt.php?action=wecomlogin - 企业微信登录
 * POST /api/auth-jwt.php?action=wecombind - 绑定企业微信成员
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth/SessionFactory.php';
require_once __DIR__ . '/auth/PwaSessionHttp.php';
require_once __DIR__ . '/auth/MiniProgramSession.php';
require_once __DIR__ . '/kernel/bootstrap.php';

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
try {
    ensureWecomStaffSchema($db);
} catch (PlatformApiException $exception) {
    json_response($exception->httpStatus(), $exception->getMessage(), $exception->errorData());
}

$action = $_GET['action'] ?? 'login';
$input = getRequestInput();

switch ($action) {
    case 'login':
        // 登录
        $username = isset($input['username']) ? trim($input['username']) : '';
        $password = isset($input['password']) ? $input['password'] : '';
        $device_id = normalizeDeviceId(isset($input['device_id']) ? trim($input['device_id']) : '');
        $device_fingerprint = normalizeDeviceFingerprint($device_id, isset($input['device_fingerprint']) ? trim($input['device_fingerprint']) : '');
        $deviceMeta = resolvePasswordLoginDevice($device_id, $device_fingerprint);
        $device_id = $deviceMeta['device_id'];
        $device_fingerprint = $deviceMeta['device_fingerprint'];
        $usedBrowserFallbackDevice = !empty($deviceMeta['used_browser_fallback']);

        if ($username === '' || $password === '') {
            json_response(400, '用户名和密码不能为空');
        }

        if ($device_id === '' || $device_fingerprint === '') {
            recordLoginAudit($db, null, null, 'password', 'failure', 'jwt_login', 'missing_device', [
                'device_id' => $device_id,
                'device_fingerprint' => $device_fingerprint,
                'risk_level' => 'high',
            ]);
            json_response(400, '无法识别登录设备，请刷新页面后重试');
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

        $deviceResult = $staff ? updateDeviceLogin($db, (int)$staff['id'], (string)($staff['openid'] ?? ''), $device_id, $device_fingerprint) : ['is_new_device' => false];

        // 生成JWT
        $token = generate_jwt($user['ID'], $user['user_login'], $role);
        $versionedSession = null;
        $miniProgramSession = null;
        if (($input['client_type'] ?? '') === 'pwa') {
            $versionedSession = platformSessionService($db)->issue([
                'user_id' => (int)$user['ID'],
                'staff_id' => $staff ? (int)$staff['id'] : null,
                'username' => (string)$user['user_login'],
                'role' => $role,
                'session_version' => $staff ? (int)($staff['session_version'] ?? 0) : 0,
            ], 'pwa', $device_id);
            platformPwaSetSessionCookies(
                $versionedSession['refresh_token'],
                $versionedSession['session_id'],
                $versionedSession['refresh_expires_in']
            );
            $token = $versionedSession['access_token'];
        } elseif (($input['client_type'] ?? '') === 'mini_program' && $staff) {
            $miniProgramSession = platformIssueMiniProgramSession(
                $db,
                $staff,
                (string)$user['user_login'],
                $role,
                $device_id,
                (string)($input['identity_provider'] ?? '')
            );
            if ($miniProgramSession) {
                $token = $miniProgramSession['access_token'];
            }
        }
        $wechatBound = $role === 'admin' || !empty($staff['openid']);
        $wecomBound = $role === 'admin' || !empty($staff['wecom_userid']);
        $pendingBindTicket = null;
        if (($input['client_type'] ?? '') === 'mini_program' && $staff) {
            $bindMode = (string)($input['identity_provider'] ?? '') === 'wecom' ? 'wecom' : 'wechat';
            $needsBindTicket = $bindMode === 'wecom' ? !$wecomBound : !$wechatBound;
            if ($needsBindTicket) {
                $pendingBindTicket = issueMiniProgramBindTicket(
                    $db,
                    (int)$staff['id'],
                    (int)$user['ID'],
                    $bindMode,
                    $device_id,
                    $device_fingerprint
                );
            }
        }
        $loginAuditMessage = $wechatBound ? ($deviceResult['is_new_device'] ? 'new_device' : 'success') : 'success_unbound_wechat';
        if ($usedBrowserFallbackDevice) {
            $loginAuditMessage = 'browser_login_success';
        }
        recordLoginAudit($db, (int)$user['ID'], $staff ? (int)$staff['id'] : null, 'password', 'success', 'jwt_login', $loginAuditMessage, [
            'device_id' => $device_id,
            'device_fingerprint' => $device_fingerprint,
            'is_new_device' => !empty($deviceResult['is_new_device']),
            'risk_level' => $usedBrowserFallbackDevice ? 'low' : ($wechatBound ? (!empty($deviceResult['is_new_device']) ? 'medium' : 'normal') : 'medium'),
        ]);

        $response = [
            'token' => $token,
            'user' => [
                'id' => $user['ID'],
                'username' => $user['user_login'],
                'role' => $role,
                'staff_id' => $staff ? (int)$staff['id'] : null,
                'wechat_bound' => $wechatBound,
                'wecom_bound' => $wecomBound,
            ],
            'expire' => $versionedSession['access_expires_in'] ?? JWT_EXPIRE,
            'session_id' => $versionedSession['session_id'] ?? null,
            'session_version' => $versionedSession['session_version'] ?? ($staff['session_version'] ?? 0),
        ];
        if ($miniProgramSession) {
            $response = array_merge($response, platformMiniProgramResponse($miniProgramSession));
        }
        if ($pendingBindTicket) {
            $response['pending_wechat_bind_ticket'] = $pendingBindTicket;
        }
        json_response(0, 'success', $response);
        break;

    case 'wxlogin':
        // 微信一键登录
        $code = isset($input['code']) ? trim($input['code']) : '';
        $device_id = normalizeDeviceId(isset($input['device_id']) ? trim($input['device_id']) : '');
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
        $wechatSession = getWeChatSession($code);
        if (!$wechatSession['ok']) {
            json_response($wechatSession['status_code'], $wechatSession['message']);
        }
        $openid = $wechatSession['openid'];

        // 查询openid是否已绑定员工
        $sql = "SELECT s.id, s.user_id, s.employee_no, s.name, s.role, s.status, s.lifecycle_status,
                       s.session_version, s.openid, s.wecom_userid, u.ID, u.user_login
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
        $miniProgramSession = null;
        if (($input['client_type'] ?? '') === 'mini_program') {
            $miniProgramSession = platformIssueMiniProgramSession(
                $db,
                $staff,
                (string)$staff['user_login'],
                $role,
                $device_id,
                'wechat'
            );
            $token = $miniProgramSession['access_token'];
        }
        recordLoginAudit($db, (int)$staff['user_id'], (int)$staff['id'], 'wechat', 'success', 'wxlogin', $deviceResult['is_new_device'] ? 'new_device' : 'success', [
            'device_id' => $device_id,
            'device_fingerprint' => $device_fingerprint,
            'is_new_device' => !empty($deviceResult['is_new_device']),
            'risk_level' => !empty($deviceResult['is_new_device']) ? 'medium' : 'normal',
        ]);

        $response = [
            'token' => $token,
            'user' => [
                'id' => (int)$staff['user_id'],
                'username' => $staff['user_login'],
                'role' => $role,
                'staff_id' => $staff['id'],
                'wechat_bound' => true,
                'wecom_bound' => !empty($staff['wecom_userid']),
            ],
            'expire' => JWT_EXPIRE
        ];
        if ($miniProgramSession) {
            $response = array_merge($response, platformMiniProgramResponse($miniProgramSession));
        }
        json_response(0, 'success', $response);
        break;

    case 'wxbind':
        // 绑定微信OpenID到员工账号
        $code = isset($input['code']) ? trim($input['code']) : '';
        $employee_no = isset($input['employee_no']) ? trim($input['employee_no']) : '';
        $username = isset($input['username']) ? trim($input['username']) : $employee_no;
        $password = isset($input['password']) ? $input['password'] : '';
        $bindTicket = normalizeBindTicket($input['pending_wechat_bind_ticket'] ?? $input['bind_ticket'] ?? '');
        $device_id = normalizeDeviceId(isset($input['device_id']) ? trim($input['device_id']) : '');
        $device_fingerprint = normalizeDeviceFingerprint($device_id, isset($input['device_fingerprint']) ? trim($input['device_fingerprint']) : '');

        if (empty($code) || ($bindTicket === '' && (empty($username) || empty($password)))) {
            json_response(400, '参数不完整');
        }

        if ($device_id === '' || $device_fingerprint === '') {
            json_response(400, '无法识别设备，请重新打开小程序后再试');
        }

        // 通过code换取openid
        $wechatSession = getWeChatSession($code);
        if (!$wechatSession['ok']) {
            json_response($wechatSession['status_code'], $wechatSession['message']);
        }
        $openid = $wechatSession['openid'];

        if ($bindTicket !== '') {
            $staff = bindMiniProgramWechatTicket($db, $bindTicket, $device_id, $device_fingerprint, $openid);
        } else {
            $staff = resolvePasswordBindStaff($db, $username, $password);
            bindWechatOpenid($db, $staff, $openid);
            $staff['openid'] = $openid;
            $staff['session_version'] = (int)($staff['session_version'] ?? 0) + 1;
        }

        $role = getUserRole($db, (int)$staff['user_id']);
        $deviceResult = updateDeviceLogin($db, (int)$staff['id'], $openid, $device_id, $device_fingerprint);
        recordLoginAudit($db, (int)$staff['user_id'], (int)$staff['id'], 'wechat_bind', 'success', 'wxbind', $deviceResult['is_new_device'] ? 'bind_new_device' : 'bind_success', [
            'device_id' => $device_id,
            'device_fingerprint' => $device_fingerprint,
            'is_new_device' => !empty($deviceResult['is_new_device']),
            'risk_level' => !empty($deviceResult['is_new_device']) ? 'medium' : 'normal',
        ]);

        $token = generate_jwt($staff['user_id'], $staff['user_login'], $role);
        $miniProgramSession = null;
        if (($input['client_type'] ?? '') === 'mini_program') {
            $miniProgramSession = platformIssueMiniProgramSession(
                $db,
                $staff,
                (string)$staff['user_login'],
                $role,
                $device_id,
                'wechat'
            );
            $token = $miniProgramSession['access_token'];
        }

        $response = [
            'openid' => substr($openid, 0, 20) . '...',
            'token' => $token,
            'user' => [
                'id' => (int)$staff['user_id'],
                'username' => $staff['user_login'],
                'role' => $role,
                'staff_id' => (int)$staff['id'],
                'wechat_bound' => true,
                'wecom_bound' => !empty($staff['wecom_userid']),
            ],
            'expire' => JWT_EXPIRE,
        ];
        if ($miniProgramSession) {
            $response = array_merge($response, platformMiniProgramResponse($miniProgramSession));
        }
        json_response(0, '绑定成功', $response);
        break;

    case 'wecomlogin':
        ensureWecomStaffSchema($db);
        if (!isWecomEnabled()) {
            json_response(503, '企业微信登录配置未完成，请联系管理员处理');
        }

        $code = isset($input['code']) ? trim($input['code']) : '';
        $wecomUserId = normalizeWecomValue($input['wecom_userid'] ?? '');
        $wecomName = normalizeWecomValue($input['wecom_name'] ?? '');
        $device_id = normalizeDeviceId(isset($input['device_id']) ? trim($input['device_id']) : '');
        $device_fingerprint = normalizeDeviceFingerprint($device_id, isset($input['device_fingerprint']) ? trim($input['device_fingerprint']) : '');

        if ($code === '' || $wecomUserId === '') {
            json_response(400, '企业微信授权信息不完整，请重新进入应用后再试');
        }

        if ($device_id === '' || $device_fingerprint === '') {
            recordLoginAudit($db, null, null, 'wecom', 'failure', 'wecomlogin', 'missing_device', [
                'device_id' => $device_id,
                'device_fingerprint' => $device_fingerprint,
                'risk_level' => 'high',
            ]);
            json_response(400, '无法识别设备，请重新打开企业微信后再试');
        }

        $wecomSession = getWecomSession($code);
        if (!$wecomSession['ok']) {
            json_response($wecomSession['status_code'], $wecomSession['message']);
        }

        $sql = "SELECT s.id, s.user_id, s.employee_no, s.name, s.role, s.status, s.lifecycle_status,
                       s.session_version, s.openid, s.wecom_userid, u.ID, u.user_login
                FROM staffs s
                LEFT JOIN wp_users u ON s.user_id = u.ID
                WHERE s.wecom_userid = ? LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([$wecomUserId]);
        $staff = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$staff) {
            recordLoginAudit($db, null, null, 'wecom', 'failure', 'wecomlogin', 'unbound_wecom_userid', [
                'device_id' => $device_id,
                'device_fingerprint' => $device_fingerprint,
                'risk_level' => 'medium',
            ]);
            json_response(401, '当前企业微信成员还未关联员工账号，请先使用账号密码登录完成绑定', ['need_bind' => true]);
        }

        if ((int)$staff['status'] !== 1) {
            recordLoginAudit($db, (int)($staff['user_id'] ?? 0), (int)$staff['id'], 'wecom', 'failure', 'wecomlogin', 'disabled_staff');
            json_response(403, '员工账号已停用，请联系管理员');
        }

        if (!$staff['user_id'] || !$staff['ID']) {
            recordLoginAudit($db, (int)($staff['user_id'] ?? 0), (int)$staff['id'], 'wecom', 'failure', 'wecomlogin', 'missing_user');
            json_response(401, '未找到关联的系统账号');
        }

        syncWecomStaffIdentity($db, (int)$staff['id'], [
            'wecom_userid' => $wecomUserId,
            'wecom_name' => $wecomName,
            'openid' => $wecomSession['openid'],
        ]);

        $role = getUserRole($db, (int)$staff['user_id']);
        $deviceResult = updateDeviceLogin($db, $staff['id'], $wecomSession['openid'], $device_id, $device_fingerprint);
        $token = generate_jwt($staff['user_id'], $staff['user_login'], $role);
        $miniProgramSession = null;
        if (($input['client_type'] ?? '') === 'mini_program') {
            $miniProgramSession = platformIssueMiniProgramSession(
                $db,
                $staff,
                (string)$staff['user_login'],
                $role,
                $device_id,
                'wecom'
            );
            $token = $miniProgramSession['access_token'];
        }
        recordLoginAudit($db, (int)$staff['user_id'], (int)$staff['id'], 'wecom', 'success', 'wecomlogin', $deviceResult['is_new_device'] ? 'new_device' : 'success', [
            'device_id' => $device_id,
            'device_fingerprint' => $device_fingerprint,
            'is_new_device' => !empty($deviceResult['is_new_device']),
            'risk_level' => !empty($deviceResult['is_new_device']) ? 'medium' : 'normal',
        ]);

        $response = [
            'token' => $token,
            'user' => [
                'id' => (int)$staff['user_id'],
                'username' => $staff['user_login'],
                'role' => $role,
                'staff_id' => (int)$staff['id'],
                'wechat_bound' => !empty($staff['openid']) || $wecomSession['openid'] !== '',
                'wecom_bound' => true,
                'login_channel' => 'wecom',
            ],
            'expire' => JWT_EXPIRE,
        ];
        if ($miniProgramSession) {
            $response = array_merge($response, platformMiniProgramResponse($miniProgramSession));
        }
        json_response(0, 'success', $response);
        break;

    case 'wecombind':
        ensureWecomStaffSchema($db);
        if (!isWecomEnabled()) {
            json_response(503, '企业微信登录配置未完成，请联系管理员处理');
        }

        $code = isset($input['code']) ? trim($input['code']) : '';
        $username = isset($input['username']) ? trim($input['username']) : '';
        $password = isset($input['password']) ? $input['password'] : '';
        $bindTicket = normalizeBindTicket($input['pending_wechat_bind_ticket'] ?? $input['bind_ticket'] ?? '');
        $wecomUserId = normalizeWecomValue($input['wecom_userid'] ?? '');
        $wecomName = normalizeWecomValue($input['wecom_name'] ?? '');
        $device_id = normalizeDeviceId(isset($input['device_id']) ? trim($input['device_id']) : '');
        $device_fingerprint = normalizeDeviceFingerprint($device_id, isset($input['device_fingerprint']) ? trim($input['device_fingerprint']) : '');

        if ($code === '' || $wecomUserId === '' || ($bindTicket === '' && ($username === '' || $password === ''))) {
            json_response(400, '参数不完整');
        }

        if ($device_id === '' || $device_fingerprint === '') {
            json_response(400, '无法识别设备，请重新打开企业微信后再试');
        }

        $wecomSession = getWecomSession($code);
        if (!$wecomSession['ok']) {
            json_response($wecomSession['status_code'], $wecomSession['message']);
        }

        if ($bindTicket !== '') {
            $staff = bindMiniProgramWecomTicket($db, $bindTicket, $device_id, $device_fingerprint, [
                'wecom_userid' => $wecomUserId,
                'wecom_name' => $wecomName,
                'openid' => $wecomSession['openid'],
            ]);
        } else {
            $staff = resolvePasswordBindStaff($db, $username, $password);
            bindWecomIdentity($db, $staff, [
                'wecom_userid' => $wecomUserId,
                'wecom_name' => $wecomName,
                'openid' => $wecomSession['openid'],
            ]);
        }
        $staff['wecom_userid'] = $wecomUserId;
        $staff['openid'] = $staff['openid'] ?: $wecomSession['openid'];
        $staff['session_version'] = (int)($staff['session_version'] ?? 0) + 1;

        $role = getUserRole($db, (int)$staff['user_id']);
        $deviceResult = updateDeviceLogin($db, (int)$staff['id'], $wecomSession['openid'], $device_id, $device_fingerprint);
        recordLoginAudit($db, (int)$staff['user_id'], (int)$staff['id'], 'wecom_bind', 'success', 'wecombind', $deviceResult['is_new_device'] ? 'bind_new_device' : 'bind_success', [
            'device_id' => $device_id,
            'device_fingerprint' => $device_fingerprint,
            'is_new_device' => !empty($deviceResult['is_new_device']),
            'risk_level' => !empty($deviceResult['is_new_device']) ? 'medium' : 'normal',
        ]);

        $token = generate_jwt($staff['user_id'], $staff['user_login'], $role);
        $miniProgramSession = null;
        if (($input['client_type'] ?? '') === 'mini_program') {
            $miniProgramSession = platformIssueMiniProgramSession(
                $db,
                $staff,
                (string)$staff['user_login'],
                $role,
                $device_id,
                'wecom'
            );
            $token = $miniProgramSession['access_token'];
        }

        $response = [
            'token' => $token,
            'user' => [
                'id' => (int)$staff['user_id'],
                'username' => $staff['user_login'],
                'role' => $role,
                'staff_id' => (int)$staff['id'],
                'wechat_bound' => !empty($staff['openid']) || $wecomSession['openid'] !== '',
                'wecom_bound' => true,
                'login_channel' => 'wecom',
            ],
            'expire' => JWT_EXPIRE,
        ];
        if ($miniProgramSession) {
            $response = array_merge($response, platformMiniProgramResponse($miniProgramSession));
        }
        json_response(0, '绑定成功', $response);
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
function getWeChatSession($code) {
    $appid = defined('WECHAT_APPID') ? WECHAT_APPID : getenv('WECHAT_APPID');
    $secret = defined('WECHAT_APP_SECRET') ? WECHAT_APP_SECRET : getenv('WECHAT_APP_SECRET');

    if (empty($appid) || empty($secret)) {
        error_log('WeChat AppID or Secret not configured');
        return [
            'ok' => false,
            'openid' => null,
            'status_code' => 500,
            'message' => '微信登录配置缺失，请联系管理员处理',
        ];
    }

    $url = "https://api.weixin.qq.com/sns/jscode2session?appid={$appid}&secret={$secret}&js_code={$code}&grant_type=authorization_code";

    $response = httpGetJsonWithTimeout($url, 3, 8);
    if (!$response['ok']) {
        error_log('[auth.wechat_session] request_failed ' . ($response['error'] ?? 'unknown'));
        return [
            'ok' => false,
            'openid' => null,
            'status_code' => 503,
            'message' => '微信服务连接超时，请稍后重试',
        ];
    }

    $data = json_decode((string)$response['body'], true);
    if (!is_array($data)) {
        error_log('[auth.wechat_session] invalid_json');
        return [
            'ok' => false,
            'openid' => null,
            'status_code' => 502,
            'message' => '微信服务返回异常，请稍后重试',
        ];
    }

    if (isset($data['errcode']) && $data['errcode'] !== 0) {
        error_log('[auth.wechat_session] api_error ' . $data['errcode'] . ' ' . ($data['errmsg'] ?? ''));
        return [
            'ok' => false,
            'openid' => null,
            'status_code' => 401,
            'message' => '微信授权失效，请重新发起授权',
        ];
    }

    $openid = isset($data['openid']) ? trim((string)$data['openid']) : '';
    if ($openid === '') {
        error_log('[auth.wechat_session] missing_openid');
        return [
            'ok' => false,
            'openid' => null,
            'status_code' => 502,
            'message' => '微信服务返回异常，请稍后重试',
        ];
    }

    return [
        'ok' => true,
        'openid' => $openid,
        'status_code' => 200,
        'message' => 'success',
    ];
}

function getWecomSession($code) {
    if (!isWecomEnabled()) {
        return [
            'ok' => false,
            'openid' => null,
            'status_code' => 500,
            'message' => '企业微信登录配置缺失，请联系管理员处理',
        ];
    }

    $url = 'https://qyapi.weixin.qq.com/cgi-bin/miniprogram/jscode2session?appid=' . rawurlencode(WECOM_APPID)
        . '&secret=' . rawurlencode(WECOM_MINI_PROGRAM_SECRET)
        . '&js_code=' . rawurlencode($code)
        . '&grant_type=authorization_code';

    $response = httpGetJsonWithTimeout($url, 3, 8);
    if (!$response['ok']) {
        error_log('[auth.wecom_session] request_failed ' . ($response['error'] ?? 'unknown'));
        return [
            'ok' => false,
            'openid' => null,
            'status_code' => 503,
            'message' => '企业微信服务连接超时，请稍后重试',
        ];
    }

    $data = json_decode((string)$response['body'], true);
    if (!is_array($data)) {
        error_log('[auth.wecom_session] invalid_json');
        return [
            'ok' => false,
            'openid' => null,
            'status_code' => 502,
            'message' => '企业微信服务返回异常，请稍后重试',
        ];
    }

    if (isset($data['errcode']) && (int)$data['errcode'] !== 0) {
        error_log('[auth.wecom_session] api_error ' . $data['errcode'] . ' ' . ($data['errmsg'] ?? ''));
        return [
            'ok' => false,
            'openid' => null,
            'status_code' => 401,
            'message' => '企业微信授权失效，请重新进入应用',
        ];
    }

    $openid = isset($data['openid']) ? trim((string)$data['openid']) : '';
    if ($openid === '') {
        error_log('[auth.wecom_session] missing_openid');
        return [
            'ok' => false,
            'openid' => null,
            'status_code' => 502,
            'message' => '企业微信服务返回异常，请稍后重试',
        ];
    }

    return [
        'ok' => true,
        'openid' => $openid,
        'status_code' => 200,
        'message' => 'success',
    ];
}

function ensureWecomStaffSchema(PDO $db): void {
    static $initialized = false;
    if ($initialized) {
        return;
    }

    platformRequireMigrationReadiness($db, ['202607310005']);
    $initialized = true;
}

function normalizeWecomValue($value, int $maxLength = 128): string {
    return mb_substr(trim((string)$value), 0, $maxLength);
}

function syncWecomStaffIdentity(PDO $db, int $staffId, array $payload): void {
    $wecomUserId = normalizeWecomValue($payload['wecom_userid'] ?? '', 128);
    $wecomName = normalizeWecomValue($payload['wecom_name'] ?? '', 100);
    $openid = normalizeWecomValue($payload['openid'] ?? '', 128);
    $bind = !empty($payload['bind']);

    $sql = 'UPDATE staffs SET wecom_userid = ?, wecom_name = ?, wecom_status = 1';
    $params = [$wecomUserId, $wecomName];

    if ($openid !== '') {
        $sql .= ', openid = COALESCE(NULLIF(openid, \'\'), ?)';
        $params[] = $openid;
    }
    if ($bind) {
        $sql .= ', wecom_bound_at = NOW(), session_version = session_version + 1';
    }

    $sql .= ' WHERE id = ?';
    $params[] = $staffId;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
}

function ensureMiniProgramBindTicketTable(PDO $db): void {
    platformRequireMigrationReadiness($db, ['202608210001']);
}

function normalizeBindTicket($value): string {
    return preg_match('/^[A-Za-z0-9_-]{32,128}$/', (string)$value) ? (string)$value : '';
}

function issueMiniProgramBindTicket(PDO $db, int $staffId, int $userId, string $bindMode, string $deviceId, string $deviceFingerprint): array {
    ensureMiniProgramBindTicketTable($db);
    $bindMode = $bindMode === 'wecom' ? 'wecom' : 'wechat';
    $ticket = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $ticketHash = hash('sha256', $ticket);
    $expiresIn = 600;
    $stmt = $db->prepare('INSERT INTO miniprogram_bind_tickets (ticket_hash, staff_id, user_id, bind_mode, device_id, device_fingerprint, expires_at) VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))');
    $stmt->execute([$ticketHash, $staffId, $userId, $bindMode, $deviceId, $deviceFingerprint, $expiresIn]);
    return [
        'ticket' => $ticket,
        'bind_mode' => $bindMode,
        'expires_in' => $expiresIn,
    ];
}

function consumeMiniProgramBindTicket(PDO $db, string $ticket, string $bindMode, string $deviceId, string $deviceFingerprint): array {
    ensureMiniProgramBindTicketTable($db);
    $stmt = $db->prepare("SELECT t.id AS ticket_id, t.bind_mode, t.device_id, t.device_fingerprint,
               s.id, s.status, s.lifecycle_status, s.session_version, s.openid, s.wecom_userid,
               u.ID AS user_id, u.user_login, u.user_pass
        FROM miniprogram_bind_tickets t
        INNER JOIN staffs s ON s.id = t.staff_id
        INNER JOIN wp_users u ON u.ID = t.user_id AND u.ID = s.user_id
        WHERE t.ticket_hash = ? AND t.consumed_at IS NULL AND t.expires_at > NOW()
        LIMIT 1 FOR UPDATE");
    $stmt->execute([hash('sha256', $ticket)]);
    $staff = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$staff) {
        json_response(401, '绑定凭据已失效，请重新登录后再绑定');
    }
    if ((string)$staff['bind_mode'] !== $bindMode) {
        json_response(400, '绑定类型不匹配，请重新登录后再绑定');
    }
    if (!hash_equals((string)$staff['device_id'], $deviceId) || !hash_equals((string)$staff['device_fingerprint'], $deviceFingerprint)) {
        json_response(401, '登录设备已变化，请重新登录后再绑定');
    }
    if ((int)$staff['status'] !== 1) {
        json_response(403, '员工账号已停用，请联系管理员');
    }
    $stmt = $db->prepare('UPDATE miniprogram_bind_tickets SET consumed_at = NOW() WHERE id = ? AND consumed_at IS NULL');
    $stmt->execute([(int)$staff['ticket_id']]);
    if ($stmt->rowCount() !== 1) {
        json_response(409, '绑定凭据已被使用，请重新登录后再绑定');
    }
    return $staff;
}

function resolvePasswordBindStaff(PDO $db, string $username, string $password): array {
    $sql = "SELECT s.id, s.status, s.lifecycle_status, s.session_version, s.openid, s.wecom_userid,
                   u.ID as user_id, u.user_login, u.user_pass
            FROM staffs s
            LEFT JOIN wp_users u ON s.user_id = u.ID
            WHERE s.employee_no = ? OR s.phone = ? OR u.user_login = ? OR u.user_email = ? LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->execute([$username, $username, $username, $username]);
    $staff = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$staff || !$staff['user_id'] || !wp_verify_password($password, $staff['user_pass'])) {
        json_response(401, '用户名或密码错误');
    }
    if ((int)$staff['status'] !== 1) {
        json_response(403, '员工账号已停用，请联系管理员');
    }
    return $staff;
}

function bindWechatOpenid(PDO $db, array $staff, string $openid): void {
    $stmt = $db->prepare('SELECT id FROM staffs WHERE openid = ? AND id <> ? LIMIT 1');
    $stmt->execute([$openid, $staff['id']]);
    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
        json_response(403, '该微信已被其他账号绑定');
    }
    if (!empty($staff['openid']) && $staff['openid'] !== $openid) {
        json_response(403, '该微信已被其他账号绑定');
    }
    $stmt = $db->prepare('UPDATE staffs SET openid = ?, openid_bound_at = NOW(), session_version = session_version + 1 WHERE id = ?');
    $stmt->execute([$openid, $staff['id']]);
}

function bindWecomIdentity(PDO $db, array $staff, array $payload): void {
    $wecomUserId = normalizeWecomValue($payload['wecom_userid'] ?? '');
    $stmt = $db->prepare('SELECT id FROM staffs WHERE wecom_userid = ? AND id <> ? LIMIT 1');
    $stmt->execute([$wecomUserId, $staff['id']]);
    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
        json_response(403, '该企业微信成员已绑定其他账号');
    }
    if (!empty($staff['wecom_userid']) && $staff['wecom_userid'] !== $wecomUserId) {
        json_response(403, '该账号已绑定其他企业微信成员');
    }
    syncWecomStaffIdentity($db, (int)$staff['id'], [
        'wecom_userid' => $wecomUserId,
        'wecom_name' => $payload['wecom_name'] ?? '',
        'openid' => $payload['openid'] ?? '',
        'bind' => true,
    ]);
}

function bindMiniProgramWechatTicket(PDO $db, string $ticket, string $deviceId, string $deviceFingerprint, string $openid): array {
    try {
        $db->beginTransaction();
        $staff = consumeMiniProgramBindTicket($db, $ticket, 'wechat', $deviceId, $deviceFingerprint);
        bindWechatOpenid($db, $staff, $openid);
        $db->commit();
        $staff['openid'] = $openid;
        $staff['session_version'] = (int)($staff['session_version'] ?? 0) + 1;
        return $staff;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('[auth.bind_ticket.wechat] ' . $e->getMessage());
        json_response(500, '微信绑定失败，请稍后重试');
    }
}

function bindMiniProgramWecomTicket(PDO $db, string $ticket, string $deviceId, string $deviceFingerprint, array $payload): array {
    try {
        $db->beginTransaction();
        $staff = consumeMiniProgramBindTicket($db, $ticket, 'wecom', $deviceId, $deviceFingerprint);
        bindWecomIdentity($db, $staff, $payload);
        $db->commit();
        return $staff;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('[auth.bind_ticket.wecom] ' . $e->getMessage());
        json_response(500, '企业微信关联失败，请稍后重试');
    }
}

function httpGetJsonWithTimeout(string $url, int $connectTimeoutSeconds, int $timeoutSeconds): array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $connectTimeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'body' => null, 'status_code' => $httpCode, 'error' => $error ?: 'curl_exec_failed'];
        }

        return ['ok' => true, 'body' => $body, 'status_code' => $httpCode, 'error' => ''];
    }

    $context = stream_context_create([
        'http' => [
            'timeout' => $timeoutSeconds,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        $lastError = error_get_last();
        return ['ok' => false, 'body' => null, 'status_code' => 0, 'error' => $lastError['message'] ?? 'stream_request_failed'];
    }

    return ['ok' => true, 'body' => $body, 'status_code' => 200, 'error' => ''];
}

function normalizeDeviceId(string $deviceId): string {
    return mb_substr(trim($deviceId), 0, 120);
}

/**
 * 更新设备登录记录
 */
function updateDeviceLogin($db, $staff_id, $openid, $device_id, $device_fingerprint) {
    ensureDeviceLoginsTable($db);
    $now = date('Y-m-d H:i:s');
    $device_id = normalizeDeviceId((string)$device_id);
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
    platformRequireMigrationReadiness($db, ['202607310005']);
}

function ensureDeviceLoginsTable(PDO $db): void {
    platformRequireMigrationReadiness($db, ['202607310005']);
}

function normalizeDeviceFingerprint(string $deviceId, string $deviceFingerprint): string {
    $deviceFingerprint = trim($deviceFingerprint);
    if ($deviceFingerprint !== '') {
        return mb_substr($deviceFingerprint, 0, 120);
    }
    $deviceId = normalizeDeviceId($deviceId);
    return $deviceId !== '' ? mb_substr($deviceId, 0, 120) : '';
}

function resolvePasswordLoginDevice(string $deviceId, string $deviceFingerprint): array {
    $normalizedFingerprint = normalizeDeviceFingerprint($deviceId, $deviceFingerprint);
    $deviceId = normalizeDeviceId($deviceId);
    if ($deviceId !== '' && $normalizedFingerprint !== '') {
        return [
            'device_id' => mb_substr($deviceId, 0, 120),
            'device_fingerprint' => $normalizedFingerprint,
            'used_browser_fallback' => false,
        ];
    }

    if (!isBrowserPasswordLoginRequest()) {
        return [
            'device_id' => $deviceId,
            'device_fingerprint' => $normalizedFingerprint,
            'used_browser_fallback' => false,
        ];
    }

    $userAgent = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? 'browser'));
    $acceptLanguage = trim((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    $seed = strtolower($userAgent . '|' . $acceptLanguage);
    $hash = substr(sha1($seed), 0, 24);
    $browserDeviceId = 'H5_' . strtoupper(substr($hash, 0, 12));

    return [
        'device_id' => $browserDeviceId,
        'device_fingerprint' => 'H5|' . $hash,
        'used_browser_fallback' => true,
    ];
}

function isBrowserPasswordLoginRequest(): bool {
    $userAgent = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($userAgent === '') {
        return false;
    }

    if (strpos($userAgent, 'micromessenger') !== false && strpos($userAgent, 'miniprogram') !== false) {
        return false;
    }

    return strpos($userAgent, 'mozilla/') !== false
        || strpos($userAgent, 'iphone') !== false
        || strpos($userAgent, 'android') !== false
        || strpos($userAgent, 'windows nt') !== false
        || strpos($userAgent, 'macintosh') !== false;
}

function tableColumnExists(PDO $db, string $table, string $column): bool {
    $stmt = $db->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '` LIKE ' . $db->quote($column));
    return (bool)($stmt ? $stmt->fetchColumn() : false);
}

function getVarcharColumnLength(PDO $db, string $table, string $column): ?int {
    $stmt = $db->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '` LIKE ' . $db->quote($column));
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
    if (!$row || empty($row['Type'])) {
        return null;
    }
    if (preg_match('/varchar\((\d+)\)/i', (string)$row['Type'], $matches)) {
        return (int)$matches[1];
    }
    return null;
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
