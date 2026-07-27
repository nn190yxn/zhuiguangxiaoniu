<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/admin/common.php';

header('Content-Type: application/json; charset=utf-8');
handleCORS();
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    json_response(405, '请求方法不支持');
}

adminRequirePermission('system.settings');
$enabled = isWecomEnabled();

json_response(0, 'success', [
    'enabled' => $enabled,
    'mode' => $enabled ? 'enabled' : 'disabled',
    'channels' => [
        'login' => $enabled,
        'directory_sync' => $enabled,
        'message' => $enabled,
    ],
    'config' => [
        'corp_id_configured' => WECOM_CORP_ID !== '',
        'agent_id_configured' => WECOM_AGENT_ID !== '',
        'app_id_configured' => WECOM_APPID !== '',
        'agent_secret_configured' => WECOM_AGENT_SECRET !== '',
        'mini_program_secret_configured' => WECOM_MINI_PROGRAM_SECRET !== '',
    ],
    'recovery_requirements' => [
        '启用 WECOM_ENABLED',
        '核验企业微信可信来源',
        '执行通讯录同步',
        '验证消息 worker',
        '完成企业微信真机登录与消息跳转验收',
    ],
]);
