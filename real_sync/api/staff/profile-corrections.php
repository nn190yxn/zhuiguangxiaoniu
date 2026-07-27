<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/admin/common.php';
require_once dirname(__DIR__) . '/admin/services/StaffProfileService.php';

header('Content-Type: application/json; charset=utf-8');
handleCORS();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
$userId = getCurrentUserId();
if (!$userId) {
    jsonError(401, '请先登录');
}
$user = getJwtCurrentUser() ?: ['user_id' => $userId];
$staff = getStaffByUserId($userId);
if (!$staff) {
    jsonError(404, '员工档案不存在');
}

try {
    $service = new StaffProfileService(getDB());
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        jsonSuccess(['list' => $service->correctionsForStaff((int)$staff['id'])]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonError(405, '仅支持 GET 或 POST 请求');
    }
    $input = adminJsonInput();
    $item = $service->submit(
        (int)$staff['id'],
        is_array($input['changes'] ?? null) ? $input['changes'] : [],
        (string)($input['request_reason'] ?? $input['reason'] ?? ''),
        $user,
        $staff
    );
    jsonSuccess(['item' => $item]);
} catch (InvalidArgumentException $error) {
    jsonError(400, $error->getMessage());
} catch (Throwable $error) {
    error_log('[staff.profile-corrections] ' . $error->getMessage());
    jsonError(500, '档案更正申请处理失败');
}
