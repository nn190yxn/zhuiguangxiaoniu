<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('forbidden');
}

require_once __DIR__ . '/_common.php';

try {
    $pdo = reminderDb();
    reminderEnsureSchema($pdo);

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
        if (!in_array($phase, ['first', 'second', 'store_summary', 'hq_summary'], true)) {
            continue;
        }
        $generated = reminderBuildWorkloadJobs($pdo, $reportDate, $phase);
        $jobIds = [];
        foreach ($generated['jobs'] as $job) {
            $jobId = reminderUpsertJob($pdo, $job);
            if ($jobId > 0) {
                $jobIds[] = $jobId;
            }
        }

        $dispatchResults = [];
        foreach ($jobIds as $jobId) {
            $dispatchResults[] = reminderDispatchJob($pdo, $jobId);
        }

        $summary[] = [
            'phase' => $phase,
            'generated' => count($generated['jobs']),
            'stored' => count($jobIds),
            'skipped' => count($generated['skipped']),
            'dispatched' => count($dispatchResults),
        ];
    }

    fwrite(STDOUT, "[reminder.worker] date={$reportDate} result=" . json_encode($summary, JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    error_log('[reminder.worker] Error: ' . $e->getMessage());
    fwrite(STDERR, '[reminder.worker] failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
