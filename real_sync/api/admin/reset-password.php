<?php
/**
 * Admin reset password API
 */
require_once __DIR__ . '/common.php';
handleCORS();

[, $user, $operatorStaff] = adminRequirePermission('staff.reset_password');

$input = adminJsonInput();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(1, '仅支持 POST 请求');
}
$db = getDB();

$targetUserId = (int)($input['user_id'] ?? 0);
if (!$targetUserId) {
    jsonResponse(1, '请指定用户ID');
}

$defaultPassword = PasswordPolicy::generate();
$hash = adminPasswordHash($defaultPassword);

$db->beginTransaction();
try {
    $stmt = $db->prepare('SELECT ID FROM wp_users WHERE ID = ? FOR UPDATE');
    $stmt->execute([$targetUserId]);
    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        throw new RuntimeException('user does not exist');
    }
    $staffStmt = $db->prepare('SELECT id, session_version FROM staffs WHERE user_id = ? FOR UPDATE');
    $staffStmt->execute([$targetUserId]);
    $staff = $staffStmt->fetch(PDO::FETCH_ASSOC);
    if (!$staff) {
        throw new RuntimeException('staff does not exist');
    }
    $db->prepare('UPDATE wp_users SET user_pass = ? WHERE ID = ?')->execute([$hash, $targetUserId]);
    $db->prepare('UPDATE staffs SET session_version = session_version + 1, updated_at = NOW() WHERE id = ?')->execute([(int)$staff['id']]);
    adminRecordOperation($db, $user, $operatorStaff, [
        'module' => 'staff',
        'action' => 'reset_password',
        'target_type' => 'wp_user',
        'target_id' => (string)$targetUserId,
        'after' => ['password_reset' => true, 'session_version' => (int)$staff['session_version'] + 1],
    ]);
    $db->commit();
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    jsonResponse(500, '密码重置失败');
}

jsonSuccess([
    'default_password' => $defaultPassword,
    'message' => '密码已重置为随机密码',
]);
