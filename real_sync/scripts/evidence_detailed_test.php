<?php
// 详细凭证测试
if (PHP_SAPI !== 'cli') { exit; }

$coachPhone = '18385534850';
$password = '123456';

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

// 先保存草稿
echo "=== 1. 保存草稿 ===\n";
$draftData = [
    'report_date' => $date,
    'store_id' => 3,
    'role_code' => 'coach',
    'submit_status' => 'draft',
    'source' => 'test',
    'values' => [
        ['metric_code' => 'coach_plan_hours', 'value' => 1],
        ['metric_code' => 'coach_actual_hours', 'value' => 1],
        ['metric_code' => 'coach_plan_comm', 'value' => 0],
        ['metric_code' => 'coach_actual_comm', 'value' => 1],
        ['metric_code' => 'coach_body_test', 'value' => 2],
        ['metric_code' => 'coach_camp_recommend', 'value' => 0],
        ['metric_code' => 'coach_renew_count', 'value' => 0],
    ]
];

$saveCtx = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Host: 122.51.223.46\r\nContent-Type: application/json\r\nAuthorization: Bearer $token\r\n",
        'content' => json_encode($draftData),
        'ignore_errors' => true
    ]
]);
$saveResponse = file_get_contents("http://127.0.0.1/api/workload/save-report.php", false, $saveCtx);
$saveData = json_decode($saveResponse, true);
$reportId = $saveData['data']['report_id'] ?? 0;
echo "草稿保存: " . ($saveData['code'] === 0 ? '成功' : '失败') . ", ID: $reportId\n";

// 直接尝试提交（不上传凭证）
echo "=== 2. 尝试提交（无凭证）===\n";
$submitData = [
    'report_date' => $date,
    'store_id' => 3,
    'role_code' => 'coach',
    'submit_status' => 'submitted',
    'source' => 'test',
    'values' => [
        ['metric_code' => 'coach_plan_hours', 'value' => 1],
        ['metric_code' => 'coach_actual_hours', 'value' => 1],
        ['metric_code' => 'coach_plan_comm', 'value' => 0],
        ['metric_code' => 'coach_actual_comm', 'value' => 1],
        ['metric_code' => 'coach_body_test', 'value' => 2],
        ['metric_code' => 'coach_camp_recommend', 'value' => 0],
        ['metric_code' => 'coach_renew_count', 'value' => 0],
    ]
];

$submitCtx = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Host: 122.51.223.46\r\nContent-Type: application/json\r\nAuthorization: Bearer $token\r\n",
        'content' => json_encode($submitData),
        'ignore_errors' => true
    ]
]);
$submitResponse = file_get_contents("http://127.0.0.1/api/workload/save-report.php", false, $submitCtx);
$submitData = json_decode($submitResponse, true);
echo "提交结果: Code=" . $submitData['code'] . ", Message=" . $submitData['message'] . "\n";

// 检查数据库中是否有任务生成
echo "=== 3. 检查数据库任务 ===\n";
$pdo = new PDO('mysql:host=localhost;dbname=_122_51_223_46', '_122_51_223_46', 'Yaoxiuning190');
$stmt = $pdo->prepare("SELECT COUNT(*) FROM workload_audit_tasks WHERE report_id = ?");
$stmt->execute([$reportId]);
$taskCount = $stmt->fetchColumn();
echo "审核任务数量: $taskCount\n";