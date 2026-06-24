<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

handleCORS();

try {
    $context = appRequireStaffContext();
    if (!appCanEditAll($context)) {
        appJsonError(403, '无权限执行提醒任务');
    }

    $pdo = reminderDb();
    reminderEnsureSchema($pdo);

    $input = $_SERVER['REQUEST_METHOD'] === 'POST' ? appInputArray() : $_GET;
    $reportDate = appOptionalString($input, 'date', reminderNow()->format('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate)) {
        appJsonError(400, '日期格式必须为YYYY-MM-DD');
    }
    $action = appOptionalString($input, 'action', $_SERVER['REQUEST_METHOD'] === 'POST' ? 'run' : 'preview');
    if (!in_array($action, ['preview', 'run'], true)) {
        appJsonError(400, 'action 不支持');
    }

    $generated = reminderBuildLearningJobs($pdo, $reportDate);
    if ($action === 'preview') {
        appLogEvent('reminder.learning_required_preview', ['staff_id' => $context['staff_id'] ?? null, 'date' => $reportDate]);
        appJsonSuccess([
            'filters' => ['date' => $reportDate, 'action' => $action],
            'summary' => [
                'job_count' => count($generated['jobs']),
                'skipped_count' => count($generated['skipped']),
                'pending_count' => count($generated['pending_rows']),
            ],
            'jobs' => $generated['jobs'],
            'skipped' => $generated['skipped'],
            'pending_rows' => $generated['pending_rows'],
        ]);
    }

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

    appLogEvent('reminder.learning_required_run', ['staff_id' => $context['staff_id'] ?? null, 'date' => $reportDate, 'job_count' => count($jobIds)]);
    appJsonSuccess([
        'filters' => ['date' => $reportDate, 'action' => $action],
        'summary' => [
            'generated_job_count' => count($generated['jobs']),
            'stored_job_count' => count($jobIds),
            'skipped_count' => count($generated['skipped']),
            'pending_count' => count($generated['pending_rows']),
        ],
        'dispatch_results' => $dispatchResults,
        'skipped' => $generated['skipped'],
    ], '执行完成');
} catch (Throwable $e) {
    appLogEvent('reminder.learning_required_error', ['error' => $e->getMessage()]);
    appJsonError(500, '执行学习提醒失败');
}
