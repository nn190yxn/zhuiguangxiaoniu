<?php
// 功能测试脚本 - 录入页面测试
if (PHP_SAPI !== 'cli') { exit; }

$coachPhone = '18385534850';
$password = '123456';

// 登录获取 token
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

if (!$token) {
    echo "登录失败\n";
    exit(1);
}

$date = date('Y-m-d');

// 1. 正常录入测试
echo "=== 1. 正常录入测试 ===\n";
$normalData = [
    'report_date' => $date,
    'store_id' => 3,
    'role_code' => 'coach',
    'submit_status' => 'draft',
    'source' => 'test',
    'values' => [
        ['metric_code' => 'coach_plan_hours', 'value' => 2],
        ['metric_code' => 'coach_actual_hours', 'value' => 2],
        ['metric_code' => 'coach_plan_comm', 'value' => 1],
        ['metric_code' => 'coach_actual_comm', 'value' => 1],
        ['metric_code' => 'coach_body_test', 'value' => 3],
        ['metric_code' => 'coach_camp_recommend', 'value' => 0],
        ['metric_code' => 'coach_renew_count', 'value' => 1],
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
echo "正常录入: " . ($saveData['code'] === 0 ? '成功' : '失败') . "\n";

// 2. 必填项校验测试
echo "=== 2. 必填项校验测试 ===\n";
$missingData = [
    'report_date' => $date,
    'store_id' => 3,
    'role_code' => 'coach',
    'submit_status' => 'draft',
    'source' => 'test',
    'values' => [
        // 缺少 coach_plan_hours (必填)
        ['metric_code' => 'coach_actual_hours', 'value' => 2],
        ['metric_code' => 'coach_plan_comm', 'value' => 1],
        ['metric_code' => 'coach_actual_comm', 'value' => 1],
        ['metric_code' => 'coach_body_test', 'value' => 3],
        ['metric_code' => 'coach_camp_recommend', 'value' => 0],
        ['metric_code' => 'coach_renew_count', 'value' => 1],
    ]
];

$saveCtx2 = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Host: 122.51.223.46\r\nContent-Type: application/json\r\nAuthorization: Bearer $token\r\n",
        'content' => json_encode($missingData),
        'ignore_errors' => true
    ]
]);
$saveResponse2 = file_get_contents("http://127.0.0.1/api/workload/save-report.php", false, $saveCtx2);
$saveData2 = json_decode($saveResponse2, true);
echo "必填项校验: " . ($saveData2['code'] === 400 && strpos($saveData2['message'], '缺少必填指标') !== false ? '正确拦截' : '校验失败') . "\n";

// 3. 负数测试
echo "=== 3. 负数测试 ===\n";
$negativeData = [
    'report_date' => $date,
    'store_id' => 3,
    'role_code' => 'coach',
    'submit_status' => 'draft',
    'source' => 'test',
    'values' => [
        ['metric_code' => 'coach_plan_hours', 'value' => -1], // 负数
        ['metric_code' => 'coach_actual_hours', 'value' => 2],
        ['metric_code' => 'coach_plan_comm', 'value' => 1],
        ['metric_code' => 'coach_actual_comm', 'value' => 1],
        ['metric_code' => 'coach_body_test', 'value' => 3],
        ['metric_code' => 'coach_camp_recommend', 'value' => 0],
        ['metric_code' => 'coach_renew_count', 'value' => 1],
    ]
];

$saveCtx3 = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Host: 122.51.223.46\r\nContent-Type: application/json\r\nAuthorization: Bearer $token\r\n",
        'content' => json_encode($negativeData),
        'ignore_errors' => true
    ]
]);
$saveResponse3 = file_get_contents("http://127.0.0.1/api/workload/save-report.php", false, $saveCtx3);
$saveData3 = json_decode($saveResponse3, true);
echo "负数校验: " . ($saveData3['code'] === 400 && strpos($saveData3['message'], '不能小于最小值') !== false ? '正确拦截' : '校验失败') . "\n";

// 4. 凭证测试 - 提交需要凭证的指标但不上传
echo "=== 4. 凭证校验测试 ===\n";
$evidenceRequiredData = [
    'report_date' => $date,
    'store_id' => 3,
    'role_code' => 'coach',
    'submit_status' => 'submitted', // 提交状态
    'source' => 'test',
    'values' => [
        ['metric_code' => 'coach_plan_hours', 'value' => 1],
        ['metric_code' => 'coach_actual_hours', 'value' => 1],
        ['metric_code' => 'coach_plan_comm', 'value' => 0],
        ['metric_code' => 'coach_actual_comm', 'value' => 1],
        ['metric_code' => 'coach_body_test', 'value' => 2], // 需要凭证
        ['metric_code' => 'coach_camp_recommend', 'value' => 0],
        ['metric_code' => 'coach_renew_count', 'value' => 0],
    ]
];

$saveCtx4 = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Host: 122.51.223.46\r\nContent-Type: application/json\r\nAuthorization: Bearer $token\r\n",
        'content' => json_encode($evidenceRequiredData),
        'ignore_errors' => true
    ]
]);
$saveResponse4 = file_get_contents("http://127.0.0.1/api/workload/save-report.php", false, $saveCtx4);
$saveData4 = json_decode($saveResponse4, true);
echo "凭证校验: " . ($saveData4['code'] === 400 && strpos($saveData4['message'], '凭证') !== false ? '正确拦截' : '校验失败') . "\n";