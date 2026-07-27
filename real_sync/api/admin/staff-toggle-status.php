<?php
/**
 * Admin toggle staff account status (enable/disable)
 */
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/services/OrganizationService.php';
require_once __DIR__ . '/services/StaffLifecycleService.php';
handleCORS();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(1, '仅支持 POST 请求');
}

$input = adminJsonInput();
$staffId = max(0, (int)($input['staff_id'] ?? 0));
$status = (int)($input['status'] ?? 0);

if ($staffId <= 0) {
    jsonResponse(1, '缺少员工ID');
}
if (!in_array($status, [0, 1], true)) {
    jsonResponse(1, '状态值无效');
}

try {
    [$userId, $user, $operatorStaff] = adminRequirePermission('staff.edit');
    $operatorUser = is_array($user) ? $user : ['user_id' => (int)$userId];
    $input['status'] = $status;
    $input['change_reason'] = trim((string)($input['change_reason'] ?? $input['reason'] ?? '兼容入口账号状态变更'));
    $result = (new StaffLifecycleService(getDB()))->update(
        $staffId,
        $input,
        $operatorUser,
        $operatorStaff ?: []
    );
    $label = $status === 1 ? '已启用' : '已停用';
    jsonSuccess(['message' => $result['item']['name'] . ' 的账号' . $label]);
} catch (PrivilegedRoleConflictException $error) {
    jsonResponse(409, $error->getMessage(), null);
} catch (StaffLifecycleValidationException | PrivilegedRoleValidationException $error) {
    jsonResponse(400, $error->getMessage(), null);
} catch (Throwable $error) {
    error_log('[admin.staff-toggle-status] ' . $error->getMessage());
    jsonResponse(500, '员工账号状态更新失败', null);
}
