<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/WorkloadAuditTaskService.php';
handleCORS();

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        appJsonError(405, '不支持的请求方法');
    }

    $context = appRequireStaffContext();
    $input = appInputArray();
    $taskId = appRequireInt($input, 'task_id', '任务 ID');
    $staffId = (int) ($context['staff_id'] ?? 0);

    $pdo = workloadDb();
    workloadEnsureAuditSchema($pdo);
    $result = (new WorkloadAuditTaskService($pdo))->requestReaudit($taskId, $staffId);

    appJsonSuccess($result, $result['idempotent'] ? '该任务已重新送审' : '重新送审成功');
} catch (WorkloadAuditTaskException|WorkloadRoleRuleVersionException $e) {
    appJsonError($e->statusCode(), $e->getMessage());
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    appLogEvent('workload.audit_resubmit_error', ['error' => $e->getMessage()]);
    appJsonError(500, '重新送审失败');
}
