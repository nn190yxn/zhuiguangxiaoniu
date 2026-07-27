<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/common.php';
require_once dirname(__DIR__) . '/services/StaffDirectoryService.php';

header('Content-Type: application/json; charset=utf-8');
handleCORS();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(1, '仅支持 GET 请求');
}

try {
    [, $user, $staff] = adminRequirePermission('staff.view_all');
    $roles = appRoleTokensFromUser($user, $staff);
    $canViewSensitive = (bool)array_intersect(['operation', 'admin'], $roles);
    $service = new StaffDirectoryService(getDB(), $canViewSensitive);
    jsonResponse(0, 'success', $service->list($_GET));
} catch (Throwable $error) {
    error_log('[admin.staff.list] ' . $error->getMessage());
    jsonResponse(1, '员工列表加载失败');
}
