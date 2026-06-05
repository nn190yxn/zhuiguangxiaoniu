<?php
/**
 * Admin reset password API
 */
require_once __DIR__ . '/common.php';
handleCORS();

[, $user, $operatorStaff] = adminRequireAuth(static fn($u, $s) => isSuperAdminUser($u, $s));

$input = adminJsonInput();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(1, '仅支持 POST 请求');
}
$db = getDB();

$targetUserId = (int)($input['user_id'] ?? 0);
if (!$targetUserId) {
    jsonResponse(1, '请指定用户ID');
}

$stmt = $db->prepare("SELECT ID FROM wp_users WHERE ID = ?");
$stmt->execute([$targetUserId]);
$wpUser = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$wpUser) {
    jsonResponse(1, '用户不存在');
}

$defaultPassword = bin2hex(random_bytes(4));
$hash = adminPasswordHash($defaultPassword);

$stmt = $db->prepare("UPDATE wp_users SET user_pass = ? WHERE ID = ?");
$stmt->execute([$hash, $targetUserId]);

adminRecordOperation($db, $user, $operatorStaff, [
    'module' => 'staff',
    'action' => 'reset_password',
    'target_type' => 'wp_user',
    'target_id' => (string)$targetUserId,
]);

jsonSuccess([
    'default_password' => $defaultPassword,
    'message' => '密码已重置为随机密码',
]);

jsonSuccess([
    'message' => '密码已重置，请将新密码告知该员工',
    'default_password' => $defaultPassword,
]);
