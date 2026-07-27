<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('forbidden');
}

require_once __DIR__ . '/services/WorkloadReportStateService.php';

try {
    $now = null;
    if (isset($argv[1]) && trim((string) $argv[1]) !== '') {
        $timezone = new DateTimeZone('Asia/Shanghai');
        $value = trim((string) $argv[1]);
        $now = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if (
            !$now
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $now->format('Y-m-d H:i:s') !== $value
        ) {
            throw new WorkloadReportStateException('锁定时间格式必须为 YYYY-MM-DD HH:MM:SS');
        }
    }
    $result = (new WorkloadReportStateService(getDB()))->lockExpired($now);
    fwrite(STDOUT, '[workload.obligation-lock] '
        . json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    error_log('[workload.obligation-lock] Error: ' . $e->getMessage());
    fwrite(STDERR, '[workload.obligation-lock] failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
