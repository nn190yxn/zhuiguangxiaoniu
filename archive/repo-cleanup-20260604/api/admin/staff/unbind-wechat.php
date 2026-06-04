<?php
require_once dirname(__DIR__) . '/common.php';

header('Content-Type: application/json');

try {
    $db = getDB();
    [, $user, $operatorStaff] = adminRequireAuth(static fn($user, $staff) => isSuperAdminUser($user));
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(1, '不支持的请求方法');
    }

    $input = adminJsonInput();
    $staffId = max(0, (int)($input['staff_id'] ?? 0));
    if ($staffId <= 0) {
        jsonResponse(1, '缺少 staff_id');
    }
    if (!adminColumnExists($db, 'staffs', 'openid')) {
        jsonResponse(0, 'success', ['supported' => false, 'message' => '当前 staffs 表未落 openid 字段，已跳过解绑']);
    }

    $stmt = $db->prepare('SELECT * FROM staffs WHERE id = ? LIMIT 1');
    $stmt->execute([$staffId]);
    $before = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$before) {
        jsonResponse(404, '员工不存在');
    }

    $fields = ['openid = NULL'];
    if (adminColumnExists($db, 'staffs', 'openid_bound_at')) {
        $fields[] = 'openid_bound_at = NULL';
    }
    $stmt = $db->prepare('UPDATE staffs SET ' . implode(', ', $fields) . ' WHERE id = ?');
    $stmt->execute([$staffId]);

    $stmt = $db->prepare('SELECT * FROM staffs WHERE id = ? LIMIT 1');
    $stmt->execute([$staffId]);
    $after = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    adminRecordOperation($db, $user, $operatorStaff, [
        'module' => 'staff',
        'action' => 'unbind_wechat',
        'target_type' => 'staff',
        'target_id' => (string)$staffId,
        'before' => $before,
        'after' => $after,
    ]);

    jsonResponse(0, 'success', ['supported' => true, 'item' => $after]);
} catch (Throwable $e) {
    error_log('[admin.staff.unbind-wechat] ' . $e->getMessage());
    jsonResponse(1, '服务器错误');
}
