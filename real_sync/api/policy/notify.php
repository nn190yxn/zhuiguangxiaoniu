<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/common.php';
require_once __DIR__ . '/../reminder/_common.php';
require_once __DIR__ . '/../kernel/bootstrap.php';
require_once __DIR__ . '/PolicyNotificationService.php';

header('Content-Type: application/json; charset=utf-8');
handleCORS();

$method = $_SERVER['REQUEST_METHOD'];
$input = getRequestInput();
$action = (string)($_GET['action'] ?? $input['action'] ?? ($method === 'GET' ? 'list' : ''));
$context = platformApiContext(['domain' => 'policy', 'action' => 'policy.notification.' . ($action ?: 'unknown')]);
$logger = new PlatformApiLogger();
platformApiInstallExceptionHandler($context, $logger);

if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}
$allowedMethods = ['list' => 'GET', 'read' => 'POST', 'confirm' => 'POST', 'send' => 'POST'];
if (!isset($allowedMethods[$action])) {
    throw new PlatformApiException(400, 'unknown_action', '未知操作');
}
if ($allowedMethods[$action] !== $method) {
    throw new PlatformApiException(405, 'method_not_allowed', '请求方法不被支持');
}

$auth = platformApiAuthContext();
$auth->requireAuthenticated();
if ($action === 'send') {
    $auth->requirePermission('policy.notify_send');
}
$context = $context->withActor($auth->userId(), $auth->staffId());
$service = new PolicyNotificationService(getDB());
$userId = (int)$auth->userId();
$result = match ($action) {
    'list' => $service->list($userId, $_GET),
    'read' => $service->markRead($userId, (string)($input['id'] ?? $_GET['id'] ?? '')),
    'confirm' => $service->confirm($userId, (string)($input['id'] ?? $_GET['id'] ?? '')),
    'send' => $service->send($input),
};
$migration = PlatformBusinessDomainRegistry::get('policy');
$result = PlatformApiCompatibility::withMetadata(
    $result,
    $migration['endpoint_version'],
    $migration['capabilities']
);

$logger->log('info', 'policy.notification.' . $action, $context, [
    'notification_id' => $input['id'] ?? $_GET['id'] ?? null,
    'sent_count' => $result['sent_count'] ?? null,
]);
platformApiResponse($context, $result)->send();
