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
    $newPassword = trim((string)($input['new_password'] ?? ''));
    if ($staffId <= 0) {
        jsonResponse(1, '缺少 staff_id');
    }
    if ($newPassword === '') {
        jsonResponse(1, '密码不能为空');
    }
    if (strlen($newPassword) < 6) {
        jsonResponse(1, '密码至少6位');
    }

    $stmt = $db->prepare('SELECT * FROM staffs WHERE id = ? LIMIT 1');
    $stmt->execute([$staffId]);
    $staffRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$staffRow) {
        jsonResponse(404, '员工不存在');
    }
    $userId = (int)($staffRow['user_id'] ?? 0);
    if ($userId <= 0) {
        jsonResponse(1, '该员工未绑定系统账号');
    }

    $beforeStmt = $db->prepare('SELECT ID, user_login, user_status FROM wp_users WHERE ID = ? LIMIT 1');
    $beforeStmt->execute([$userId]);
    $beforeUser = $beforeStmt->fetch(PDO::FETCH_ASSOC);
    if (!$beforeUser) {
        jsonResponse(404, '系统账号不存在');
    }

    $db->beginTransaction();
    try {
        $stmt = $db->prepare('UPDATE wp_users SET user_pass = ? WHERE ID = ?');
        $stmt->execute([adminPasswordHash($newPassword), $userId]);

        adminRecordOperation($db, $user, $operatorStaff, [
            'module' => 'staff',
            'action' => 'reset_password',
            'target_type' => 'staff',
            'target_id' => (string)$staffId,
            'before' => ['user_id' => $userId, 'user_login' => $beforeUser['user_login'] ?? null],
            'after' => ['user_id' => $userId, 'password_reset' => true],
        ]);

        $db->commit();
    } catch (Throwable $txe) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $txe;
    }

    recordLoginAudit([
        'user_id' => $userId,
        'staff_id' => $staffId,
        'login_type' => 'password_reset',
        'login_status' => 'success',
        'source' => 'admin',
        'message' => 'password_reset',
    ]);

    jsonResponse(0, 'success', ['staff_id' => $staffId, 'user_id' => $userId]);
} catch (Throwable $e) {
    error_log('[admin.staff.reset-password] ' . $e->getMessage());
    jsonResponse(1, '服务器错误');
}
