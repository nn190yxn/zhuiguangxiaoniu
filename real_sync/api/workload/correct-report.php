<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/WorkloadReportStateService.php';
require_once __DIR__ . '/services/WorkloadAnalyticsCacheService.php';
handleCORS();

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        appJsonError(405, '不支持的请求方法');
    }
    $context = appRequireStaffContext();
    $input = appInputArray();
    $reportId = appRequireInt($input, 'report_id', '日报 ID');
    $reason = appRequireString($input, 'correction_reason', '更正原因');
    $remarks = mb_substr(appOptionalString($input, 'remarks'), 0, 255);
    $values = $input['values'] ?? [];
    if (!is_array($values) || $values === []) {
        appJsonError(400, '更正后的指标值不能为空');
    }

    $pdo = workloadDb();
    workloadEnsureSchema($pdo);
    workloadEnsureAuditSchema($pdo);
    $scopeStmt = $pdo->prepare('SELECT store_id FROM workload_daily_reports WHERE id = ?');
    $scopeStmt->execute([$reportId]);
    $storeId = (int) $scopeStmt->fetchColumn();
    if ($storeId <= 0) {
        appJsonError(404, '日报不存在');
    }
    if (!appCanEditStore($context, $storeId)) {
        appJsonError(403, '无权更正该门店日报');
    }

    $result = (new WorkloadReportStateService($pdo))->correctReport(
        $reportId,
        $values,
        $remarks,
        $reason,
        (int) ($context['staff_id'] ?? 0)
    );
    (new WorkloadAnalyticsCacheService())->invalidate([
        'date' => $result['business_date'],
        'store_id' => $result['store_id'],
        'staff_id' => $result['staff_id'],
        'role_code' => $result['role_code'],
        'metric_codes' => $result['metric_codes'],
    ]);
    appLogEvent('workload.correct_report', [
        'report_id' => $reportId,
        'store_id' => $storeId,
        'operator_staff_id' => (int) ($context['staff_id'] ?? 0),
        'correction_key' => $result['correction_key'],
    ]);
    appJsonSuccess($result, '更正成功');
} catch (WorkloadReportStateException|WorkloadAuditTaskException|WorkloadRoleRuleVersionException $e) {
    appJsonError($e->statusCode(), $e->getMessage());
} catch (Throwable $e) {
    appLogEvent('workload.correct_report_error', ['error' => $e->getMessage()]);
    appJsonError(500, '更正日报失败');
}
