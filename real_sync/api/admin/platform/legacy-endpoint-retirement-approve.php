<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/common.php';
require_once dirname(__DIR__, 2) . '/platform/LegacyEndpointGovernance.php';

$context = platformApiContext(['domain' => 'platform', 'action' => 'legacy_endpoint.retirement_approve']);
$logger = new PlatformApiLogger();
platformApiInstallExceptionHandler($context, $logger);
$platformAuth = platformApiAuthContext();
$platformAuth->requirePermission('legacy_endpoint.retirement_approve');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    throw new PlatformApiException(405, 'method_not_allowed', '仅支持 POST 请求');
}
$input = adminJsonInput();
$approvalId = (int)($input['approval_id'] ?? 0);
if ($approvalId <= 0) {
    throw new PlatformApiException(422, 'validation_failed', 'approval_id 必须为正整数');
}
$result = LegacyEndpointGovernance::approveRetirement(
    getDB(), $approvalId, (int)$platformAuth->staffId(), $context->requestId(), $input['note'] ?? null
);
$logger->log('info', 'legacy_endpoint.retirement_approved', $context, ['approval_id' => $approvalId]);
$data = PlatformApiCompatibility::withMetadata($result, '1.0.0', ['legacy_endpoint_retirement_approval']);
platformApiResponse($context, $data, '退役审批已批准')->send();
