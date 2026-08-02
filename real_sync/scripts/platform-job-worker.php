<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('forbidden');
}

require_once dirname(__DIR__) . '/api/platform/JobDispatcher.php';

$maxJobs = 1;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--max-jobs=(\d+)$/', $argument, $matches)) {
        $maxJobs = (int)$matches[1];
    } else {
        fwrite(STDERR, "usage: php scripts/platform-job-worker.php --max-jobs=1..100\n");
        exit(2);
    }
}
if ($maxJobs < 1 || $maxJobs > 100) {
    fwrite(STDERR, "max-jobs must be between 1 and 100\n");
    exit(2);
}

try {
    require_once dirname(__DIR__) . '/api/config.php';
    $db = getDB();
    $store = new PlatformPdoJobStore($db);
    $workerId = PlatformJobRunner::defaultWorkerId('platform-worker');
    $runner = new PlatformJobRunner($store, new PlatformRetryPolicy(), $workerId);
    $handlers = [];
    $registry = dirname(__DIR__) . '/api/platform/jobs/registry.php';
    if (is_file($registry)) {
        $loaded = require $registry;
        if (is_array($loaded)) {
            $handlers = $loaded;
        }
    }
    $dispatcher = new PlatformJobDispatcher($runner, $handlers, static function (PlatformJobLease $lease, string $code, string $summary) use ($store): void {
        if (!$store->deadLetter($lease, new DateTimeImmutable('now'), $code, $summary)) {
            throw new PlatformJobLeaseLost();
        }
    });
    $summary = $dispatcher->run($maxJobs);
    $summary['worker_id'] = $workerId;
    echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['status' => 'failed', 'error' => $error->getMessage()], JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}
