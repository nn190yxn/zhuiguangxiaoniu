<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$base = 'http://127.0.0.1';
$password = '123456';
$today = date('Y-m-d');

$scenarios = [
    [
        'label' => 'sales',
        'username' => '19900000091',
        'store' => '小河',
        'role' => '销售',
        'name' => '验收测试账号',
        'payload' => [
            'name' => '验收测试账号',
            'resources' => 3,
            'planPromise' => 2,
            'promise' => 2,
            'planVisit' => 1,
            'visit' => 1,
            'planTrial' => 1,
            'trial' => 1,
            'deal' => 1,
            'note' => 'campaign-flow-sales',
        ],
        'assertions' => [
            'store.newSign' => 1,
            'funnel.resources' => 3,
            'funnel.deal' => 1,
            'sales_count' => 1,
        ],
    ],
    [
        'label' => 'coach',
        'username' => '15121622579',
        'store' => '小河',
        'role' => '教练',
        'name' => '向仙灯',
        'payload' => [
            'name' => '向仙灯',
            'planHours' => 2,
            'classHours' => 2,
            'planComm' => 1,
            'parentComm' => 1,
            'bodyTest' => 1,
            'campRec' => 1,
            'note' => 'campaign-flow-coach',
        ],
        'assertions' => [
            'store.classHours' => 2,
            'camp_cnt' => 1,
            'coaches_count' => 1,
        ],
    ],
    [
        'label' => 'manager',
        'username' => '19900000091',
        'store' => '小河',
        'role' => '店长',
        'name' => '验收测试账号',
        'payload' => [
            'renew' => 1,
            'renewAmt' => 5000,
            'newSign' => 1,
            'newAmt' => 6000,
            'planP0' => 2,
            'p0touch' => 2,
            'planP1' => 1,
            'p1touch' => 1,
            'note' => 'campaign-flow-manager',
        ],
        'assertions' => [
            'renew_cnt' => 1,
            'new_cnt' => 1,
            'total_rev' => 1.1,
            'store.renew' => 1,
            'store.newSign' => 1,
        ],
    ],
    [
        'label' => 'ops_live',
        'username' => '19900000091',
        'store' => '小河',
        'role' => '直播运营',
        'name' => '验收测试账号',
        'payload' => [
            'name' => '验收测试账号',
            'planLive' => 1,
            'liveCount' => 1,
            'liveDuration' => 90,
            'maxOnline' => 35,
            'liveLeads' => 4,
            'liveDeal' => 1,
            'liveGMV' => 1500,
            'campLive' => 1,
            'note' => 'campaign-flow-live',
        ],
        'assertions' => [
            'store.live' => 1,
            'camp_cnt' => 1,
        ],
    ],
    [
        'label' => 'ops_content',
        'username' => '19900000091',
        'store' => '小河',
        'role' => '内容运营',
        'name' => '验收测试账号',
        'payload' => [
            'name' => '验收测试账号',
            'planContent' => 2,
            'contentTotal' => 2,
            'maxPlay' => 2000,
            'followers' => 50,
            'note' => 'campaign-flow-content',
        ],
        'assertions' => [
            'auth.canEditAllData' => true,
            'auth.allowedRolesContains' => '内容运营',
        ],
    ],
];

foreach ($scenarios as $scenario) {
    runScenario($base, $password, $today, $scenario);
}

function runScenario(string $base, string $password, string $today, array $scenario): void
{
    $label = strtoupper($scenario['label']);
    $login = requestJson($base . '/api/auth-jwt.php', [
        'Host: 122.51.223.46',
        'Content-Type: application/json',
    ], json_encode([
        'username' => $scenario['username'],
        'password' => $password,
    ], JSON_UNESCAPED_UNICODE));

    echo $label . '_LOGIN_CODE=' . ($login['code'] ?? 'null') . PHP_EOL;
    $token = $login['data']['token'] ?? '';
    if ($token === '') {
        return;
    }

    $summary = requestJson($base . '/api/campaign/summary.php', [
        'Host: 122.51.223.46',
        'Authorization: Bearer ' . $token,
    ], null);

    echo $label . '_ALLOWED_ROLES=' . implode(',', $summary['data']['auth']['allowedRoles'] ?? []) . PHP_EOL;
    echo $label . '_CAN_EDIT_ALL=' . ((($summary['data']['auth']['canEditAllData'] ?? false) ? '1' : '0')) . PHP_EOL;

    $payload = [
        'action' => 'save_entry',
        'date' => $today,
        'store' => $scenario['store'],
        'role' => $scenario['role'],
        'name' => $scenario['name'],
        'data' => $scenario['payload'],
    ];

    $save = requestJson($base . '/api/campaign/save.php', [
        'Host: 122.51.223.46',
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
    ], json_encode($payload, JSON_UNESCAPED_UNICODE));

    echo $label . '_SAVE_CODE=' . ($save['code'] ?? 'null') . PHP_EOL;
    echo $label . '_SAVE_MESSAGE=' . ($save['message'] ?? '') . PHP_EOL;

    $after = requestJson($base . '/api/campaign/summary.php', [
        'Host: 122.51.223.46',
        'Authorization: Bearer ' . $token,
    ], null);

    foreach ($scenario['assertions'] as $key => $expected) {
        $actual = readAssertion($after, $scenario['store'], $key);
        echo $label . '_' . strtoupper(str_replace(['.', ' '], '_', $key)) . '=' . formatScalar($actual) . PHP_EOL;
        echo $label . '_' . strtoupper(str_replace(['.', ' '], '_', $key)) . '_EXPECTED=' . formatScalar($expected) . PHP_EOL;
    }
}

function readAssertion(array $summary, string $store, string $key)
{
    switch ($key) {
        case 'store.newSign': return $summary['data']['state']['stores'][$store]['newSign'] ?? null;
        case 'store.classHours': return $summary['data']['state']['stores'][$store]['classHours'] ?? null;
        case 'store.renew': return $summary['data']['state']['stores'][$store]['renew'] ?? null;
        case 'store.live': return $summary['data']['state']['stores'][$store]['live'] ?? null;
        case 'funnel.resources': return $summary['data']['state']['funnel']['resources'] ?? null;
        case 'funnel.deal': return $summary['data']['state']['funnel']['deal'] ?? null;
        case 'renew_cnt': return $summary['data']['state']['renew_cnt'] ?? null;
        case 'new_cnt': return $summary['data']['state']['new_cnt'] ?? null;
        case 'camp_cnt': return $summary['data']['state']['camp_cnt'] ?? null;
        case 'total_rev': return $summary['data']['state']['total_rev'] ?? null;
        case 'sales_count': return count($summary['data']['state']['sales'] ?? []);
        case 'coaches_count': return count($summary['data']['state']['coaches'] ?? []);
        case 'auth.canEditAllData': return $summary['data']['auth']['canEditAllData'] ?? null;
        case 'auth.allowedRolesContains':
            return in_array('内容运营', $summary['data']['auth']['allowedRoles'] ?? [], true);
        default: return null;
    }
}

function formatScalar($value): string
{
    if (is_bool($value)) return $value ? '1' : '0';
    if ($value === null) return 'null';
    if (is_float($value)) return rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');
    return (string) $value;
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
