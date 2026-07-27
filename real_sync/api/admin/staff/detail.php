<?php
require_once dirname(__DIR__) . '/common.php';
require_once dirname(__DIR__) . '/services/StaffDirectoryService.php';

header('Content-Type: application/json');

try {
    $db = getDB();
    [, $user, $operatorStaff] = adminRequirePermission('staff.view_all');

    $staffId = max(0, (int)($_GET['staff_id'] ?? 0));
    if ($staffId <= 0) {
        jsonResponse(1, '缺少 staff_id');
    }

    $roles = appRoleTokensFromUser($user, $operatorStaff);
    $canViewSensitive = (bool)array_intersect(['operation', 'admin'], $roles);
    $service = new StaffDirectoryService($db, $canViewSensitive);
    $detail = $service->detail($staffId);
    if ($detail === null) {
        jsonResponse(404, '员工不存在');
    }
    $devices = $detail['devices'];
    $detail['device_stats'] = [
        'total_devices' => count($devices),
        'trusted_devices' => count(array_filter($devices, static fn($row) => (int)($row['is_trusted'] ?? 0) === 1)),
        'recent_login' => $devices[0]['last_login'] ?? null,
    ];
    jsonResponse(0, 'success', $detail);
} catch (Throwable $e) {
    error_log('[admin.staff.detail] ' . $e->getMessage());
    jsonResponse(1, '服务器错误');
}
