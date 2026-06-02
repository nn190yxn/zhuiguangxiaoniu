<?php
// Test script to verify HQ permissions and audit list access
if (PHP_SAPI !== 'cli') { exit; }

// Use the working test flow to get valid tokens
$coach = '18385534850';
$hq = '13668501068';

// Step 1: Login as coach and create a report with evidence
echo "=== Creating report with evidence ===\n";
$cToken = login($coach);
$date = date('Y-m-d');
$res = save($cToken, $date, 3, 'coach', [
    ['metric_code' => 'coach_plan_hours', 'value' => 1],
    ['metric_code' => 'coach_actual_hours', 'value' => 1],
    ['metric_code' => 'coach_plan_comm', 'value' => 0],
    ['metric_code' => 'coach_actual_comm', 'value' => 1],
    ['metric_code' => 'coach_body_test', 'value' => 2], // Needs evidence
    ['metric_code' => 'coach_camp_recommend', 'value' => 0],
    ['metric_code' => 'coach_renew_count', 'value' => 0],
], 'draft');
$rid = $res['data']['report_id'] ?? 0;
echo "Report ID: $rid\n";

// Upload evidence
$pixel = base64_encode("\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\nIDATx\x9cc\x00\x01\x00\x00\x05\x00\x01\r\n-\xb4\x00\x00\x00\x00IEND\xaeB`\x82");
$img = "data:image/png;base64,$pixel";
upload($cToken, $rid, 'coach_body_test', $img);

// Submit with evidence
save($cToken, $date, 3, 'coach', [
    ['metric_code' => 'coach_plan_hours', 'value' => 1],
    ['metric_code' => 'coach_actual_hours', 'value' => 1],
    ['metric_code' => 'coach_plan_comm', 'value' => 0],
    ['metric_code' => 'coach_actual_comm', 'value' => 1],
    ['metric_code' => 'coach_body_test', 'value' => 2],
    ['metric_code' => 'coach_camp_recommend', 'value' => 0],
    ['metric_code' => 'coach_renew_count', 'value' => 0],
], 'submitted');

// Step 2: Login as HQ and check audit list
echo "=== Checking HQ audit list access ===\n";
$hToken = login($hq);
$tasks = getTasks($hToken);
echo "Audit tasks found: " . count($tasks) . "\n";

if (count($tasks) > 0) {
    echo "SUCCESS: HQ can access audit tasks!\n";
    // Test approval
    $taskId = $tasks[0]['id'];
    $result = auditAction($hToken, $taskId, 'approved', 'Test approval');
    echo "Approval result: " . ($result['code'] === 0 ? 'SUCCESS' : 'FAILED') . "\n";
} else {
    echo "ISSUE: No audit tasks visible to HQ\n";
    
    // Debug: Check database directly
    $pdo = new PDO('mysql:host=localhost;dbname=_122_51_223_46', '_122_51_223_46', 'Yaoxiuning190');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM workload_audit_tasks WHERE report_id = ?");
    $stmt->execute([$rid]);
    $dbCount = $stmt->fetchColumn();
    echo "Tasks in database for report $rid: $dbCount\n";
}

function login($phone) {
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Host: 122.51.223.46\r\nContent-Type: application/json\r\n",
            'content' => json_encode(['username' => $phone, 'password' => '123456']),
            'ignore_errors' => true
        ]
    ]);
    $response = file_get_contents("http://127.0.0.1/api/auth-jwt.php", false, $context);
    $data = json_decode($response, true);
    return $data['data']['token'] ?? '';
}

function save($token, $date, $storeId, $role, $values, $status) {
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Host: 122.51.223.46\r\nContent-Type: application/json\r\nAuthorization: Bearer $token\r\n",
            'content' => json_encode([
                'report_date' => $date,
                'store_id' => $storeId,
                'role_code' => $role,
                'submit_status' => $status,
                'source' => 'test',
                'values' => $values
            ]),
            'ignore_errors' => true
        ]
    ]);
    $response = file_get_contents("http://127.0.0.1/api/workload/save-report.php", false, $context);
    return json_decode($response, true) ?? [];
}

function upload($token, $reportId, $metricCode, $imageData) {
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Host: 122.51.223.46\r\nContent-Type: application/json\r\nAuthorization: Bearer $token\r\n",
            'content' => json_encode([
                'report_id' => $reportId,
                'metric_code' => $metricCode,
                'image_data' => $imageData
            ]),
            'ignore_errors' => true
        ]
    ]);
    $response = file_get_contents("http://127.0.0.1/api/workload/evidence-upload.php", false, $context);
    return json_decode($response, true) ?? [];
}

function getTasks($token) {
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Host: 122.51.223.46\r\nAuthorization: Bearer $token\r\n",
            'ignore_errors' => true
        ]
    ]);
    $response = file_get_contents("http://127.0.0.1/api/workload/audit-list.php", false, $context);
    $data = json_decode($response, true);
    return $data['data']['list'] ?? [];
}

function auditAction($token, $taskId, $action, $comment) {
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Host: 122.51.223.46\r\nContent-Type: application/json\r\nAuthorization: Bearer $token\r\n",
            'content' => json_encode([
                'task_id' => $taskId,
                'action' => $action,
                'comment' => $comment
            ]),
            'ignore_errors' => true
        ]
    ]);
    $response = file_get_contents("http://127.0.0.1/api/workload/audit-action.php", false, $context);
    return json_decode($response, true) ?? [];
}