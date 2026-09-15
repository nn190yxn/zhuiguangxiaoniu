<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/WorkloadAuditTaskService.php';
require_once __DIR__ . '/services/WorkloadManagementConfirmationService.php';
require_once __DIR__ . '/services/WorkloadAnalyticsCacheService.php';
handleCORS();

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        appJsonError(405, '不支持的请求方法');
    }
    $context = appRequireStaffContext();
    $input = appInputArray();
    $reportId = appRequireInt($input, 'report_id', '日报 ID');
    $metricCode = appRequireString($input, 'metric_code', '指标代码');
    $comment = appOptionalString($input, 'comment');
    $pdo = workloadDb();
    $pdo->beginTransaction();
    $result = (new WorkloadManagementConfirmationService($pdo))->confirm($reportId, $metricCode, $context, $comment);
    $pdo->commit();
    (new WorkloadAnalyticsCacheService())->invalidate([
        'store_id' => $result['store_id'],
        'staff_id' => $result['staff_id'],
        'role_code' => $result['role_code'],
        'metric_code' => $result['metric_code'],
    ]);
    appJsonSuccess($result, '管理动作已确认');
} catch (WorkloadAuditTaskException $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    appJsonError($e->statusCode(), $e->getMessage());
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    appLogEvent('workload.management_confirmation_error', ['error' => $e->getMessage()]);
    appJsonError(500, '确认管理动作失败');
}
