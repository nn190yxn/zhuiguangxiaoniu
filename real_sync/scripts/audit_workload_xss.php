<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$phone = $argv[1] ?? '';
if ($phone === '') {
    fwrite(STDERR, "Usage: php audit_workload_xss.php <phone>\n");
    exit(2);
}
$date = date('Y-m-d');
$token = login($phone);
if ($token === '') {
    echo "XSS_LOGIN=FAIL\n";
    exit(1);
}
echo "XSS_LOGIN=OK\n";

$payload = '<script>alert(1)</script> & "quoted"';
$save = requestJson('http://127.0.0.1/api/workload/save-report.php', [
    'Host: 122.51.223.46',
    'Content-Type: application/json',
    'Authorization: Bearer ' . $token,
], json_encode([
    'report_date' => $date,
    'store_id' => 3,
    'role_code' => 'coach',
    'submit_status' => 'draft',
    'source' => 'xss_audit',
    'remarks' => $payload,
    'values' => [
        ['metric_code' => 'coach_plan_hours', 'value' => 3],
        ['metric_code' => 'coach_actual_hours', 'value' => 3],
        ['metric_code' => 'coach_actual_comm', 'value' => 1],
    ],
], JSON_UNESCAPED_UNICODE));
echo 'XSS_SAVE_CODE=' . ($save['code'] ?? 'null') . PHP_EOL;

$read = requestJson('http://127.0.0.1/api/workload/my-report.php?date=' . rawurlencode($date) . '&store_id=3&role=coach', [
    'Host: 122.51.223.46',
    'Authorization: Bearer ' . $token,
]);
$remarks = $read['data']['report']['remarks'] ?? '';
echo 'XSS_READ_CODE=' . ($read['code'] ?? 'null') . PHP_EOL;
echo 'XSS_REMARKS_STORED_RAW=' . ($remarks === $payload ? 'YES' : 'NO') . PHP_EOL;

$invalidMetric = requestJson('http://127.0.0.1/api/workload/save-report.php', [
    'Host: 122.51.223.46',
    'Content-Type: application/json',
    'Authorization: Bearer ' . $token,
], json_encode([
    'report_date' => $date,
    'store_id' => 3,
    'role_code' => 'coach',
    'submit_status' => 'draft',
    'source' => 'xss_audit',
    'remarks' => str_repeat('超长备注', 100),
    'values' => [
        ['metric_code' => 'coach_plan_hours', 'value' => 1],
        ['metric_code' => 'coach_actual_hours', 'value' => 1],
        ['metric_code' => 'coach_actual_comm', 'value' => 1],
        ['metric_code' => 'bad_metric<script>', 'value' => 1],
    ],
], JSON_UNESCAPED_UNICODE));
echo 'XSS_BAD_METRIC_CODE=' . ($invalidMetric['code'] ?? 'null') . PHP_EOL;

$mobile = file_get_contents('/www/wwwroot/122.51.223.46/mobile/workload.html') ?: '';
$admin = file_get_contents('/www/wwwroot/122.51.223.46/admin/workload.html') ?: '';
echo 'XSS_H5_ESCAPE_FUNC=' . (str_contains($mobile, 'function escapeHtml') ? 'YES' : 'NO') . PHP_EOL;
echo 'XSS_ADMIN_ESCAPE_FUNC=' . (str_contains($admin, 'function esc') ? 'YES' : 'NO') . PHP_EOL;

function login(string $phone): string
{
    $login = requestJson('http://127.0.0.1/api/auth-jwt.php', [
        'Host: 122.51.223.46',
        'Content-Type: application/json',
    ], json_encode(['username' => $phone, 'password' => '123456'], JSON_UNESCAPED_UNICODE));
    return $login['data']['token'] ?? '';
}

function requestJson(string $url, array $headers, ?string $body = null): array
{
    $options = ['http' => ['method' => $body === null ? 'GET' : 'POST', 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 15]];
    if ($body !== null) $options['http']['content'] = $body;
    $response = file_get_contents($url, false, stream_context_create($options));
    return is_string($response) ? (json_decode($response, true) ?: []) : [];
}
