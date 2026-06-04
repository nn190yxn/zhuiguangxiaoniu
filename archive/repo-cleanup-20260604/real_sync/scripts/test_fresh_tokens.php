<?php
if (PHP_SAPI !== 'cli') { exit; }

// Get fresh tokens by logging in
$coach = '18385534850';
$hq = '13668501068';

// Login for coach
$ctx = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Host: 122.51.223.46\r\nContent-Type: application/json\r\n",
        'content' => json_encode(['username' => $coach, 'password' => '123456']),
        'ignore_errors' => true
    ]
]);
$response = file_get_contents("http://127.0.0.1/api/auth-jwt.php", false, $ctx);
$data = json_decode($response, true);
$cToken = $data['data']['token'] ?? '';

// Login for HQ
$ctx2 = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Host: 122.51.223.46\r\nContent-Type: application/json\r\n",
        'content' => json_encode(['username' => $hq, 'password' => '123456']),
        'ignore_errors' => true
    ]
]);
$response2 = file_get_contents("http://127.0.0.1/api/auth-jwt.php", false, $ctx2);
$data2 = json_decode($response2, true);
$hToken = $data2['data']['token'] ?? '';

if (!$cToken || !$hToken) {
    echo "Login failed\n";
    exit(1);
}

echo "Coach Token: " . substr($cToken, 0, 20) . "...\n";
echo "HQ Token: " . substr($hToken, 0, 20) . "...\n";

// Test audit list with HQ token
$ctx3 = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => "Host: 122.51.223.46\r\nAuthorization: Bearer $hToken\r\n",
        'ignore_errors' => true
    ]
]);
$response3 = file_get_contents("http://127.0.0.1/api/workload/audit-list.php", false, $ctx3);
echo "Audit List Response: " . $response3 . "\n";