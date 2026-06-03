<?php
// 璇︾粏鍑瘉娴嬭瘯
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
    echo "鐧诲綍澶辫触\n";
    exit(1);
}

$date = date('Y-m-d');

// 鍏堜繚瀛樿崏绋?
echo "=== 1. 淇濆瓨鑽夌 ===\n";
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
echo "鑽夌淇濆瓨: " . ($saveData['code'] === 0 ? '鎴愬姛' : '澶辫触') . ", ID: $reportId\n";

// 鐩存帴灏濊瘯鎻愪氦锛堜笉涓婁紶鍑瘉锛?
echo "=== 2. 灏濊瘯鎻愪氦锛堟棤鍑瘉锛?==\n";
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
echo "鎻愪氦缁撴灉: Code=" . $submitData['code'] . ", Message=" . $submitData['message'] . "\n";

// 妫€鏌ユ暟鎹簱涓槸鍚︽湁浠诲姟鐢熸垚
echo "=== 3. 妫€鏌ユ暟鎹簱浠诲姟 ===\n";
$pdo = new PDO('mysql:host=localhost;dbname=_122_51_223_46', '_122_51_223_46', '<通过安全渠道获取>');
$stmt = $pdo->prepare("SELECT COUNT(*) FROM workload_audit_tasks WHERE report_id = ?");
$stmt->execute([$reportId]);
$taskCount = $stmt->fetchColumn();
echo "瀹℃牳浠诲姟鏁伴噺: $taskCount\n";