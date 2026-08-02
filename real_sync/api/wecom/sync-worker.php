<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('forbidden');
}

require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__) . '/platform/JobQueue.php';

$pdo = null;

try {
    $pdo = wecomDb();
    $rootDepartmentId = isset($argv[1]) ? (int)$argv[1] : wecomRootDepartmentId();
    $rootDepartmentId = max(1, $rootDepartmentId);
    platformRequireMigrationReadiness($pdo, ['202607310010', '202607310012']);
    $slot = (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('YmdHi');
    $pdo->beginTransaction();
    try {
        $queue = new PlatformJobQueueService(new PlatformPdoJobQueueStore($pdo));
        $job = $queue->enqueue(
            'wecom.members.sync',
            'wecom_department',
            (string)$rootDepartmentId,
            hash('sha256', 'wecom.members.sync:' . $rootDepartmentId . ':' . $slot),
            ['root_department_id' => $rootDepartmentId, 'schedule_slot' => $slot],
            10,
            3
        );
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
    fwrite(STDOUT, '[wecom.sync] queued job_id=' . (int)$job['id'] . ' root_department_id=' . $rootDepartmentId . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    error_log('[wecom.sync] Error: ' . $e->getMessage());
    fwrite(STDERR, '[wecom.sync] failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
