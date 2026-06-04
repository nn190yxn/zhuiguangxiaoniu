<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$base = 'http://127.0.0.1/api';

$accounts = [
    'sales' => '18185025361',
    'coach' => '15121622579',
];

foreach ($accounts as $label => $username) {
    $login = postJson($base . '/auth-jwt.php', [
        'username' => $username,
        'password' => '123456',
    ]);

    $token = $login['data']['token'] ?? '';
    echo strtoupper($label) . '_LOGIN=' . ($login['code'] ?? 'null') . PHP_EOL;
    if ($token === '') {
        continue;
    }

    $modules = getJson($base . '/drill/training-modules.php', $token);
    $moduleNames = [];
    foreach (($modules['data']['modules'] ?? []) as $module) {
        $moduleNames[] = $module['module_name'] ?? $module['title'] ?? '';
    }
    echo strtoupper($label) . '_MODULE_COUNT=' . count($moduleNames) . PHP_EOL;
    echo strtoupper($label) . '_MODULES=' . implode(' | ', array_slice($moduleNames, 0, 5)) . PHP_EOL;
}

$salesLogin = postJson($base . '/auth-jwt.php', [
    'username' => $accounts['sales'],
    'password' => '123456',
]);
$salesToken = $salesLogin['data']['token'] ?? '';
if ($salesToken !== '') {
    $map = getJson($base . '/pass/map.php', $salesToken);
    echo 'SALES_PASS_ROLE=' . (($map['data']['role'] ?? '')) . PHP_EOL;
    echo 'SALES_PASS_STAGE_COUNT=' . count($map['data']['stages'] ?? []) . PHP_EOL;
}

function postJson(string $url, array $payload): array {
    return requestJson($url, [
        'Host: 122.51.223.46',
        'Content-Type: application/json',
    ], json_encode($payload, JSON_UNESCAPED_UNICODE));
}

function getJson(string $url, string $token): array {
    return requestJson($url, [
        'Host: 122.51.223.46',
        'Authorization: Bearer ' . $token,
    ], null);
}

function requestJson(string $url, array $headers, ?string $body): array {
    $options = [
        'http' => [
            'method' => $body === null ? 'GET' : 'POST',
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
            'timeout' => 10,
        ],
    ];
    if ($body !== null) {
        $options['http']['content'] = $body;
    }
    $response = file_get_contents($url, false, stream_context_create($options));
    return is_string($response) ? (json_decode($response, true) ?: []) : [];
}
