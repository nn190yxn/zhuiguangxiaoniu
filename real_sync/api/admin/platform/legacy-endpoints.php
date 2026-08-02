<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/common.php';
require_once dirname(__DIR__, 2) . '/platform/LegacyEndpointGovernance.php';

$context = platformApiContext(['domain' => 'platform', 'action' => 'legacy_endpoint.list']);
$logger = new PlatformApiLogger();
platformApiInstallExceptionHandler($context, $logger);
$platformAuth = platformApiAuthContext();
$platformAuth->requirePermission('legacy_endpoint.view');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    throw new PlatformApiException(405, 'method_not_allowed', '仅支持 GET 请求');
}

$rows = LegacyEndpointGovernance::list(getDB(), [
    'domain' => $_GET['domain'] ?? null,
    'status' => $_GET['status'] ?? null,
]);
$data = PlatformApiCompatibility::withMetadata(
    ['items' => $rows, 'count' => count($rows)],
    '1.0.0',
    ['legacy_endpoint_governance', 'retirement_gate']
);
platformApiResponse($context, $data)->send();
