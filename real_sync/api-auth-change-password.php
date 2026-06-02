<?php
/**
 * Auth: change password API
 */
require_once __DIR__ . '/config.php';
handleCORS();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError(405, 'Method not allowed');
}
$userId = getCurrentUserId();
if (!$userId) {
    jsonError(401, '请先登录');
}
$input = getRequestInput();
$oldPassword = trim($input['old_password'] ?? '');
$newPassword = trim($input['new_password'] ?? '');

if (!$oldPassword || !$newPassword) {
    jsonError(400, '请填写完整密码信息');
}
if (strlen($newPassword) < 6) {
    jsonError(400, '新密码至少6个字符');
}

$db = getDB();

// Verify old password via WordPress
$stmt = $db->prepare("SELECT user_pass, user_login FROM wp_users WHERE ID = ?");
$stmt->execute([$userId]);
$wpUser = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$wpUser) {
    jsonError(404, '用户不存在');
}

// WordPress password verification
if (!wp_check_password($oldPassword, $wpUser['user_pass'], $userId)) {
    jsonError(400, '旧密码不正确');
}

// Update password
$hash = wpHashPassword($newPassword);
$stmt = $db->prepare("UPDATE wp_users SET user_pass = ?, user_activation_key = '' WHERE ID = ?");
$stmt->execute([$hash, $userId]);

jsonSuccess(['message' => '密码修改成功']);

/**
 * WordPress password check - portable implementation
 */
function wp_check_password($password, $hash, $user_id = 0) {
    // Support $wp$ prefixed hashes (our custom format)
    if (str_starts_with((string)$hash, '$wp')) {
        $passwordToVerify = base64_encode(hash_hmac('sha384', $password, 'wp-sha384', true));
        $check = password_verify($passwordToVerify, substr($hash, 3));
    } elseif (str_starts_with((string)$hash, '$2y$') || str_starts_with((string)$hash, '$2a$') || str_starts_with((string)$hash, '$argon2')) {
        $check = password_verify($password, $hash);
    } else {
        $check = password_verify($password, $hash);
        if (!$check) {
            $check = (md5($password) === $hash);
        }
    }
    if ($check && strlen($hash) <= 32) {
        // Upgrade old MD5 hash to new format
        $newHash = wpHashPassword($password);
        if (function_exists('getDB')) {
            $db = getDB();
            $db->prepare("UPDATE wp_users SET user_pass = ? WHERE ID = ?")->execute([$newHash, $user_id]);
        }
    }
    return $check;
}

function wpHashPassword($password) {
    $passwordToHash = base64_encode(hash_hmac('sha384', $password, 'wp-sha384', true));
    return '$wp' . password_hash($passwordToHash, PASSWORD_BCRYPT);
}
