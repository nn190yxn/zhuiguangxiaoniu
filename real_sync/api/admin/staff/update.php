<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/common.php';
require_once dirname(__DIR__) . '/services/OrganizationService.php';
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
    [$userId, $user, $operatorStaff] = adminRequirePermission('staff.edit');
    $operatorUser = is_array($user) ? $user : ['user_id' => (int)$userId];
    $input = adminJsonInput();
    $staffId = (int)($input['staff_id'] ?? $input['id'] ?? 0);

    $service = new StaffLifecycleService(getDB());
    $result = $service->update($staffId, $input, $operatorUser, $operatorStaff ?: []);
    jsonResponse(0, '员工资料更新成功', $result);
} catch (StaffIdentityConflictException $error) {
    jsonResponse(409, $error->getMessage(), [
        'conflict_fields' => $error->conflictFields(),
        'profiles' => $error->profiles(),
    ]);
} catch (OrganizationAssignmentConflictException $error) {
    jsonResponse(409, $error->getMessage(), [
        'conflicting_assignments' => $error->conflictingAssignments(),
    ]);
} catch (PrivilegedRoleConflictException $error) {
    jsonResponse(409, $error->getMessage(), null);
} catch (OrganizationAssignmentValidationException | StaffLifecycleValidationException | PrivilegedRoleValidationException $error) {
    jsonResponse(400, $error->getMessage(), null);
} catch (Throwable $error) {
    error_log('[admin.staff.update] ' . $error->getMessage());
    jsonResponse(500, '员工资料更新失败', null);
}
