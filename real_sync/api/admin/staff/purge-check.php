<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/common.php';
require_once dirname(__DIR__) . '/services/StaffAssociationService.php';

header('Content-Type: application/json; charset=utf-8');
handleCORS();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, '请求方法不被支持', null);
}

try {
    [$userId, $user, $operatorStaff] = adminRequirePermission('staff.purge');
    $operatorUser = is_array($user) ? $user : ['user_id' => (int)$userId];
    $input = adminJsonInput();
    $staffId = (int)($input['staff_id'] ?? $input['id'] ?? 0);

    $service = new StaffAssociationService(getDB());
    $result = $service->inspectForPurge($staffId, $operatorUser, $operatorStaff ?: []);
    jsonResponse(0, $result['eligible_for_purge'] ? '员工可进入受控清理确认' : '员工存在业务关联，建议离职归档', $result);
} catch (StaffAssociationValidationException $error) {
    jsonResponse(400, $error->getMessage(), null);
} catch (Throwable $error) {
    error_log('[admin.staff.purge-check] ' . $error->getMessage());
    jsonResponse(500, '员工关联检查失败', null);
}
