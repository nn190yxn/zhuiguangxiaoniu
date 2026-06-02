<?php
// 汇总页面测试
if (PHP_SAPI !== 'cli') { exit; }

$coachPhone = '18385534850';
$managerPhone = '18798742857';
$operationPhone = '18285031172';
$password = '123456';

// 测试教练访问汇总页面
echo "=== 1. 教练访问汇总页面 ===\n";
$loginCtx = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Host: 122.51.223.46\r\nContent-Type: application/json\r\n",
        'content' => json_encode(['username' => $coachPhone, 'password' => $password]),
        'ignore_errors' => true
    ]
]);
$loginResponse = file_get_contents("http://127.0.0.1/api/auth-jwt.php", false, $loginCtx);
$loginData = json_decode($loginResponse, true);
$coachToken = $loginData['data']['token'] ?? '';

$date = date('Y-m-d');
$summaryCtx = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => "Host: 122.51.223.46\r\nAuthorization: Bearer $coachToken\r\n",
        'ignore_errors' => true
    ]
]);
$summaryResponse = file_get_contents("http://127.0.0.1/api/workload/hq-summary.php?start_date=$date&end_date=$date", false, $summaryCtx);
$summaryData = json_decode($summaryResponse, true);
echo "教练访问汇总: " . ($summaryData['code'] === 403 ? '被拒绝 (正确)' : '允许访问 (错误)') . "\n";

// 测试店长访问汇总页面
echo "=== 2. 店长访问汇总页面 ===\n";
$loginCtx2 = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Host: 122.51.223.46\r\nContent-Type: application/json\r\n",
        'content' => json_encode(['username' => $managerPhone, 'password' => $password]),
        'ignore_errors' => true
    ]
]);
$loginResponse2 = file_get_contents("http://127.0.0.1/api/auth-jwt.php", false, $loginCtx2);
$loginData2 = json_decode($loginResponse2, true);
$managerToken = $loginData2['data']['token'] ?? '';

$summaryCtx2 = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => "Host: 122.51.223.46\r\nAuthorization: Bearer $managerToken\r\n",
        'ignore_errors' => true
    ]
]);
$summaryResponse2 = file_get_contents("http://127.0.0.1/api/workload/hq-summary.php?start_date=$date&end_date=$date&store_id=3", false, $summaryCtx2);
$summaryData2 = json_decode($summaryResponse2, true);
echo "店长访问本店汇总: " . ($summaryData2['code'] === 0 ? '成功' : '失败') . "\n";

// 测试运营访问汇总页面
echo "=== 3. 运营访问汇总页面 ===\n";
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
$summaryResponse3 = file_get_contents("http://127.0.0.1/api/workload/hq-summary.php?start_date=$date&end_date=$date", false, $summaryCtx3);
$summaryData3 = json_decode($summaryResponse3, true);
echo "运营访问汇总: " . ($summaryData3['code'] === 0 ? '成功' : '失败') . "\n";

// 测试空数据情况
echo "=== 4. 空数据测试 ===\n";
$futureDate = date('Y-m-d', strtotime('+30 days'));
$summaryCtx4 = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => "Host: 122.51.223.46\r\nAuthorization: Bearer $operationToken\r\n",
        'ignore_errors' => true
    ]
]);
$summaryResponse4 = file_get_contents("http://127.0.0.1/api/workload/hq-summary.php?start_date=$futureDate&end_date=$futureDate", false, $summaryCtx4);
$summaryData4 = json_decode($summaryResponse4, true);
echo "空数据返回: " . (isset($summaryData4['data']['list']) && is_array($summaryData4['data']['list']) ? '正确格式' : '格式错误') . "\n";