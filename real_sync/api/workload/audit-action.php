<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/WorkloadAuditTaskService.php';
require_once __DIR__ . '/services/WorkloadAnalyticsCacheService.php';
handleCORS();

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        appJsonError(405, '不支持的请求方法');
    }

    $context = appRequireStaffContext();
    if (!appCanViewAll($context) && !in_array((string) ($context['role'] ?? ''), ['headquarters', 'operation'], true)) {
        appJsonError(403, '无权限处理审核任务');
    }

    $input = appInputArray();
    $taskId = appRequireInt($input, 'task_id', '任务 ID');
    $action = appRequireEnum($input, 'action', ['approved', 'rejected', 'needs_resubmit'], '操作');
    $comment = appOptionalString($input, 'comment');

    $pdo = workloadDb();
    workloadEnsureAuditSchema($pdo);

    $result = (new WorkloadAuditTaskService($pdo))->transition(
        $taskId,
        $action,
        (int) ($context['staff_id'] ?? 0),
        $comment
    );
    if (empty($result['idempotent'])) {
        (new WorkloadAnalyticsCacheService())->invalidate([
            'date' => $result['business_date'] ?? '',
            'store_id' => $result['store_id'] ?? 0,
            'staff_id' => $result['staff_id'] ?? 0,
            'role_code' => $result['role_code'] ?? '',
            'metric_code' => $result['metric_code'] ?? '',
        ]);
    }
    appJsonSuccess($result, '审核完成');
} catch (WorkloadAuditTaskException $e) {
    appJsonError($e->statusCode(), $e->getMessage());
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    appLogEvent('workload.audit_action_error', ['error' => $e->getMessage()]);
    appJsonError(500, '操作失败');
}
