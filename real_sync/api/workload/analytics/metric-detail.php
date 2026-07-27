<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';
require_once dirname(__DIR__) . '/services/WorkloadMetricDetailService.php';
handleCORS();

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        appJsonError(405, '不支持的请求方法');
    }
    $context = appRequireStaffContext();
    $pdo = workloadDb();
    workloadEnsureSchema($pdo);
    $result = (new WorkloadMetricDetailService($pdo))->detail($_GET, $context);
    appLogEvent('workload.analytics.metric_detail', [
        'staff_id' => $context['staff_id'] ?? null,
        'date_from' => $result['filters']['date_from'] ?? null,
        'date_to' => $result['filters']['date_to'] ?? null,
        'row_count' => count($result['rows'] ?? []),
        'metric_version' => $result['metric_version'] ?? null,
    ]);
    appJsonSuccess($result);
} catch (WorkloadAnalyticsQueryException | WorkloadSourcePolicyException $error) {
    appLogEvent('workload.analytics.metric_detail_rejected', ['error' => $error->getMessage()]);
    appJsonError($error->statusCode(), $error->getMessage());
} catch (Throwable $error) {
    appLogEvent('workload.analytics.metric_detail_error', ['error' => $error->getMessage()]);
    appJsonError(500, '获取工作量明细失败');
}
