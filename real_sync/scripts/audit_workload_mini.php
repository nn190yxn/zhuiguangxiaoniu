<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$root = '/www/wwwroot/122.51.223.46/mini-program';
$files = [
    'app.json',
    'app.js',
    'utils/api.js',
    'utils/auth.js',
    'pages/workload/index.js',
    'pages/workload/index.wxml',
    'pages/workload/index.wxss',
    'pages/workload/index.json',
];

foreach ($files as $file) {
    echo 'MINI_FILE_' . strtoupper(str_replace(['/', '.', '-'], '_', $file)) . '=' . (is_file($root . '/' . $file) ? 'YES' : 'NO') . PHP_EOL;
}

$appJsonRaw = file_get_contents($root . '/app.json') ?: '{}';
$appJson = json_decode($appJsonRaw, true) ?: [];
$pages = $appJson['pages'] ?? [];
echo 'MINI_PAGE_REGISTERED=' . (in_array('pages/workload/index', $pages, true) ? 'YES' : 'NO') . PHP_EOL;

$appJs = file_get_contents($root . '/app.js') ?: '';
$apiJs = file_get_contents($root . '/utils/api.js') ?: '';
$pageJs = file_get_contents($root . '/pages/workload/index.js') ?: '';
$pageWxml = file_get_contents($root . '/pages/workload/index.wxml') ?: '';

echo 'MINI_APP_REQUEST_USES_API=' . (str_contains($appJs, "require('./utils/api')") ? 'YES' : 'NO') . PHP_EOL;
echo 'MINI_API_AUTH_HEADER=' . (str_contains($apiJs, 'header.Authorization') ? 'YES' : 'NO') . PHP_EOL;
echo 'MINI_API_401_REDIRECT=' . (str_contains($apiJs, 'redirectToLogin') ? 'YES' : 'NO') . PHP_EOL;
echo 'MINI_PAGE_USES_APP_REQUEST=' . (str_contains($pageJs, 'app.request') ? 'YES' : 'NO') . PHP_EOL;
echo 'MINI_PAGE_NO_WX_REQUEST=' . (!str_contains($pageJs, 'wx.request') ? 'YES' : 'NO') . PHP_EOL;
echo 'MINI_PICKER_END=' . (str_contains($pageWxml, 'end="{{maxDate}}"') ? 'YES' : 'NO') . PHP_EOL;

$phone = $argv[1] ?? '';
if ($phone === '') {
    fwrite(STDERR, "Usage: php audit_workload_mini.php <phone>\n");
    exit(2);
}
$token = login($phone);
if ($token !== '') {
    echo 'MINI_LOGIN=OK' . PHP_EOL;
    $template = requestJson('http://127.0.0.1/api/workload/template.php?role=coach', authHeaders($token));
    echo 'MINI_TEMPLATE_CODE=' . ($template['code'] ?? 'null') . PHP_EOL;
    $report = requestJson('http://127.0.0.1/api/workload/my-report.php?date=' . rawurlencode(date('Y-m-d')) . '&store_id=3&role=coach', authHeaders($token));
    echo 'MINI_MY_REPORT_CODE=' . ($report['code'] ?? 'null') . PHP_EOL;
} else {
    echo 'MINI_LOGIN=FAIL' . PHP_EOL;
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
    $options = ['http' => ['method' => $body === null ? 'GET' : 'POST', 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 15]];
    if ($body !== null) {
        $options['http']['content'] = $body;
    }
    $response = file_get_contents($url, false, stream_context_create($options));
    return is_string($response) ? (json_decode($response, true) ?: []) : [];
}
