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
foreach ($phones as $phone) {
    $stmt = $db->prepare('SELECT s.id,s.name,s.phone,s.role,s.store_id,s.user_id,s.status,u.user_login,u.user_status FROM staffs s LEFT JOIN wp_users u ON u.ID=s.user_id WHERE s.phone=? LIMIT 1');
    $stmt->execute([$phone]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo $phone . ' => ' . json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

echo '--- wp_users duplicate check ---' . PHP_EOL;
foreach ($phones as $phone) {
    $stmt = $db->prepare('SELECT ID,user_login,user_email,user_status,LENGTH(user_pass) AS pass_len,LEFT(user_pass,10) AS pass_prefix FROM wp_users WHERE user_login=? OR user_email=? ORDER BY ID');
    $stmt->execute([$phone, $phone . '@staff.local']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo $phone . ' USERS=' . json_encode($rows, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

echo '--- login + campaign auth ---' . PHP_EOL;
foreach ($phones as $phone) {
    $login = requestJson('http://127.0.0.1/api/auth-jwt.php', [
        'Host: 122.51.223.46',
        'Content-Type: application/json',
    ], json_encode([
        'username' => $phone,
        'password' => '123456',
    ], JSON_UNESCAPED_UNICODE));
    $token = $login['data']['token'] ?? '';
    echo $phone . ' LOGIN=' . ($login['code'] ?? 'null') . PHP_EOL;
    if ($token === '') {
        continue;
    }
    $summary = requestJson('http://127.0.0.1/api/campaign/summary.php', [
        'Host: 122.51.223.46',
        'Authorization: Bearer ' . $token,
    ], null);
    echo $phone . ' ROLES=' . implode(',', $summary['data']['auth']['allowedRoles'] ?? []) . PHP_EOL;
    echo $phone . ' CAN_EDIT_ALL=' . ((($summary['data']['auth']['canEditAllData'] ?? false) ? '1' : '0')) . PHP_EOL;
}

function requestJson(string $url, array $headers, ?string $body): array
{
    $options = [
        'http' => [
            'method' => $body === null ? 'GET' : 'POST',
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
            'timeout' => 15,
        ],
    ];

    if ($body !== null) {
        $options['http']['content'] = $body;
    }

    $response = file_get_contents($url, false, stream_context_create($options));
    return is_string($response) ? (json_decode($response, true) ?: []) : [];
}
