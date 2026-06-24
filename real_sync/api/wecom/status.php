<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

header('Content-Type: application/json; charset=utf-8');
handleCORS();
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$user = getJwtCurrentUser();
$staff = null;
if ($user && !empty($user['user_id'])) {
    $staff = getStaffByUserId((int)$user['user_id']);
}

json_response(0, 'success', [
    'enabled' => isWecomEnabled(),
    'config' => [
        'corp_id_configured' => WECOM_CORP_ID !== '',
        'agent_id_configured' => WECOM_AGENT_ID !== '',
        'app_id_configured' => WECOM_APPID !== '',
        'agent_secret_configured' => WECOM_AGENT_SECRET !== '',
        'mini_program_secret_configured' => WECOM_MINI_PROGRAM_SECRET !== '',
    ],
    'user' => $user ? [
        'user_id' => (int)($user['user_id'] ?? 0),
        'staff_id' => (int)($user['staff_id'] ?? 0),
        'username' => (string)($user['username'] ?? ''),
        'role' => (string)($user['role'] ?? ''),
        'wechat_bound' => !empty($user['wechat_bound']),
        'wecom_bound' => !empty($user['wecom_bound']),
        'wecom_userid' => (string)($user['wecom_userid'] ?? ''),
        'wecom_name' => (string)($user['wecom_name'] ?? ''),
    ] : null,
    'staff' => $staff ? [
        'id' => (int)($staff['id'] ?? 0),
        'employee_no' => (string)($staff['employee_no'] ?? ''),
        'name' => (string)($staff['name'] ?? ''),
        'store_id' => isset($staff['store_id']) ? (int)$staff['store_id'] : null,
        'role' => (string)($staff['role'] ?? ''),
        'status' => isset($staff['status']) ? (int)$staff['status'] : null,
        'openid_bound' => !empty($staff['openid']),
        'wecom_userid' => (string)($staff['wecom_userid'] ?? ''),
        'wecom_name' => (string)($staff['wecom_name'] ?? ''),
        'wecom_mobile' => (string)($staff['wecom_mobile'] ?? ''),
        'wecom_department_id' => (string)($staff['wecom_department_id'] ?? ''),
        'wecom_department_path' => (string)($staff['wecom_department_path'] ?? ''),
        'wecom_status' => isset($staff['wecom_status']) ? (int)$staff['wecom_status'] : null,
        'wecom_bound_at' => (string)($staff['wecom_bound_at'] ?? ''),
    ] : null,
]);
