<?php
// Test actual operation user context
if (PHP_SAPI !== 'cli') { exit; }

$operationPhone = '18285031172';
$password = '123456';

$loginCtx = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Host: 122.51.223.46\r\nContent-Type: application/json\r\n",
        'content' => json_encode(['username' => $operationPhone, 'password' => $password]),
        'ignore_errors' => true
    ]
]);
$loginResponse = file_get_contents("http://127.0.0.1/api/auth-jwt.php", false, $loginCtx);
echo "Login response: " . $loginResponse . "\n";