<?php
/**
 * Auth: change password API
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/common/PasswordPolicy.php';
handleCORS();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError(405, 'Method not allowed');
}
$userId = getCurrentUserId();
if (!$userId) {
    jsonError(401, '请先登录');
}
$currentUser = getJwtCurrentUser();
$input = getRequestInput();
$oldPassword = trim($input['old_password'] ?? '');
$newPassword = trim($input['new_password'] ?? '');

if (!$oldPassword || !$newPassword) {
    jsonError(400, '请填写完整密码信息');
}
try {
    PasswordPolicy::validate($newPassword);
} catch (PasswordPolicyValidationException $error) {
    jsonError(400, $error->getMessage());
}

$db = getDB();

// Verify old password via WordPress
$stmt = $db->prepare("SELECT user_pass, user_login FROM wp_users WHERE ID = ?");
$stmt->execute([$userId]);
$wpUser = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$wpUser) {
    jsonError(404, '用户不存在');
}

// WordPress password verification（统一实现见 config.php wp_verify_password）
if (!wp_verify_password($oldPassword, $wpUser['user_pass'], (int)$userId)) {
    jsonError(400, '旧密码不正确');
}

$db->beginTransaction();
try {
    $stmt = $db->prepare('SELECT id, session_version FROM staffs WHERE user_id = ? FOR UPDATE');
    $stmt->execute([$userId]);
    $staff = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$staff) {
        throw new RuntimeException('员工档案不存在');
    }
    $stmt = $db->prepare('SELECT user_login FROM wp_users WHERE ID = ? FOR UPDATE');
    $stmt->execute([$userId]);
    $username = (string)$stmt->fetchColumn();
    $hash = PasswordPolicy::hash($newPassword);
    $db->prepare("UPDATE wp_users SET user_pass = ?, user_activation_key = '' WHERE ID = ?")->execute([$hash, $userId]);
    $db->prepare('UPDATE staffs SET session_version = session_version + 1, updated_at = NOW() WHERE id = ?')->execute([(int)$staff['id']]);
    $db->commit();
    $replacementToken = generate_jwt($userId, $username, (string)($currentUser['role'] ?? 'staff'));
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    jsonError(500, '密码修改失败');
}

jsonSuccess(['message' => '密码修改成功', 'token' => $replacementToken, 'expire' => JWT_EXPIRE]);
