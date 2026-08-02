<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('forbidden');
}

require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__) . '/platform/JobQueue.php';

try {
    $pdo = reminderDb();
    reminderEnsureSchema($pdo);
    platformRequireMigrationReadiness($pdo, ['202607310010', '202607310012']);

    $dateArg = isset($argv[1]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$argv[1]) ? (string)$argv[1] : '';
    $phaseArg = isset($argv[2]) ? trim((string)$argv[2]) : '';
    $now = reminderNow();
    $reportDate = $dateArg !== '' ? $dateArg : $now->format('Y-m-d');
    $phases = $phaseArg !== '' ? [$phaseArg] : reminderDuePhases($now);

    if (!$phases) {
        fwrite(STDOUT, "[reminder.worker] no phase due at " . $now->format('Y-m-d H:i:s') . PHP_EOL);
        exit(0);
    }

    $summary = [];
    foreach ($phases as $phase) {
        if (!in_array($phase, ['learning_required', 'first', 'second', 'store_summary', 'hq_summary'], true)) {
            continue;
        }
        $pdo->beginTransaction();
        try {
            $queue = new PlatformJobQueueService(new PlatformPdoJobQueueStore($pdo));
            $job = $queue->enqueue(
                'reminder.schedule.tick',
                'reminder_schedule',
                $reportDate . ':' . $phase,
                hash('sha256', 'reminder.schedule.tick:' . $reportDate . ':' . $phase),
                ['report_date' => $reportDate, 'phase' => $phase],
                10,
                3
            );
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
        $summary[] = ['phase' => $phase, 'job_id' => (int)$job['id'], 'status' => (string)$job['status']];
    }

    fwrite(STDOUT, "[reminder.worker] date={$reportDate} queued=" . json_encode($summary, JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    error_log('[reminder.worker] Error: ' . $e->getMessage());
    fwrite(STDERR, '[reminder.worker] failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
