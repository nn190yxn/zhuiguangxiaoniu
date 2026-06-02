<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$phone = $argv[1] ?? '';
if ($phone === '') {
    fwrite(STDERR, "Usage: php check_common_context.php <phone>\n");
    exit(2);
}
$password = $argv[2] ?? '123456';

$login = requestJson('http://127.0.0.1/api/auth-jwt.php', [
    'Host: 122.51.223.46',
    'Content-Type: application/json',
], json_encode(['username' => $phone, 'password' => $password], JSON_UNESCAPED_UNICODE));

$token = $login['data']['token'] ?? '';
echo $phone . ' LOGIN=' . ($login['code'] ?? 'null') . PHP_EOL;
if ($token === '') {
    echo $phone . ' LOGIN_MESSAGE=' . ($login['message'] ?? '') . PHP_EOL;
    exit;
}

$context = requestJson('http://127.0.0.1/api/common/context-test.php', [
    'Host: 122.51.223.46',
    'Authorization: Bearer ' . $token,
], null);

echo $phone . ' CONTEXT_CODE=' . ($context['code'] ?? 'null') . PHP_EOL;
$ctx = $context['data']['context'] ?? [];
echo $phone . ' ROLE=' . ($ctx['role'] ?? '') . PHP_EOL;
echo $phone . ' STORE=' . ($ctx['store_name'] ?? '') . PHP_EOL;
echo $phone . ' CAN_EDIT_OWN=' . (!empty($ctx['permissions']['can_edit_own']) ? '1' : '0') . PHP_EOL;
$checks = $context['data']['permission_checks'] ?? [];
foreach (['can_view_own_store', 'can_edit_own_store', 'can_operate_self', 'can_view_all', 'can_edit_all'] as $key) {
    echo $phone . ' ' . strtoupper($key) . '=' . (!empty($checks[$key]) ? '1' : '0') . PHP_EOL;
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
