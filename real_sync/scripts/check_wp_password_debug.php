<?php

require_once __DIR__ . '/../api/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$phone = $argv[1] ?? '18744908578';
$password = $argv[2] ?? '123456';

$db = getDB();
$stmt = $db->prepare('SELECT ID,user_login,user_pass,user_status FROM wp_users WHERE user_login=? LIMIT 1');
$stmt->execute([$phone]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
echo json_encode([
    'user' => $user ? [
        'ID' => $user['ID'],
        'user_login' => $user['user_login'],
        'user_status' => $user['user_status'],
        'pass_prefix' => substr((string)$user['user_pass'], 0, 10),
        'pass_len' => strlen((string)$user['user_pass']),
    ] : null,
    'verify_123456' => $user ? wp_check_password_local($password, (string)$user['user_pass']) : null,
], JSON_UNESCAPED_UNICODE) . PHP_EOL;

function wp_check_password_local(string $password, string $hash): bool {
    if (str_starts_with($hash, '$wp')) {
        $passwordToVerify = base64_encode(hash_hmac('sha384', $password, 'wp-sha384', true));
        return password_verify($passwordToVerify, substr($hash, 3));
    }
    return password_verify($password, $hash);
}
