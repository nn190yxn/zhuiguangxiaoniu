<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$phone = $argv[1] ?? '';
if ($phone === '') {
    fwrite(STDERR, "Usage: php check_doc_auth_chain.php <phone>\n");
    exit(2);
}
$token = login($phone);
if ($token === '') {
    echo "CHAIN_LOGIN=FAIL\n";
    exit(1);
}
echo "CHAIN_LOGIN=OK\n";

$me = request('http://127.0.0.1/api/auth/me.php', [
    'Host: 122.51.223.46',
    'Authorization: Bearer ' . $token,
]);
echo 'CHAIN_ME_CODE=' . $me['code'] . PHP_EOL;
echo 'CHAIN_ME_BODY=' . substr($me['body'], 0, 180) . PHP_EOL;

$doc = request('http://127.0.0.1/doc-content.php?doc=v4-00', [
    'Host: 122.51.223.46',
    'Authorization: Bearer ' . $token,
]);
echo 'CHAIN_DOC_CODE=' . $doc['code'] . PHP_EOL;
echo 'CHAIN_DOC_BODY=' . substr($doc['body'], 0, 180) . PHP_EOL;

function login(string $phone): string
{
    $res = request('http://127.0.0.1/api/auth-jwt.php', [
        'Host: 122.51.223.46',
        'Content-Type: application/json',
    ], json_encode(['username' => $phone, 'password' => '123456'], JSON_UNESCAPED_UNICODE));
    $json = json_decode($res['body'], true) ?: [];
    return $json['data']['token'] ?? '';
}

function request(string $url, array $headers, ?string $body = null): array
{
    $options = ['http' => ['method' => $body === null ? 'GET' : 'POST', 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 15]];
    if ($body !== null) $options['http']['content'] = $body;
    $content = file_get_contents($url, false, stream_context_create($options));
    $code = 0;
    foreach (($http_response_header ?? []) as $line) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $m)) { $code = (int)$m[1]; break; }
    }
    return ['code' => $code, 'body' => is_string($content) ? $content : ''];
}
