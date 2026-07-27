<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/common.php';
require_once dirname(__DIR__) . '/services/StaffLifecycleService.php';

header('Content-Type: application/json; charset=utf-8');
handleCORS();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(1, '仅支持 POST 请求');
}

try {
    [, $user, $operatorStaff] = adminRequirePermission('staff.create');
    $service = new StaffLifecycleService(getDB());
    $staff = $service->create(adminJsonInput(), $user, $operatorStaff ?: []);
    jsonResponse(0, 'success', ['item' => $staff]);
} catch (StaffIdentityConflictException $error) {
    jsonResponse(409, $error->getMessage(), [
        'conflict_fields' => $error->conflictFields(),
        'existing_profiles' => $error->profiles(),
    ]);
} catch (StaffLifecycleValidationException | PasswordPolicyValidationException $error) {
    jsonResponse(400, $error->getMessage(), null);
} catch (Throwable $error) {
    error_log('[admin.staff.create] ' . $error->getMessage());
    jsonResponse(1, '员工创建失败');
}
