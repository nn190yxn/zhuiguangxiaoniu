<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../common/context.php';
require_once __DIR__ . '/../kernel/bootstrap.php';
require_once __DIR__ . '/KnowledgeListService.php';

header('Content-Type: application/json; charset=utf-8');
handleCORS();

$context = platformApiContext(['domain' => 'knowledge', 'action' => 'knowledge.list.read']);
$logger = new PlatformApiLogger();
platformApiInstallExceptionHandler($context, $logger);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    throw new PlatformApiException(405, 'method_not_allowed', '仅支持 GET 请求');
}

$auth = platformApiAuthContext();
$auth->requireAuthenticated();
$context = $context->withActor($auth->userId(), $auth->staffId());
$staffContext = appGetCurrentStaffContext();
$staff = getStaffByUserId((int)$auth->userId()) ?: [];
$staffContext['stage'] = (string)($staff['stage'] ?? '');
$result = (new KnowledgeListService(getDB(), 'getResourceUrl'))->list(
    (int)$auth->userId(),
    $staffContext,
    $_GET
);
$migration = PlatformBusinessDomainRegistry::get('knowledge');
$result = PlatformApiCompatibility::withMetadata(
    $result,
    $migration['endpoint_version'],
    $migration['capabilities']
);

$logger->log('info', 'knowledge.list.read', $context, ['total' => $result['total']]);
platformApiResponse($context, $result)->send();
