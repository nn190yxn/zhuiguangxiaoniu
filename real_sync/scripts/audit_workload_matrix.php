<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$accounts = [
    'sales' => ['phone' => '18185025361', 'store_id' => 3, 'role' => 'sales'],
    'coach' => ['phone' => '18385534850', 'store_id' => 3, 'role' => 'coach'],
    'manager' => ['phone' => '18798742857', 'store_id' => 3, 'role' => 'manager'],
    'operation' => ['phone' => '13668501068', 'store_id' => 1, 'role' => 'operation'],
    'finance' => ['phone' => '18685147960', 'store_id' => 1, 'role' => 'finance'],
    'ceo' => ['phone' => '13885135551', 'store_id' => 1, 'role' => 'ceo'],
];

$date = date('Y-m-d');
foreach ($accounts as $label => $account) {
    $token = login($account['phone']);
    if ($token === '') {
        echo strtoupper($label) . '_LOGIN=FAIL' . PHP_EOL;
        continue;
    }
    echo strtoupper($label) . '_LOGIN=OK' . PHP_EOL;

    $template = requestJson('http://127.0.0.1/api/workload/template.php?role=' . rawurlencode($account['role']), authHeaders($token));
    echo strtoupper($label) . '_TEMPLATE=' . ($template['code'] ?? 'null') . PHP_EOL;

    $own = requestJson('http://127.0.0.1/api/workload/my-report.php?date=' . rawurlencode($date) . '&role=' . rawurlencode($account['role']) . '&store_id=' . (int)$account['store_id'], authHeaders($token));
    echo strtoupper($label) . '_MY_REPORT=' . ($own['code'] ?? 'null') . PHP_EOL;

    $store = requestJson('http://127.0.0.1/api/workload/store-summary.php?date=' . rawurlencode($date) . '&store_id=3', authHeaders($token));
    echo strtoupper($label) . '_STORE_SUMMARY_3=' . ($store['code'] ?? 'null') . PHP_EOL;

    $hq = requestJson('http://127.0.0.1/api/workload/hq-summary.php?date_from=' . rawurlencode($date) . '&date_to=' . rawurlencode($date), authHeaders($token));
    echo strtoupper($label) . '_HQ_SUMMARY=' . ($hq['code'] ?? 'null') . PHP_EOL;
}

function login(string $phone): string
{
    $login = requestJson('http://127.0.0.1/api/auth-jwt.php', [
        'Host: 122.51.223.46',
        'Content-Type: application/json',
    ], json_encode(['username' => $phone, 'password' => '123456'], JSON_UNESCAPED_UNICODE));
    return $login['data']['token'] ?? '';
}

function authHeaders(string $token): array
{
    return ['Host: 122.51.223.46', 'Authorization: Bearer ' . $token];
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
