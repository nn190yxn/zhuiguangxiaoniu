<?php
// 修正后的汇总页面测试
if (PHP_SAPI !== 'cli') { exit; }

$coachPhone = '18385534850';
$managerPhone = '18798742857';
$operationPhone = '18285031172';
$password = '123456';

$date = date('Y-m-d');

// 测试运营访问汇总页面（使用正确参数）
echo "=== 1. 运营访问汇总页面 ===\n";
$loginCtx3 = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Host: 122.51.223.46\r\nContent-Type: application/json\r\n",
        'content' => json_encode(['username' => $operationPhone, 'password' => $password]),
        'ignore_errors' => true
    ]
]);
$loginResponse3 = file_get_contents("http://127.0.0.1/api/auth-jwt.php", false, $loginCtx3);
$loginData3 = json_decode($loginResponse3, true);
$operationToken = $loginData3['data']['token'] ?? '';

$summaryCtx3 = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => "Host: 122.51.223.46\r\nAuthorization: Bearer $operationToken\r\n",
        'ignore_errors' => true
    ]
]);
$summaryResponse3 = file_get_contents("http://127.0.0.1/api/workload/hq-summary.php?date_from=$date&date_to=$date", false, $summaryCtx3);
$summaryData3 = json_decode($summaryResponse3, true);
echo "运营访问汇总: " . ($summaryData3['code'] === 0 ? '成功' : '失败 - ' . $summaryData3['message']) . "\n";

// 测试空数据情况
echo "=== 2. 空数据测试 ===\n";
$futureDate = date('Y-m-d', strtotime('+30 days'));
$summaryCtx4 = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => "Host: 122.51.223.46\r\nAuthorization: Bearer $operationToken\r\n",
        'ignore_errors' => true
    ]
]);
$summaryResponse4 = file_get_contents("http://127.0.0.1/api/workload/hq-summary.php?date_from=$futureDate&date_to=$futureDate", false, $summaryCtx4);
$summaryData4 = json_decode($summaryResponse4, true);
echo "空数据返回: Code=" . $summaryData4['code'] . ", Message=" . ($summaryData4['message'] ?? 'success') . "\n";