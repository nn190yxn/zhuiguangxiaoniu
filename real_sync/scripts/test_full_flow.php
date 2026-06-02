<?php
if (PHP_SAPI !== 'cli') { exit; }
$login = json_decode(file_get_contents('http://127.0.0.1/api/auth-jwt.php', false, stream_context_create(['http'=>['method'=>'POST','header'=>"Host: 122.51.223.46\r\nContent-Type: application/json",'content'=>json_encode(['username'=>'18385534850','password'=>'123456'])]])), true);
$token = $login['data']['token'];

// Save Draft
$data = [
    'report_date'=>date('Y-m-d'), 'store_id'=>3, 'role_code'=>'coach', 
    'submit_status'=>'draft', 'source'=>'debug', 
    'values'=>[
        ['metric_code'=>'coach_plan_hours','value'=>2],
        ['metric_code'=>'coach_actual_hours','value'=>2],
        ['metric_code'=>'coach_plan_comm','value'=>0],
        ['metric_code'=>'coach_actual_comm','value'=>1],
        ['metric_code'=>'coach_body_test','value'=>2], // Required Evidence
        ['metric_code'=>'coach_camp_recommend','value'=>0],
        ['metric_code'=>'coach_renew_count','value'=>0]
    ]
];
$ctx = stream_context_create(['http'=>['method'=>'POST','header'=>"Host: 122.51.223.46\r\nContent-Type: application/json\r\nAuthorization: Bearer $token",'content'=>json_encode($data),'ignore_errors'=>true]]);
$res = json_decode(file_get_contents('http://127.0.0.1/api/workload/save-report.php', false, $ctx), true);
echo "Save Draft: " . $res['code'] . " ID:" . $res['data']['report_id'] . "\n";
$reportId = $res['data']['report_id'];

// Upload Evidence
$pixel = base64_encode("\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\nIDATx\x9cc\x00\x01\x00\x00\x05\x00\x01\r\n-\xb4\x00\x00\x00\x00IEND\xaeB`\x82");
$img = "data:image/png;base64,$pixel";
$upData = ['report_id'=>$reportId, 'metric_code'=>'coach_body_test', 'image_data'=>$img];
$ctx2 = stream_context_create(['http'=>['method'=>'POST','header'=>"Host: 122.51.223.46\r\nContent-Type: application/json\r\nAuthorization: Bearer $token",'content'=>json_encode($upData),'ignore_errors'=>true]]);
$upRes = json_decode(file_get_contents('http://127.0.0.1/api/workload/evidence-upload.php', false, $ctx2), true);
echo "Upload: " . $upRes['code'] . " Msg:" . $upRes['message'] . "\n";

// Submit
$data['submit_status'] = 'submitted';
$ctx3 = stream_context_create(['http'=>['method'=>'POST','header'=>"Host: 122.51.223.46\r\nContent-Type: application/json\r\nAuthorization: Bearer $token",'content'=>json_encode($data),'ignore_errors'=>true]]);
$res3 = json_decode(file_get_contents('http://127.0.0.1/api/workload/save-report.php', false, $ctx3), true);
echo "Submit: " . $res3['code'] . " Msg:" . $res3['message'] . "\n";
