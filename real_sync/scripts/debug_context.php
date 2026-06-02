<?php
// Debug context test
if (PHP_SAPI !== 'cli') { exit; }

require_once '/www/wwwroot/122.51.223.46/api/common/context.php';

// Simulate operation user login
$operationPhone = '18285031172';
$staff = [
    'id' => 47,
    'role' => 'operation',
    'store_id' => 1,
    'phone' => $operationPhone,
    'name' => 'Test Operation'
];

$context = appGetCurrentStaffContext();
echo "Current context: " . json_encode($context) . "\n";

// Test permissions directly
$isHq = in_array('operation', ['operation', 'finance'], true);
echo "isHq for operation: " . ($isHq ? 'true' : 'false') . "\n";

$permissions = [
    'can_view_all' => $isHq,
];
echo "Permissions: " . json_encode($permissions) . "\n";