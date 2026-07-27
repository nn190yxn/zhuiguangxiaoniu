<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('forbidden');
}

require_once __DIR__ . '/services/WorkloadObligationBackfillService.php';
require_once __DIR__ . '/services/WorkloadAnalyticsCacheService.php';

try {
    $fromDate = isset($argv[1]) ? trim((string) $argv[1]) : '';
    $toDate = isset($argv[2]) && trim((string) $argv[2]) !== ''
        ? trim((string) $argv[2])
        : $fromDate;
    if ($fromDate === '') {
        throw new WorkloadObligationBackfillValidationException(
            '用法: php obligation-backfill-worker.php YYYY-MM-DD [YYYY-MM-DD]'
        );
    }

    $result = (new WorkloadObligationBackfillService(getDB()))->backfill($fromDate, $toDate);
    (new WorkloadAnalyticsCacheService())->invalidate(['date_from' => $fromDate, 'date_to' => $toDate]);
    fwrite(STDOUT, '[workload.obligation-backfill] '
        . json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    error_log('[workload.obligation-backfill] Error: ' . $e->getMessage());
    fwrite(STDERR, '[workload.obligation-backfill] failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
