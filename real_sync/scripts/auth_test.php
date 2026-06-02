<?php
// 越权测试脚本 - 教练账号
if (PHP_SAPI !== 'cli') { exit; }

$coachPhone = '18385534850';
$password = '123456';

// 1. 正常登录获取 token
echo "=== 1. 正常登录 ===\n";
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
$token = $loginData['data']['token'] ?? '';
echo "Token: " . ($token ? '成功获取' : '获取失败') . "\n";

if (!$token) {
    echo "登录失败，无法继续测试\n";
    exit(1);
}

// 2. 正常提交日报
echo "=== 2. 正常提交日报 ===\n";
$date = date('Y-m-d');
$normalData = [
    'report_date' => $date,
    'store_id' => 3, // 教练所属门店
    'role_code' => 'coach',
    'submit_status' => 'draft',
    'source' => 'test',
    'values' => [
        ['metric_code' => 'coach_plan_hours', 'value' => 1],
        ['metric_code' => 'coach_actual_hours', 'value' => 1],
        ['metric_code' => 'coach_plan_comm', 'value' => 0],
        ['metric_code' => 'coach_actual_comm', 'value' => 1],
        ['metric_code' => 'coach_body_test', 'value' => 0],
        ['metric_code' => 'coach_camp_recommend', 'value' => 0],
        ['metric_code' => 'coach_renew_count', 'value' => 0],
    ]
];

$saveCtx = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Host: 122.51.223.46\r\nContent-Type: application/json\r\nAuthorization: Bearer $token\r\n",
        'content' => json_encode($normalData),
        'ignore_errors' => true
    ]
]);
$saveResponse = file_get_contents("http://127.0.0.1/api/workload/save-report.php", false, $saveCtx);
$saveData = json_decode($saveResponse, true);
$reportId = $saveData['data']['report_id'] ?? 0;
echo "正常提交: " . ($saveData['code'] === 0 ? '成功' : '失败') . ", Report ID: $reportId\n";

// 3. 越权测试 - 修改 store_id 为其他门店 (store_id=2)
echo "=== 3. 越权测试 - 修改 store_id 为其他门店 ===\n";
$maliciousData1 = $normalData;
$maliciousData1['store_id'] = 2; // 其他门店

$saveCtx1 = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Host: 122.51.223.46\r\nContent-Type: application/json\r\nAuthorization: Bearer $token\r\n",
        'content' => json_encode($maliciousData1),
        'ignore_errors' => true
    ]
]);
$saveResponse1 = file_get_contents("http://127.0.0.1/api/workload/save-report.php", false, $saveCtx1);
$saveData1 = json_decode($saveResponse1, true);
echo "越权 store_id: " . ($saveData1['code'] === 403 ? '被拦截 (正确)' : '越权成功 (P0问题!)') . "\n";

// 4. 越权测试 - 在 URL 中尝试访问其他门店数据
echo "=== 4. 越权测试 - 访问其他门店汇总数据 ===\n";
$summaryCtx = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => "Host: 122.51.223.46\r\nAuthorization: Bearer $token\r\n",
        'ignore_errors' => true
    ]
]);
// 尝试访问 store_id=2 的数据
$summaryResponse = file_get_contents("http://127.0.0.1/api/workload/hq-summary.php?store_id=2&start_date=$date&end_date=$date", false, $summaryCtx);
$summaryData = json_decode($summaryResponse, true);
echo "越权访问汇总: " . ($summaryData['code'] === 403 ? '被拦截 (正确)' : '越权成功 (P0问题!)') . "\n";

// 5. 未登录测试
echo "=== 5. 未登录测试 ===\n";
$noAuthCtx = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => "Host: 122.51.223.46\r\n",
        'ignore_errors' => true
    ]
]);
$noAuthResponse = file_get_contents("http://127.0.0.1/api/workload/template.php?role=coach", false, $noAuthCtx);
$noAuthData = json_decode($noAuthResponse, true);
echo "未登录访问: " . ($noAuthData['code'] === 401 ? '被拦截 (正确)' : '未拦截 (P0问题!)') . "\n";

// 6. 过期 token 测试
echo "=== 6. 过期 token 测试 ===\n";
$expiredToken = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJzdGFmZl9pZCI6MzUsInN0b3JlX2lkIjozLCJyb2xlIjoiY29hY2giLCJleHAiOjE2MDAwMDAwMDB9.expired_signature';
$expiredCtx = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => "Host: 122.51.223.46\r\nAuthorization: Bearer $expiredToken\r\n",
        'ignore_errors' => true
    ]
]);
$expiredResponse = file_get_contents("http://127.0.0.1/api/workload/template.php?role=coach", false, $expiredCtx);
$expiredData = json_decode($expiredResponse, true);
echo "过期 token 访问: " . ($expiredData['code'] === 401 ? '被拦截 (正确)' : '未拦截 (P0问题!)') . "\n";