<?php
// Test headquarters user access
if (PHP_SAPI !== 'cli') { exit; }

$hqPhone = '13668501068';
$password = '123456';
$date = date('Y-m-d');

$loginCtx = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Host: 122.51.223.46\r\nContent-Type: application/json\r\n",
        'content' => json_encode(['username' => $hqPhone, 'password' => $password]),
        'ignore_errors' => true
    ]
]);
$loginResponse = file_get_contents("http://127.0.0.1/api/auth-jwt.php", false, $loginCtx);
$loginData = json_decode($loginResponse, true);
$token = $loginData['data']['token'] ?? '';

if (!$token) {
    echo "HQ Login failed: " . $loginResponse . "\n";
    exit(1);
}

echo "HQ Login successful\n";

$summaryCtx = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => "Host: 122.51.223.46\r\nAuthorization: Bearer $token\r\n",
        'ignore_errors' => true
    ]
]);
$summaryResponse = file_get_contents("http://127.0.0.1/api/workload/hq-summary.php?date_from=$date&date_to=$date", false, $summaryCtx);
$summaryData = json_decode($summaryResponse, true);
echo "HQ Summary access: " . ($summaryData['code'] === 0 ? 'SUCCESS' : 'FAILED - ' . $summaryData['message']) . "\n";