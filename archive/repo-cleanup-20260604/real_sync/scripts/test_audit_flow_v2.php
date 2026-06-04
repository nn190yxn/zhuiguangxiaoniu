<?php
if (PHP_SAPI !== 'cli') { exit; }

// Use fixed test tokens that work
$cToken = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJzdGFmZl9pZCI6MzUsInN0b3JlX2lkIjozLCJyb2xlIjoiY29hY2giLCJleHAiOjE3MTUzMjQ4MDB9.XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX';
$hToken = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJzdGFmZl9pZCI6MSwic3RvcmVfaWQiOjEsInJvbGUiOiJoZWFkcXVhcnRlcnMiLCJleHAiOjE3MTUzMjQ4MDB9.YYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYY';

$date = date('Y-m-d');

echo "=== 1. Coach Save Draft ===\n";
$res = save($cToken, $date, 3, 'coach', [
    ['metric_code' => 'coach_plan_hours', 'value' => 1],
    ['metric_code' => 'coach_actual_hours', 'value' => 1],
    ['metric_code' => 'coach_plan_comm', 'value' => 0],
    ['metric_code' => 'coach_actual_comm', 'value' => 1],
    ['metric_code' => 'coach_body_test', 'value' => 2], // Needs evidence
    ['metric_code' => 'coach_camp_recommend', 'value' => 0],
    ['metric_code' => 'coach_renew_count', 'value' => 0],
], 'draft');
echo "Draft Code: " . $res['code'] . " ID: " . ($res['data']['report_id'] ?? 'null') . "\n";
$rid = $res['data']['report_id'] ?? 0;

echo "=== 2. Try to Submit without Evidence ===\n";
$res2 = save($cToken, $date, 3, 'coach', [
    ['metric_code' => 'coach_plan_hours', 'value' => 1],
    ['metric_code' => 'coach_actual_hours', 'value' => 1],
    ['metric_code' => 'coach_plan_comm', 'value' => 0],
    ['metric_code' => 'coach_actual_comm', 'value' => 1],
    ['metric_code' => 'coach_body_test', 'value' => 2], // Missing evidence
    ['metric_code' => 'coach_camp_recommend', 'value' => 0],
    ['metric_code' => 'coach_renew_count', 'value' => 0],
], 'submitted');
echo "Submit Code: " . $res2['code'] . " Msg: " . $res2['message'] . "\n";

if ($rid > 0) {
    echo "=== 3. Upload Evidence ===\n";
    $pixel = base64_encode("\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\nIDATx\x9cc\x00\x01\x00\x00\x05\x00\x01\r\n-\xb4\x00\x00\x00\x00IEND\xaeB`\x82");
    $img = "data:image/png;base64,$pixel";
    $up = upload($cToken, $rid, 'coach_body_test', $img);
    echo "Upload Code: " . $up['code'] . "\n";

    echo "=== 4. Submit with Evidence ===\n";
    $res3 = save($cToken, $date, 3, 'coach', [
        ['metric_code' => 'coach_plan_hours', 'value' => 1],
        ['metric_code' => 'coach_actual_hours', 'value' => 1],
        ['metric_code' => 'coach_plan_comm', 'value' => 0],
        ['metric_code' => 'coach_actual_comm', 'value' => 1],
        ['metric_code' => 'coach_body_test', 'value' => 2], // Has evidence now
        ['metric_code' => 'coach_camp_recommend', 'value' => 0],
        ['metric_code' => 'coach_renew_count', 'value' => 0],
    ], 'submitted');
    echo "Submit Code: " . $res3['code'] . " Msg: " . $res3['message'] . "\n";

    echo "=== 5. HQ Check Audit Tasks ===\n";
    $tasks = getTasks($hToken);
    echo "Tasks Count: " . count($tasks) . "\n";
    if (count($tasks) > 0) {
        echo "PASS: Audit tasks generated correctly.\n";
    } else {
        echo "FAIL: No audit tasks generated.\n";
    }
} else {
    echo "Skipping steps 3-5 because Draft failed.\n";
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
    $ctx = stream_context_create(['http'=>['method'=>$m, 'header'=>implode("\r\n", $h), 'content'=>$b, 'ignore_errors'=>true]]);
    return json_decode(file_get_contents("http://127.0.0.1$u", false, $ctx), true) ?? [];
}