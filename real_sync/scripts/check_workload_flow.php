<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$phone = $argv[1] ?? '';
if ($phone === '') {
    fwrite(STDERR, "Usage: php check_workload_flow.php <phone>\n");
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
    exit(1);
}

$template = requestJson('http://127.0.0.1/api/workload/template.php?role=coach', [
    'Host: 122.51.223.46',
    'Authorization: Bearer ' . $token,
], null);
echo $phone . ' TEMPLATE_CODE=' . ($template['code'] ?? 'null') . PHP_EOL;
echo $phone . ' TEMPLATE_ITEMS=' . count($template['data']['items'] ?? []) . PHP_EOL;

$today = date('Y-m-d');
$payload = [
    'report_date' => $today,
    'store_id' => 3,
    'role_code' => 'coach',
    'submit_status' => 'submitted',
    'source' => 'cli_check',
    'remarks' => '自动验证工作量日报保存',
    'values' => [
        ['metric_code' => 'coach_plan_hours', 'value' => 6],
        ['metric_code' => 'coach_actual_hours', 'value' => 5],
        ['metric_code' => 'coach_actual_comm', 'value' => 7],
    ],
];
$save = requestJson('http://127.0.0.1/api/workload/save-report.php', [
    'Host: 122.51.223.46',
    'Content-Type: application/json',
    'Authorization: Bearer ' . $token,
], json_encode($payload, JSON_UNESCAPED_UNICODE));
echo $phone . ' SAVE_CODE=' . ($save['code'] ?? 'null') . PHP_EOL;
echo $phone . ' SAVE_MESSAGE=' . ($save['message'] ?? '') . PHP_EOL;
echo $phone . ' REPORT_ID=' . ($save['data']['report_id'] ?? '') . PHP_EOL;

$report = requestJson('http://127.0.0.1/api/workload/my-report.php?date=' . rawurlencode($today) . '&store_id=3&role=coach', [
    'Host: 122.51.223.46',
    'Authorization: Bearer ' . $token,
], null);
echo $phone . ' MY_REPORT_CODE=' . ($report['code'] ?? 'null') . PHP_EOL;
echo $phone . ' MY_REPORT_VALUES=' . count($report['data']['values'] ?? []) . PHP_EOL;

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
    if ($body !== null) $options['http']['content'] = $body;
    $response = file_get_contents($url, false, stream_context_create($options));
    return is_string($response) ? (json_decode($response, true) ?: []) : [];
}
