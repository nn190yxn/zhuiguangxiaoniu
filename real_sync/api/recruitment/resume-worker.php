<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/admin/recruitment/_common.php';
require_once dirname(__DIR__) . '/admin/recruitment/services/ResumeWorkerService.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
    $configuredToken = trim((string) getenv('RECRUITMENT_WORKER_TOKEN'));
    $providedToken = trim((string) ($_SERVER['HTTP_X_WORKER_TOKEN'] ?? ''));
    if ($configuredToken === '' || $providedToken === '' || !hash_equals($configuredToken, $providedToken)) {
        jsonResponse(403, 'Worker 访问令牌无效', null);
    }
}

try {
    $limit = PHP_SAPI === 'cli'
        ? (int) ($argv[1] ?? getenv('RECRUITMENT_WORKER_CONCURRENCY') ?: 4)
        : (int) ($_GET['limit'] ?? getenv('RECRUITMENT_WORKER_CONCURRENCY') ?: 4);
    $result = (new ResumeWorkerService(getDB()))->run($limit);
    if (PHP_SAPI === 'cli') {
        fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(0);
    }
    jsonResponse(0, 'Worker 执行完成', $result);
} catch (Throwable $error) {
    error_log('[recruitment.worker] ' . $error->getMessage());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $error->getMessage() . PHP_EOL);
        exit(1);
    }
    jsonResponse(500, 'Worker 执行失败', null);
}
