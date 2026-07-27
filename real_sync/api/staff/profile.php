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
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError(405, '仅支持 GET 请求');
}
$userId = getCurrentUserId();
if (!$userId) {
    jsonError(401, '请先登录');
}
$staff = getStaffByUserId($userId);
if (!$staff) {
    jsonError(404, '员工档案不存在');
}

try {
    $item = (new StaffProfileService(getDB()))->profile((int)$staff['id']);
    jsonSuccess(['item' => $item]);
} catch (Throwable $error) {
    error_log('[staff.profile] ' . $error->getMessage());
    jsonError(500, '本人档案读取失败');
}
