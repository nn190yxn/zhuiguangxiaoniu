<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('forbidden');
}

require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__) . '/platform/JobQueue.php';

$timezone = new DateTimeZone('Asia/Shanghai');
$rawDate = trim((string) ($argv[1] ?? ''));
$date = $rawDate === '' ? new DateTimeImmutable('now', $timezone) : DateTimeImmutable::createFromFormat('!Y-m-d', $rawDate, $timezone);
if (!$date || $date->format('Y-m-d') !== ($rawDate === '' ? $date->format('Y-m-d') : $rawDate)) {
    fwrite(STDERR, "[workload.alert.queue] invalid business date\n");
    exit(2);
}

$businessDate = $date->format('Y-m-d');
$slot = $date->format('Y-m-d-H-i');
$pdo = workloadDb();
$pdo->beginTransaction();
try {
    $job = (new PlatformJobQueueService(new PlatformPdoJobQueueStore($pdo)))->enqueue(
        'workload.alert.run',
        'workload_alert_schedule',
        $slot,
        hash('sha256', 'workload.alert.run:' . $slot),
        ['now' => $date->format('Y-m-d H:i:s')],
        20,
        3
    );
    $pdo->commit();
    fwrite(STDOUT, '[workload.alert.queue] ' . json_encode([
        'job_id' => (int) ($job['id'] ?? 0),
        'status' => (string) ($job['status'] ?? 'pending'),
        'business_date' => $businessDate,
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[workload.alert.queue] failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
