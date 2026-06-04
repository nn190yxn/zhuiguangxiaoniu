<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$phone = $argv[1] ?? '';
$doc = $argv[2] ?? 'v4-00';
if ($phone === '') {
    fwrite(STDERR, "Usage: php check_doc_viewer.php <phone> [doc]\n");
    exit(2);
}
$token = login($phone);
if ($token === '') {
    echo "DOC_LOGIN=FAIL\n";
    exit(1);
}
echo "DOC_LOGIN=OK\n";

$unauth = httpCode('http://127.0.0.1/doc-content.php?doc=' . rawurlencode($doc), ['Host: 122.51.223.46']);
echo 'DOC_UNAUTH_CODE=' . $unauth . PHP_EOL;

$auth = requestRaw('http://127.0.0.1/doc-content.php?doc=' . rawurlencode($doc), [
    'Host: 122.51.223.46',
    'Authorization: Bearer ' . $token,
]);
echo 'DOC_AUTH_CODE=' . $auth['code'] . PHP_EOL;
echo 'DOC_AUTH_LEN=' . strlen($auth['body']) . PHP_EOL;

$page = requestRaw('http://127.0.0.1/doc-viewer.html?doc=' . rawurlencode($doc), ['Host: 122.51.223.46']);
echo 'DOC_VIEWER_CODE=' . $page['code'] . PHP_EOL;
echo 'DOC_VIEWER_AUTH_HEADERS=' . (str_contains($page['body'], 'window.authHeaders') ? 'YES' : 'NO') . PHP_EOL;

function login(string $phone): string
{
    $res = requestRaw('http://127.0.0.1/api/auth-jwt.php', [
        'Host: 122.51.223.46',
        'Content-Type: application/json',
    ], json_encode(['username' => $phone, 'password' => '123456'], JSON_UNESCAPED_UNICODE));
    $json = json_decode($res['body'], true) ?: [];
    return $json['data']['token'] ?? '';
}

function httpCode(string $url, array $headers): int
{
    return requestRaw($url, $headers)['code'];
}

function requestRaw(string $url, array $headers, ?string $body = null): array
{
    $options = ['http' => ['method' => $body === null ? 'GET' : 'POST', 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 15]];
    if ($body !== null) {
        $options['http']['content'] = $body;
    }
    $content = file_get_contents($url, false, stream_context_create($options));
    $code = 0;
    foreach (($http_response_header ?? []) as $line) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $m)) {
            $code = (int)$m[1];
            break;
        }
    }
    return ['code' => $code, 'body' => is_string($content) ? $content : ''];
}
