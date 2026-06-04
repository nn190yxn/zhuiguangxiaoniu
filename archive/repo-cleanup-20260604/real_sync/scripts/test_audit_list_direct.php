<?php
if (PHP_SAPI !== 'cli') { exit; }

// Direct test of audit-list.php with proper token
$hToken = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJzdGFmZl9pZCI6NDUsInN0b3JlX2lkIjpudWxsLCJyb2xlIjoib3BlcmF0aW9uIiwiZXhwIjoxNzE1MzI0ODAwfQ.ZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZ';

$ctx = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => "Host: 122.51.223.46\r\nAuthorization: Bearer $hToken\r\n",
        'ignore_errors' => true
    ]
]);

$response = file_get_contents("http://127.0.0.1/api/workload/audit-list.php", false, $ctx);
echo "Response: " . $response . "\n";