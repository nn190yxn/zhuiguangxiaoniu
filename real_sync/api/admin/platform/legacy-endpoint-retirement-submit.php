<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/common.php';
require_once dirname(__DIR__, 2) . '/platform/LegacyEndpointGovernance.php';

$context = platformApiContext(['domain' => 'platform', 'action' => 'legacy_endpoint.retirement_submit']);
$logger = new PlatformApiLogger();
platformApiInstallExceptionHandler($context, $logger);
$platformAuth = platformApiAuthContext();
$platformAuth->requirePermission('legacy_endpoint.retirement_submit');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    throw new PlatformApiException(405, 'method_not_allowed', '仅支持 POST 请求');
}
$input = adminJsonInput();
$endpointId = (int)($input['endpoint_id'] ?? 0);
if ($endpointId <= 0) {
    throw new PlatformApiException(422, 'validation_failed', 'endpoint_id 必须为正整数');
}
$result = LegacyEndpointGovernance::submitRetirement(
    getDB(), $endpointId, $input, (int)$platformAuth->staffId(), $context->requestId()
);
$logger->log('info', 'legacy_endpoint.retirement_submitted', $context, ['endpoint_id' => $endpointId]);
$data = PlatformApiCompatibility::withMetadata($result, '1.0.0', ['legacy_endpoint_retirement_approval']);
platformApiResponse($context, $data, '退役审批已提交', 201)->send();
