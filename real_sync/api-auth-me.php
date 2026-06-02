<?php
/**
 * Get current user info including role (for admin check)
 */
require_once __DIR__ . '/../config.php';
handleCORS();
$userId = getCurrentUserId();
if (!$userId) {
    jsonError(401, '未登录');
}
$db = getDB();
$stmt = $db->prepare("SELECT ID as user_id, user_login, display_name FROM wp_users WHERE ID = ?");
$stmt->execute([$userId]);
$wpUser = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$wpUser) {
    jsonError(404, '用户不存在');
}
$staff = getStaffByUserId($userId);
$role = 'staff';
if ($staff && !empty($staff['role'])) {
    $role = normalizeStaffRoleCode($staff['role']);
}
$isManager = in_array($role, ['admin', 'manager'], true);

// Check if user needs to change password (first login)
$mustChangePassword = false;
if ($wpUser['user_login']) {
    $loginLower = strtolower($wpUser['user_login']);
    $phonePattern = '/^1[3-9]\d{9}$/';
    // If username is a phone number and password is still default
    $stmt2 = $db->prepare("SELECT user_pass FROM wp_users WHERE ID = ?");
    $stmt2->execute([$userId]);
    $passHash = $stmt2->fetchColumn();
    if (preg_match($phonePattern, $loginLower) && $passHash) {
        if (wp_check_password('123456', $passHash, $userId)) {
            $mustChangePassword = true;
        }
    }
}

jsonSuccess([
    'user_id' => (int)$wpUser['user_id'],
    'username' => $wpUser['user_login'],
    'display_name' => $wpUser['display_name'],
    'role' => $role,
    'is_manager' => $isManager,
    'must_change_password' => $mustChangePassword,
    'staff' => $staff ? [
        'id' => (int)$staff['id'],
        'name' => $staff['name'],
        'role' => $staff['role'],
        'phone' => $staff['phone'],
        'store_id' => (int)$staff['store_id'],
    ] : null,
]);

function wp_check_password($password, $hash, $user_id = 0) {
    if (str_starts_with((string)$hash, '$wp')) {
        $passwordToVerify = base64_encode(hash_hmac('sha384', $password, 'wp-sha384', true));
        return password_verify($passwordToVerify, substr($hash, 3));
    }

    if (str_starts_with((string)$hash, '$2y$') || str_starts_with((string)$hash, '$2a$') || str_starts_with((string)$hash, '$argon2')) {
        return password_verify($password, $hash);
    }

    $check = password_verify($password, $hash);
    if (!$check) {
        $check = (md5($password) === $hash);
    }
    return $check;
}
