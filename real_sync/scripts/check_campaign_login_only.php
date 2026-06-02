<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$phones = array_slice($argv, 1);
if (!$phones) {
    $phones = ['18744908578', '17279586191'];
}

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
        echo $phone . ' LOGIN_MESSAGE=' . ($login['message'] ?? '') . PHP_EOL;
        continue;
    }

    $summary = requestJson('http://127.0.0.1/api/campaign/summary.php', [
        'Host: 122.51.223.46',
        'Authorization: Bearer ' . $token,
    ], null);

    echo $phone . ' ROLES=' . implode(',', $summary['data']['auth']['allowedRoles'] ?? []) . PHP_EOL;
    echo $phone . ' CAN_EDIT_ALL=' . ((($summary['data']['auth']['canEditAllData'] ?? false) ? '1' : '0')) . PHP_EOL;
    echo $phone . ' SUMMARY_CODE=' . ($summary['code'] ?? 'null') . PHP_EOL;
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
