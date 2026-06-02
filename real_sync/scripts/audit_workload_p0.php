<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$coachPhone = $argv[1] ?? '';
$hqPhone = $argv[2] ?? '';
if ($coachPhone === '' || $hqPhone === '') {
    fwrite(STDERR, "Usage: php audit_workload_p0.php <coach_phone> <hq_phone>\n");
    exit(2);
}
$date = date('Y-m-d');

$coachToken = login($coachPhone);
if ($coachToken !== '') {
    $store = requestJson('http://127.0.0.1/api/workload/store-summary.php?date=' . rawurlencode($date) . '&store_id=3', [
        'Host: 122.51.223.46',
        'Authorization: Bearer ' . $coachToken,
    ]);
    echo 'WL001_COACH_STORE_SUMMARY_CODE=' . ($store['code'] ?? 'null') . PHP_EOL;

    $save = requestJson('http://127.0.0.1/api/workload/save-report.php', [
        'Host: 122.51.223.46',
        'Content-Type: application/json',
        'Authorization: Bearer ' . $coachToken,
    ], json_encode([
        'report_date' => $date,
        'store_id' => 3,
        'role_code' => 'coach',
        'submit_status' => 'draft',
        'source' => 'audit',
        'values' => [
            ['metric_code' => 'coach_plan_hours', 'value' => 1],
            ['metric_code' => 'coach_actual_hours', 'value' => 1],
            ['metric_code' => 'coach_actual_comm', 'value' => 1],
        ],
    ], JSON_UNESCAPED_UNICODE));
    echo 'WL002_COACH_SAVE_OWN_CODE=' . ($save['code'] ?? 'null') . PHP_EOL;

    $future = date('Y-m-d', strtotime('+1 day'));
    $futureSave = requestJson('http://127.0.0.1/api/workload/save-report.php', [
        'Host: 122.51.223.46',
        'Content-Type: application/json',
        'Authorization: Bearer ' . $coachToken,
    ], json_encode([
        'report_date' => $future,
        'store_id' => 3,
        'role_code' => 'coach',
        'submit_status' => 'draft',
        'source' => 'audit',
        'values' => [
            ['metric_code' => 'coach_plan_hours', 'value' => 1],
            ['metric_code' => 'coach_actual_hours', 'value' => 1],
            ['metric_code' => 'coach_actual_comm', 'value' => 1],
        ],
    ], JSON_UNESCAPED_UNICODE));
    echo 'WL003_FUTURE_SAVE_CODE=' . ($futureSave['code'] ?? 'null') . PHP_EOL;
}

$hqToken = login($hqPhone);
if ($hqToken !== '') {
    $fakeSave = requestJson('http://127.0.0.1/api/workload/save-report.php', [
        'Host: 122.51.223.46',
        'Content-Type: application/json',
        'Authorization: Bearer ' . $hqToken,
    ], json_encode([
        'report_date' => $date,
        'store_id' => 3,
        'role_code' => 'coach',
        'submit_status' => 'draft',
        'source' => 'audit',
        'values' => [
            ['metric_code' => 'coach_plan_hours', 'value' => 1],
            ['metric_code' => 'coach_actual_hours', 'value' => 1],
            ['metric_code' => 'coach_actual_comm', 'value' => 1],
        ],
    ], JSON_UNESCAPED_UNICODE));
    echo 'WL002_HQ_FAKE_SAVE_CODE=' . ($fakeSave['code'] ?? 'null') . PHP_EOL;

    $hq = requestJson('http://127.0.0.1/api/workload/hq-summary.php?date_from=' . rawurlencode($date) . '&date_to=' . rawurlencode($date), [
        'Host: 122.51.223.46',
        'Authorization: Bearer ' . $hqToken,
    ]);
    echo 'HQ_SUMMARY_CODE=' . ($hq['code'] ?? 'null') . PHP_EOL;
    echo 'HQ_STORE_ROWS=' . count($hq['data']['store_submission_rows'] ?? []) . PHP_EOL;
}

function login(string $phone): string
{
    $login = requestJson('http://127.0.0.1/api/auth-jwt.php', [
        'Host: 122.51.223.46',
        'Content-Type: application/json',
    ], json_encode(['username' => $phone, 'password' => '123456'], JSON_UNESCAPED_UNICODE));
    echo 'LOGIN_' . $phone . '=' . ($login['code'] ?? 'null') . PHP_EOL;
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
