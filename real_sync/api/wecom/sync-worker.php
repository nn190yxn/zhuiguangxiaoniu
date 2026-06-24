<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('forbidden');
}

require_once __DIR__ . '/_common.php';

try {
    $pdo = wecomDb();
    $rootDepartmentId = isset($argv[1]) ? (int)$argv[1] : wecomRootDepartmentId();
    $result = wecomSyncMembers($pdo, ['root_department_id' => $rootDepartmentId]);
    $logId = wecomWriteSyncLog($pdo, [
        'sync_type' => 'members',
        'status' => 'success',
        'departments_total' => $result['departments_total'],
        'users_total' => $result['users_total'],
        'matched_total' => $result['matched_total'],
        'updated_total' => $result['updated_total'],
        'unbound_total' => $result['unbound_total'],
        'deactivated_total' => $result['deactivated_total'],
        'payload' => $result,
    ]);

    fwrite(STDOUT, '[wecom.sync] log_id=' . $logId . ' result=' . json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    error_log('[wecom.sync] Error: ' . $e->getMessage());
    fwrite(STDERR, '[wecom.sync] failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
