<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/common.php';
require_once dirname(__DIR__, 2) . '/platform/LegacyEndpointGovernance.php';

$context = platformApiContext(['domain' => 'platform', 'action' => 'legacy_endpoint.status_update']);
$logger = new PlatformApiLogger();
platformApiInstallExceptionHandler($context, $logger);
$platformAuth = platformApiAuthContext();
$platformAuth->requirePermission('legacy_endpoint.manage');

if (!in_array(($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['POST', 'PATCH'], true)) {
    throw new PlatformApiException(405, 'method_not_allowed', '仅支持 POST 或 PATCH 请求');
}
$input = adminJsonInput();
$endpointId = (int)($input['endpoint_id'] ?? 0);
if ($endpointId <= 0) {
    throw new PlatformApiException(422, 'validation_failed', 'endpoint_id 必须为正整数');
}
$result = LegacyEndpointGovernance::updateStatus(
    getDB(), $endpointId, $input, (int)$platformAuth->staffId(), $context->requestId()
);
if (!$result['eligible']) {
    throw new PlatformApiException(409, 'retirement_gate_blocked', '历史入口尚未满足退役条件', $result);
}
$logger->log('info', 'legacy_endpoint.status_updated', $context, ['endpoint_id' => $endpointId]);
$data = PlatformApiCompatibility::withMetadata($result, '1.0.0', ['legacy_endpoint_governance']);
platformApiResponse($context, $data, '历史入口状态已更新')->send();
