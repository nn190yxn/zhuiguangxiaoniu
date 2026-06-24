<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/admin/common.php';
require_once __DIR__ . '/_common.php';

header('Content-Type: application/json; charset=utf-8');
handleCORS();
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'POST')) === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $db = wecomDb();
    [, $user, $operatorStaff] = adminRequireAuth(static fn($user, $staff) => isSuperAdminUser($user));
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        jsonResponse(1, '不支持的请求方法');
    }

    $input = adminJsonInput();
    $staffId = max(0, (int)($input['staff_id'] ?? 0));
    if ($staffId <= 0) {
        jsonResponse(1, '缺少 staff_id');
    }
    if (trim((string)($input['userid'] ?? '')) === '') {
        jsonResponse(1, '缺少企业微信 userid');
    }

    $db->beginTransaction();
    try {
        $result = wecomBindStaffManually($db, $staffId, [
            'userid' => $input['userid'] ?? '',
            'name' => $input['name'] ?? '',
            'mobile' => $input['mobile'] ?? '',
            'department_id' => $input['department_id'] ?? '',
            'department_path' => $input['department_path'] ?? '',
            'status' => isset($input['status']) ? (int)$input['status'] : 1,
        ]);

        adminRecordOperation($db, $user, $operatorStaff, [
            'module' => 'wecom',
            'action' => 'bind_member',
            'target_type' => 'staff',
            'target_id' => (string)$staffId,
            'before' => $result['before'],
            'after' => $result['after'],
        ]);

        $db->commit();
    } catch (Throwable $txe) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $txe;
    }

    jsonResponse(0, 'success', ['item' => $result['after']]);
} catch (Throwable $e) {
    error_log('[wecom.bind-member] ' . $e->getMessage());
    jsonResponse(1, $e->getMessage() !== '' ? $e->getMessage() : '服务器错误');
}
