<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$phone = $argv[1] ?? '';
if ($phone === '') {
    fwrite(STDERR, "Usage: php check_admin_login_audit.php <phone>\n");
    exit(2);
}
$token = login($phone);
if ($token === '') {
    exit(1);
}

$response = requestJson('http://127.0.0.1/api/admin/security/login-audit.php', [
    'Host: 122.51.223.46',
    'Authorization: Bearer ' . $token,
]);

echo 'LOGIN_AUDIT_CODE=' . ($response['code'] ?? 'null') . PHP_EOL;
echo 'LOGIN_AUDIT_MESSAGE=' . ($response['message'] ?? '') . PHP_EOL;
echo 'LOGIN_AUDIT_LIST=' . count($response['data']['list'] ?? []) . PHP_EOL;

function login(string $phone): string
{
    $login = requestJson('http://127.0.0.1/api/auth-jwt.php', [
        'Host: 122.51.223.46',
        'Content-Type: application/json',
    ], json_encode(['username' => $phone, 'password' => '123456'], JSON_UNESCAPED_UNICODE));
    echo 'LOGIN_CODE=' . ($login['code'] ?? 'null') . PHP_EOL;
    echo 'LOGIN_MESSAGE=' . ($login['message'] ?? '') . PHP_EOL;
    return $login['data']['token'] ?? '';
}

function requestJson(string $url, array $headers, ?string $body = null): array
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
