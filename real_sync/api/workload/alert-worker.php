<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('forbidden');
}

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/WorkloadAlertWorkerService.php';

try {
    $timezone = new DateTimeZone('Asia/Shanghai');
    $now = isset($argv[1]) && trim((string) $argv[1]) !== ''
        ? new DateTimeImmutable(trim((string) $argv[1]), $timezone)
        : new DateTimeImmutable('now', $timezone);
    $result = (new WorkloadAlertWorkerService(workloadDb()))->run($now);
    fwrite(STDOUT, '[workload.alert] ' . json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    error_log('[workload.alert] Error: ' . $error->getMessage());
    fwrite(STDERR, '[workload.alert] failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
