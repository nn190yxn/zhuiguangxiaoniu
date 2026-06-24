<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/admin/common.php';
require_once __DIR__ . '/_common.php';

header('Content-Type: application/json; charset=utf-8');
handleCORS();
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'OPTIONS') {
    http_response_code(200);
    exit;
}

[$userId, $user, $staff] = adminRequireAuth('adminCanAccessHeadquarter');
$pdo = wecomDb();

try {
    $input = getRequestInput();
    $rootDepartmentId = isset($_GET['department_id']) ? (int)$_GET['department_id'] : (isset($input['department_id']) ? (int)$input['department_id'] : wecomRootDepartmentId());
    $result = wecomSyncMembers($pdo, ['root_department_id' => $rootDepartmentId]);
    $logId = wecomWriteSyncLog($pdo, [
        'sync_type' => 'members',
        'status' => 'success',
        'operator_user_id' => $userId,
        'operator_staff_id' => isset($staff['id']) ? (int)$staff['id'] : null,
        'departments_total' => $result['departments_total'],
        'users_total' => $result['users_total'],
        'matched_total' => $result['matched_total'],
        'updated_total' => $result['updated_total'],
        'unbound_total' => $result['unbound_total'],
        'deactivated_total' => $result['deactivated_total'],
        'payload' => $result,
    ]);

    json_response(0, 'success', [
        'log_id' => $logId,
        'result' => $result,
    ]);
} catch (Throwable $e) {
    wecomWriteSyncLog($pdo, [
        'sync_type' => 'members',
        'status' => 'failed',
        'operator_user_id' => $userId,
        'operator_staff_id' => isset($staff['id']) ? (int)$staff['id'] : null,
        'error_message' => $e->getMessage(),
    ]);
    json_response(500, '企业微信成员同步失败：' . $e->getMessage());
}
