<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/common.php';
require_once dirname(__DIR__) . '/services/StaffRosterSyncService.php';

header('Content-Type: application/json; charset=utf-8');
handleCORS();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, '仅支持 POST 请求');
}

try {
    [$userId, $user, $operatorStaff] = adminRequirePermission('staff.edit');
    $input = adminJsonInput();
    $records = $input['records'] ?? $input;
    if (!is_array($records)) {
        throw new InvalidArgumentException('同步记录格式无效');
    }
    $result = (new StaffRosterSyncService(getDB()))->sync(
        array_values($records),
        is_array($user) ? $user : ['user_id' => (int)$userId],
        $operatorStaff ?: []
    );
    jsonResponse(0, '花名册同步完成', $result);
} catch (InvalidArgumentException $error) {
    jsonResponse(400, $error->getMessage());
} catch (Throwable $error) {
    error_log('[admin.staff.roster-sync] ' . $error->getMessage());
    jsonResponse(500, '花名册同步失败');
}
