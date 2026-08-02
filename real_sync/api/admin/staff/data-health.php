<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/common.php';
require_once dirname(__DIR__) . '/services/StaffDataHealthService.php';
require_once dirname(__DIR__, 2) . '/kernel/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
handleCORS();

$context = platformApiContext([
    'domain' => 'staff',
    'action' => 'staff.data_health.read',
]);
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
$auth->requirePermission('staff.audit_view');
$context = $context->withActor($auth->userId(), $auth->staffId());
$user = getJwtCurrentUser() ?: [];
$staff = $auth->userId() !== null ? (getStaffByUserId($auth->userId()) ?: []) : [];
$roles = appRoleTokensFromUser($user, $staff);
$canViewSensitive = (bool)array_intersect(['operation', 'admin'], $roles);
$result = (new StaffDataHealthService(getDB(), $canViewSensitive))->inspect();
$result = PlatformApiCompatibility::withMetadata($result, '1.0.0', ['staff_data_health']);

$logger->log('info', 'staff.data_health.read', $context, [
    'healthy' => $result['healthy'] ?? null,
]);
platformApiResponse($context, $result)->send();
