<?php

require_once __DIR__ . '/../api/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$phones = array_slice($argv, 1);
if (!$phones) {
    $phones = ['18744908578', '17279586191'];
}

$db = getDB();
$hash = wpHashPassword('123456');

foreach ($phones as $phone) {
    $stmt = $db->prepare('SELECT s.id,s.name,s.phone,s.user_id,u.ID AS wp_id FROM staffs s LEFT JOIN wp_users u ON u.ID=s.user_id WHERE s.phone=? LIMIT 1');
    $stmt->execute([$phone]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['wp_id'])) {
        echo $phone . ' => NOT_FOUND' . PHP_EOL;
        continue;
    }
    $db->prepare('UPDATE wp_users SET user_pass = ?, user_status = 0, user_activation_key = "" WHERE ID = ?')->execute([$hash, (int)$row['wp_id']]);
    echo $phone . ' => RESET_OK user_id=' . $row['wp_id'] . ' name=' . ($row['name'] ?? '') . PHP_EOL;
}

function wpHashPassword(string $password): string {
    $passwordToHash = base64_encode(hash_hmac('sha384', $password, 'wp-sha384', true));
    return '$wp' . password_hash($passwordToHash, PASSWORD_BCRYPT);
}
