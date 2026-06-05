<?php
/**
 * Get current user info including role (for admin check)
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../common/context.php';
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
$context = appGetCurrentStaffContext();
$role = (string)($context['role'] ?? 'staff');
$isManager = !empty($context['is_manager']) || !empty($context['is_admin']);

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
        // Check against $wp$ format or standard bcrypt
        if (str_starts_with($passHash, '$wp')) {
            $defaultToHash = base64_encode(hash_hmac('sha384', '123456', 'wp-sha384', true));
            if (password_verify($defaultToHash, substr($passHash, 3))) {
                $mustChangePassword = true;
            }
        } elseif (password_verify('123456', $passHash)) {
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
    'is_admin' => !empty($context['is_admin']),
    'is_hq' => !empty($context['is_hq']),
    'permissions' => $context['permissions'] ?? [],
    'must_change_password' => $mustChangePassword,
    'staff' => $staff ? [
        'id' => (int)$staff['id'],
        'name' => $staff['name'],
        'role' => $staff['role'],
        'phone' => $staff['phone'],
        'store_id' => (int)$staff['store_id'],
    ] : null,
]);
