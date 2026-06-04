<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$coachPhone = $argv[1] ?? '';
$hqPhone = $argv[2] ?? '';
if ($coachPhone === '' || $hqPhone === '') {
    fwrite(STDERR, "Usage: php check_workload_summary.php <coach_phone> <hq_phone>\n");
    exit(2);
}
$date = date('Y-m-d');

$coachToken = login($coachPhone);
if ($coachToken !== '') {
    $store = requestJson('http://127.0.0.1/api/workload/store-summary.php?date=' . rawurlencode($date) . '&store_id=3', [
        'Host: 122.51.223.46',
        'Authorization: Bearer ' . $coachToken,
    ], null);
    echo $coachPhone . ' STORE_SUMMARY_CODE=' . ($store['code'] ?? 'null') . PHP_EOL;
    echo $coachPhone . ' STORE_REPORT_COUNT=' . ($store['data']['report_count'] ?? '') . PHP_EOL;
    echo $coachPhone . ' STORE_EXPECTED_COUNT=' . ($store['data']['expected_count'] ?? '') . PHP_EOL;
    echo $coachPhone . ' STORE_MISSING_COUNT=' . ($store['data']['missing_count'] ?? '') . PHP_EOL;
    echo $coachPhone . ' STORE_SUBMISSION_RATE=' . ($store['data']['submission_rate'] ?? '') . PHP_EOL;

    $hqDenied = requestJson('http://127.0.0.1/api/workload/hq-summary.php?date_from=' . rawurlencode($date) . '&date_to=' . rawurlencode($date), [
        'Host: 122.51.223.46',
        'Authorization: Bearer ' . $coachToken,
    ], null);
    echo $coachPhone . ' HQ_SUMMARY_CODE=' . ($hqDenied['code'] ?? 'null') . PHP_EOL;
}

$hqToken = login($hqPhone);
if ($hqToken !== '') {
    $hq = requestJson('http://127.0.0.1/api/workload/hq-summary.php?date_from=' . rawurlencode($date) . '&date_to=' . rawurlencode($date), [
        'Host: 122.51.223.46',
        'Authorization: Bearer ' . $hqToken,
    ], null);
    echo $hqPhone . ' HQ_SUMMARY_CODE=' . ($hq['code'] ?? 'null') . PHP_EOL;
    echo $hqPhone . ' HQ_ROWS=' . count($hq['data']['summary_rows'] ?? []) . PHP_EOL;
    echo $hqPhone . ' HQ_STORE_ROWS=' . count($hq['data']['store_submission_rows'] ?? []) . PHP_EOL;
    $firstStore = $hq['data']['store_submission_rows'][0] ?? [];
    echo $hqPhone . ' HQ_FIRST_STORE_RATE=' . ($firstStore['submission_rate'] ?? '') . PHP_EOL;
}

function login(string $phone): string {
    $login = requestJson('http://127.0.0.1/api/auth-jwt.php', [
        'Host: 122.51.223.46',
        'Content-Type: application/json',
    ], json_encode(['username' => $phone, 'password' => '123456'], JSON_UNESCAPED_UNICODE));
    echo $phone . ' LOGIN=' . ($login['code'] ?? 'null') . PHP_EOL;
    if (($login['data']['token'] ?? '') === '') {
        echo $phone . ' LOGIN_MESSAGE=' . ($login['message'] ?? '') . PHP_EOL;
    }
    return $login['data']['token'] ?? '';
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
    if ($body !== null) $options['http']['content'] = $body;
    $response = file_get_contents($url, false, stream_context_create($options));
    return is_string($response) ? (json_decode($response, true) ?: []) : [];
}
