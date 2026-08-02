<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/admin/common.php';
require_once dirname(__DIR__) . '/kernel/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
handleCORS();
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Request-ID, X-Client, X-Client-Version');

$context = platformApiContext([
    'domain' => 'wecom',
    'action' => 'wecom.status.read',
]);
$logger = new PlatformApiLogger();
platformApiInstallExceptionHandler($context, $logger);

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    throw new PlatformApiException(405, 'method_not_allowed', '请求方法不支持');
}

$auth = platformApiAuthContext();
$auth->requirePermission('system.settings');
$context = $context->withActor($auth->userId(), $auth->staffId());
$enabled = isWecomEnabled();

$data = PlatformApiCompatibility::withMetadata([
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
], '1.0.0', ['wecom_status']);

$logger->log('info', 'wecom.status.read', $context, ['enabled' => $enabled]);
platformApiResponse($context, $data)->send();
