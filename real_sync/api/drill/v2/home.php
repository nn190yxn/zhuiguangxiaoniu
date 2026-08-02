<?php
declare(strict_types=1);
require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__, 2) . '/kernel/bootstrap.php';
require_once __DIR__ . '/services/DrillEmployeeApiService.php';

$context = platformApiContext(['domain' => 'drill', 'action' => 'drill.home.read']);
$logger = new PlatformApiLogger();
platformApiInstallExceptionHandler($context, $logger);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    throw new PlatformApiException(405, 'method_not_allowed', '仅支持 GET 请求');
}

$auth = platformApiAuthContext();
$auth->requireAuthenticated();
$context = $context->withActor($auth->userId(), $auth->staffId());
$result = (new DrillEmployeeApiService(getDB()))->home((int) $auth->staffId());
$migration = PlatformBusinessDomainRegistry::get('drill');
$result = PlatformApiCompatibility::withMetadata($result, $migration['endpoint_version'], $migration['capabilities']);
$logger->log('info', 'drill.home.read', $context, ['staff_id' => $auth->staffId()]);
platformApiResponse($context, $result)->send();
