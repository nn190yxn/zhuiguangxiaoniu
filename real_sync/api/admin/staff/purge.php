<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/common.php';
require_once dirname(__DIR__) . '/services/StaffAssociationService.php';
require_once dirname(__DIR__) . '/services/StaffLifecycleService.php';

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

    $service = new StaffLifecycleService(getDB());
    $result = $service->purgeMiscreated($staffId, $input, $operatorUser, $operatorStaff ?: []);
    jsonResponse(0, '误建员工已完成受控清理', $result);
} catch (StaffPurgeBlockedException $error) {
    jsonResponse(409, '员工存在业务关联或关联检查不完整，请改用离职归档', [
        'recommendation' => 'offboard',
        'association_summary' => $error->associationSummary(),
    ]);
} catch (StaffAssociationValidationException | StaffLifecycleValidationException $error) {
    jsonResponse(400, $error->getMessage(), null);
} catch (Throwable $error) {
    error_log('[admin.staff.purge] ' . $error->getMessage());
    jsonResponse(500, '误建员工清理失败', null);
}
