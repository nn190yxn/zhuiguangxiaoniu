<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/admin/common.php';
require_once __DIR__ . '/../api/admin/services/StaffImportService.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must run in CLI.\n");
    exit(1);
}

$jsonPath = $argv[1] ?? '';
$operatorStaffId = (int)($argv[2] ?? 0);
$batchKey = trim((string)($argv[3] ?? ''));
if ($jsonPath === '' || !is_file($jsonPath) || $operatorStaffId <= 0) {
    fwrite(STDERR, "Usage: php import_staff_cli.php <json-file> <operator-staff-id> [batch-key]\n");
    exit(1);
}

$payload = json_decode((string)file_get_contents($jsonPath), true);
$records = is_array($payload) ? ($payload['records'] ?? $payload) : null;
if (!is_array($records)) {
    fwrite(STDERR, "Invalid JSON file.\n");
    exit(1);
}

$db = getDB();
$operatorStmt = $db->prepare(
    'SELECT s.* FROM staffs s '
    . "WHERE s.id = ? AND s.status = 1 AND s.lifecycle_status = 'active' LIMIT 1"
);
$operatorStmt->execute([$operatorStaffId]);
$operatorStaff = $operatorStmt->fetch(PDO::FETCH_ASSOC);
if (!$operatorStaff || (int)($operatorStaff['user_id'] ?? 0) <= 0) {
    fwrite(STDERR, "Operator staff must be active and linked to an account.\n");
    exit(1);
}

try {
    $result = (new StaffImportService($db))->import(
        array_values($records),
        $batchKey ?: (string)($payload['batch_key'] ?? ''),
        ['user_id' => (int)$operatorStaff['user_id']],
        $operatorStaff,
        [
            'file_name' => basename($jsonPath),
            'file_sha256' => hash_file('sha256', $jsonPath) ?: null,
        ]
    );
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
