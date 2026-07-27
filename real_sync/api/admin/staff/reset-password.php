<?php
require_once dirname(__DIR__) . '/common.php';

header('Content-Type: application/json');

try {
    $db = getDB();
    [, $user, $operatorStaff] = adminRequirePermission('staff.reset_password');
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
    PasswordPolicy::validate($newPassword);

    $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT id, user_id, session_version FROM staffs WHERE id = ? FOR UPDATE');
        $stmt->execute([$staffId]);
        $staffRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$staffRow) {
            throw new RuntimeException('staff does not exist');
        }
        $userId = (int)($staffRow['user_id'] ?? 0);
        $beforeStmt = $db->prepare('SELECT ID, user_login, user_status FROM wp_users WHERE ID = ? FOR UPDATE');
        $beforeStmt->execute([$userId]);
        $beforeUser = $beforeStmt->fetch(PDO::FETCH_ASSOC);
        if (!$beforeUser) {
            throw new RuntimeException('linked account does not exist');
        }
        $stmt = $db->prepare('UPDATE wp_users SET user_pass = ? WHERE ID = ?');
        $stmt->execute([adminPasswordHash($newPassword), $userId]);
        $db->prepare('UPDATE staffs SET session_version = session_version + 1, updated_at = NOW() WHERE id = ?')->execute([$staffId]);

        adminRecordOperation($db, $user, $operatorStaff, [
            'module' => 'staff',
            'action' => 'reset_password',
            'target_type' => 'staff',
            'target_id' => (string)$staffId,
            'before' => ['user_id' => $userId, 'user_login' => $beforeUser['user_login'] ?? null],
            'after' => [
                'user_id' => $userId,
                'password_reset' => true,
                'session_version' => (int)$staffRow['session_version'] + 1,
            ],
        ]);

        $db->commit();
    } catch (Throwable $txe) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $txe;
    }

    adminRecordLoginAudit($db, [
        'user_id' => $userId,
        'staff_id' => $staffId,
        'login_type' => 'password_reset',
        'login_status' => 'success',
        'source' => 'admin',
        'message' => 'password_reset',
    ]);

    jsonResponse(0, 'success', ['staff_id' => $staffId, 'user_id' => $userId]);
} catch (PasswordPolicyValidationException $e) {
    jsonResponse(400, $e->getMessage());
} catch (Throwable $e) {
    error_log('[admin.staff.reset-password] ' . $e->getMessage());
    jsonResponse(1, '服务器错误');
}
