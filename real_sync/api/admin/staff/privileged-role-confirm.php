<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/common.php';
require_once dirname(__DIR__) . '/services/PrivilegedRoleGuard.php';

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
    [, $approverUser, $approverStaff] = adminRequirePermission('role.manage_privileged');
    $result = (new PrivilegedRoleGuard(getDB()))->issueConfirmation(
        adminJsonInput(),
        $approverUser ?: [],
        $approverStaff ?: []
    );
    jsonResponse(0, '高权限角色变更确认已签发', $result);
} catch (PrivilegedRoleValidationException $error) {
    jsonResponse(400, $error->getMessage(), null);
} catch (Throwable $error) {
    error_log('[admin.staff.privileged-role-confirm] ' . $error->getMessage());
    jsonResponse(500, '高权限角色变更确认失败', null);
}
