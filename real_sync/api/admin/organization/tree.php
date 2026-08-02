<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/common.php';
require_once dirname(__DIR__) . '/services/OrganizationService.php';
require_once dirname(__DIR__, 2) . '/kernel/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
handleCORS();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$context = platformApiContext(['domain' => 'organization', 'action' => 'organization.tree.read']);
$logger = new PlatformApiLogger();
platformApiInstallExceptionHandler($context, $logger);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    throw new PlatformApiException(405, 'method_not_allowed', '仅支持 GET 请求');
}

$auth = platformApiAuthContext();
$auth->requirePermission('organization.manage');
$context = $context->withActor($auth->userId(), $auth->staffId());
$result = (new OrganizationService(getDB()))->getOrganizationTree();
$migration = PlatformBusinessDomainRegistry::get('organization');
$result = PlatformApiCompatibility::withMetadata(
    $result,
    $migration['endpoint_version'],
    $migration['capabilities']
);

$logger->log('info', 'organization.tree.read', $context, ['staff_count' => count($result['staff'] ?? [])]);
platformApiResponse($context, $result)->send();
