<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$base = 'http://127.0.0.1';
$today = date('Y-m-d');
$password = '123456';

$entries = [
    [
        'phone' => '18744908578',
        'name' => '吴丽沙',
        'store' => '小十字',
        'data' => [
            'name' => '吴丽沙',
            'planHours' => 4,
            'classHours' => 4,
            'planComm' => 3,
            'parentComm' => 3,
            'bodyTest' => 1,
            'campRec' => 1,
            'note' => '真实场景验收-教练录入',
        ],
    ],
    [
        'phone' => '17279586191',
        'name' => '陈美琴',
        'store' => '小十字',
        'data' => [
            'name' => '陈美琴',
            'planHours' => 3,
            'classHours' => 3,
            'planComm' => 2,
            'parentComm' => 2,
            'bodyTest' => 1,
            'campRec' => 0,
            'note' => '真实场景验收-教练录入',
        ],
    ],
];

foreach ($entries as $entry) {
    $login = requestJson($base . '/api/auth-jwt.php', [
        'Host: 122.51.223.46',
        'Content-Type: application/json',
    ], json_encode([
        'username' => $entry['phone'],
        'password' => $password,
    ], JSON_UNESCAPED_UNICODE));

    echo $entry['phone'] . ' LOGIN=' . ($login['code'] ?? 'null') . PHP_EOL;
    $token = $login['data']['token'] ?? '';
    if ($token === '') {
        echo $entry['phone'] . ' LOGIN_MESSAGE=' . ($login['message'] ?? '') . PHP_EOL;
        continue;
    }

    $save = requestJson($base . '/api/campaign/save.php', [
        'Host: 122.51.223.46',
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
    ], json_encode([
        'action' => 'save_entry',
        'date' => $today,
        'store' => $entry['store'],
        'role' => '教练',
        'name' => $entry['name'],
        'data' => $entry['data'],
    ], JSON_UNESCAPED_UNICODE));

    echo $entry['phone'] . ' SAVE=' . ($save['code'] ?? 'null') . ' ' . ($save['message'] ?? '') . PHP_EOL;

    $summary = requestJson($base . '/api/campaign/summary.php', [
        'Host: 122.51.223.46',
        'Authorization: Bearer ' . $token,
    ], null);

    $storeData = $summary['data']['state']['stores'][$entry['store']] ?? [];
    echo $entry['phone'] . ' STORE_CLASS_HOURS=' . ($storeData['classHours'] ?? 'null') . PHP_EOL;
    echo $entry['phone'] . ' CAMP_CNT=' . ($summary['data']['state']['camp_cnt'] ?? 'null') . PHP_EOL;
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
