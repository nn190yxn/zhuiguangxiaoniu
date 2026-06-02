<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once '/www/wwwroot/122.51.223.46/api/workload/_common.php';
$pdo = workloadDb();
$before = (int)$pdo->query('SELECT COUNT(*) FROM login_audit_logs')->fetchColumn();

$phone = $argv[1] ?? '';
if ($phone === '') {
    fwrite(STDERR, "Usage: php audit_login_audit_write.php <phone>\n");
    exit(2);
}

requestJson('http://127.0.0.1/api/auth-jwt.php', [
    'Host: 122.51.223.46',
    'Content-Type: application/json',
], json_encode(['username' => $phone, 'password' => 'bad-password'], JSON_UNESCAPED_UNICODE));

$success = requestJson('http://127.0.0.1/api/auth-jwt.php', [
    'Host: 122.51.223.46',
    'Content-Type: application/json',
], json_encode(['username' => $phone, 'password' => '123456'], JSON_UNESCAPED_UNICODE));

$after = (int)$pdo->query('SELECT COUNT(*) FROM login_audit_logs')->fetchColumn();
$last = $pdo->query('SELECT login_status, login_type, source, message FROM login_audit_logs ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];

echo 'LOGIN_AUDIT_BEFORE=' . $before . PHP_EOL;
echo 'LOGIN_AUDIT_AFTER=' . $after . PHP_EOL;
echo 'LOGIN_AUDIT_DELTA=' . ($after - $before) . PHP_EOL;
echo 'LOGIN_SUCCESS_CODE=' . ($success['code'] ?? 'null') . PHP_EOL;
echo 'LOGIN_AUDIT_LAST_STATUS=' . ($last['login_status'] ?? '') . PHP_EOL;
echo 'LOGIN_AUDIT_LAST_TYPE=' . ($last['login_type'] ?? '') . PHP_EOL;
echo 'LOGIN_AUDIT_LAST_SOURCE=' . ($last['source'] ?? '') . PHP_EOL;

function requestJson(string $url, array $headers, string $body): array
{
    $response = file_get_contents($url, false, stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body, 'ignore_errors' => true, 'timeout' => 15]]));
    return is_string($response) ? (json_decode($response, true) ?: []) : [];
}
