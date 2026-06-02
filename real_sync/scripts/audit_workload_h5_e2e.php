<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$phone = $argv[1] ?? '';
if ($phone === '') {
    fwrite(STDERR, "Usage: php audit_workload_h5_e2e.php <phone>\n");
    exit(2);
}
$date = date('Y-m-d');
$token = login($phone);
if ($token === '') {
    echo "H5_LOGIN=FAIL\n";
    exit(1);
}
echo "H5_LOGIN=OK\n";

$pageCode = httpCode('http://127.0.0.1/mobile/workload.html', ['Host: 122.51.223.46']);
echo 'H5_PAGE_CODE=' . $pageCode . PHP_EOL;

$template = requestJson('http://127.0.0.1/api/workload/template.php?role=coach', authHeaders($token));
echo 'H5_TEMPLATE_CODE=' . ($template['code'] ?? 'null') . PHP_EOL;
echo 'H5_TEMPLATE_ITEMS=' . count($template['data']['items'] ?? []) . PHP_EOL;

$ok = save($token, $date, 3, 'coach', [
    ['metric_code' => 'coach_plan_hours', 'value' => 2],
    ['metric_code' => 'coach_actual_hours', 'value' => 2],
    ['metric_code' => 'coach_actual_comm', 'value' => 1],
], '<b>e2e</b> & remark');
echo 'H5_SAVE_OK_CODE=' . ($ok['code'] ?? 'null') . PHP_EOL;

$missing = save($token, $date, 3, 'coach', [
    ['metric_code' => 'coach_plan_hours', 'value' => 2],
], 'missing required');
echo 'H5_MISSING_REQUIRED_CODE=' . ($missing['code'] ?? 'null') . PHP_EOL;

$invalid = save($token, $date, 3, 'coach', [
    ['metric_code' => 'coach_plan_hours', 'value' => 'abc'],
    ['metric_code' => 'coach_actual_hours', 'value' => 2],
    ['metric_code' => 'coach_actual_comm', 'value' => 1],
], 'invalid number');
echo 'H5_INVALID_NUMBER_CODE=' . ($invalid['code'] ?? 'null') . PHP_EOL;

$negative = save($token, $date, 3, 'coach', [
    ['metric_code' => 'coach_plan_hours', 'value' => -1],
    ['metric_code' => 'coach_actual_hours', 'value' => 2],
    ['metric_code' => 'coach_actual_comm', 'value' => 1],
], 'negative');
echo 'H5_NEGATIVE_CODE=' . ($negative['code'] ?? 'null') . PHP_EOL;

$otherStore = save($token, $date, 2, 'coach', [
    ['metric_code' => 'coach_plan_hours', 'value' => 1],
    ['metric_code' => 'coach_actual_hours', 'value' => 1],
    ['metric_code' => 'coach_actual_comm', 'value' => 1],
], 'other store');
echo 'H5_OTHER_STORE_CODE=' . ($otherStore['code'] ?? 'null') . PHP_EOL;

$future = save($token, date('Y-m-d', strtotime('+1 day')), 3, 'coach', [
    ['metric_code' => 'coach_plan_hours', 'value' => 1],
    ['metric_code' => 'coach_actual_hours', 'value' => 1],
    ['metric_code' => 'coach_actual_comm', 'value' => 1],
], 'future');
echo 'H5_FUTURE_CODE=' . ($future['code'] ?? 'null') . PHP_EOL;

$read = requestJson('http://127.0.0.1/api/workload/my-report.php?date=' . rawurlencode($date) . '&store_id=3&role=coach', authHeaders($token));
echo 'H5_READ_BACK_CODE=' . ($read['code'] ?? 'null') . PHP_EOL;
echo 'H5_READ_BACK_VALUES=' . count($read['data']['values'] ?? []) . PHP_EOL;

function save(string $token, string $date, int $storeId, string $role, array $values, string $remarks): array
{
    return requestJson('http://127.0.0.1/api/workload/save-report.php', [
        'Host: 122.51.223.46',
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
    ], json_encode([
        'report_date' => $date,
        'store_id' => $storeId,
        'role_code' => $role,
        'submit_status' => 'draft',
        'source' => 'h5_e2e',
        'remarks' => $remarks,
        'values' => $values,
    ], JSON_UNESCAPED_UNICODE));
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

function httpCode(string $url, array $headers): int
{
    $body = file_get_contents($url, false, stream_context_create(['http' => ['method' => 'GET', 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 15]]));
    $meta = $http_response_header ?? [];
    foreach ($meta as $line) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $m)) return (int)$m[1];
    }
    return is_string($body) ? 200 : 0;
}

function requestJson(string $url, array $headers, ?string $body = null): array
{
    $options = ['http' => ['method' => $body === null ? 'GET' : 'POST', 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 15]];
    if ($body !== null) $options['http']['content'] = $body;
    $response = file_get_contents($url, false, stream_context_create($options));
    return is_string($response) ? (json_decode($response, true) ?: []) : [];
}
