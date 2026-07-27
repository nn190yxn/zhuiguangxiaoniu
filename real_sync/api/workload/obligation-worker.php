<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('forbidden');
}

require_once __DIR__ . '/services/WorkloadObligationService.php';
require_once __DIR__ . '/services/WorkloadAnalyticsCacheService.php';

try {
    $timezone = new DateTimeZone('Asia/Shanghai');
    $businessDate = isset($argv[1]) && trim((string) $argv[1]) !== ''
        ? trim((string) $argv[1])
        : (new DateTimeImmutable('now', $timezone))->format('Y-m-d');
    $result = (new WorkloadObligationService(getDB()))->generateForDate($businessDate);
    (new WorkloadAnalyticsCacheService())->invalidate(['date' => $businessDate]);
    fwrite(STDOUT, '[workload.obligation] ' . json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    error_log('[workload.obligation] Error: ' . $e->getMessage());
    fwrite(STDERR, '[workload.obligation] failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
