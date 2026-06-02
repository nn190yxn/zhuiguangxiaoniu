<?php
if (PHP_SAPI !== 'cli') { exit(1); }

$phone = $argv[1] ?? '18385534850';
$date = date('Y-m-d');
$token = login($phone);
if (!$token) { echo "LOGIN FAIL\n"; exit; }
echo "LOGIN OK\n";

// 1. Save Draft
$res = save($token, $date, 3, 'coach', [
    ['metric_code' => 'coach_plan_hours', 'value' => 5],
    ['metric_code' => 'coach_actual_hours', 'value' => 5],
    ['metric_code' => 'coach_body_test', 'value' => 2], // Required Evidence
], 'draft');
echo "DRAFT CODE: " . ($res['code'] ?? 'null') . "\n";
echo "DRAFT ID: " . ($res['data']['report_id'] ?? 'null') . "\n";
$reportId = $res['data']['report_id'];

// 2. Upload Evidence for coach_body_test (requires 1 image)
$pixel = base64_encode("\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\nIDATx\x9cc\x00\x01\x00\x00\x05\x00\x01\r\n-\xb4\x00\x00\x00\x00IEND\xaeB`\x82");
$img = "data:image/png;base64,$pixel";
$upload = upload($token, $reportId, 'coach_body_test', $img);
echo "UPLOAD CODE: " . ($upload['code'] ?? 'null') . "\n";

// 3. Try to Submit without enough evidence (simulate by saving as submitted again)
// But we already uploaded 1, so it should pass?
// Let's test failure case: metric with value > 0 but NO evidence.
// coach_actual_comm is NOT required evidence?
// Let's check rules:
// coach_body_test is required.
// Let's try submitting without uploading evidence for it.
// To do this, I need to clear the upload or use a different report.
// Let's use a NEW report ID.

$res2 = save($token, $date, 3, 'coach', [
    ['metric_code' => 'coach_plan_hours', 'value' => 5],
    ['metric_code' => 'coach_body_test', 'value' => 3], // Required
], 'submitted'); // Missing Evidence
echo "SUBMIT NO EVID CODE: " . ($res2['code'] ?? 'null') . "\n";
echo "SUBMIT NO EVID MSG: " . ($res2['message'] ?? '') . "\n";

// 4. Now test Success case: Upload evidence then submit.
// Use report ID from step 1 (which was draft).
// We already uploaded evidence for it.
// Now update it to submitted.
$res3 = save($token, $date, 3, 'coach', [
    ['metric_code' => 'coach_plan_hours', 'value' => 5],
    ['metric_code' => 'coach_actual_hours', 'value' => 5],
    ['metric_code' => 'coach_body_test', 'value' => 2],
], 'submitted');
echo "SUBMIT WITH EVID CODE: " . ($res3['code'] ?? 'null') . "\n";
echo "SUBMIT WITH EVID MSG: " . ($res3['message'] ?? '') . "\n";

// 5. Check Audit Tasks
// Should be 1 task for coach_body_test? No, coach_body_test is required evidence, but is it audit_mode=full?
// Let's check.
$tasks = getTasks($token);
echo "TASKS COUNT: " . count($tasks) . "\n";

function login($p) {
    $r = req('POST', '/api/auth-jwt.php', json_encode(['username'=>$p, 'password'=>'123456']));
    return $r['data']['token'] ?? '';
}

function save($t, $d, $sid, $role, $vals, $status) {
    return req('POST', '/api/workload/save-report.php', json_encode([
        'report_date'=>$d, 'store_id'=>$sid, 'role_code'=>$role,
        'submit_status'=>$status, 'source'=>'test', 'values'=>$vals
    ]), $t);
}

function upload($t, $rid, $mc, $img) {
    return req('POST', '/api/workload/evidence-upload.php', json_encode([
        'report_id'=>$rid, 'metric_code'=>$mc, 'image_data'=>$img
    ]), $t);
}

function getTasks($t) {
    $r = req('GET', '/api/workload/audit-list.php', null, $t);
    return $r['data']['list'] ?? [];
}

function req($m, $u, $b, $tok=null) {
    $h = ['Host: 122.51.223.46', 'Content-Type: application/json'];
    if ($tok) $h[] = "Authorization: Bearer $tok";
    $ctx = stream_context_create(['http'=>['method'=>$m, 'header'=>implode("\r\n", $h), 'content'=>$b]]);
    return json_decode(file_get_contents("http://127.0.0.1$u", false, $ctx), true) ?? [];
}
