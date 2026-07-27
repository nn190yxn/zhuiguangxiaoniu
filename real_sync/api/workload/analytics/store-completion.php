<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';
require_once dirname(__DIR__) . '/services/WorkloadStoreAnalyticsService.php';
handleCORS();

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        appJsonError(405, '不支持的请求方法');
    }
    $context = appRequireStaffContext();
    $pdo = workloadDb();
    workloadEnsureSchema($pdo);
    $service = new WorkloadStoreAnalyticsService($pdo);
    $result = $service->storeCompletion($_GET, $context);
    appLogEvent('workload.analytics.store_completion', [
        'staff_id' => $context['staff_id'] ?? null,
        'date_from' => $result['filters']['date_from'] ?? null,
        'date_to' => $result['filters']['date_to'] ?? null,
        'store_count' => count($result['store_summaries'] ?? []),
        'metric_version' => $result['metric_version'] ?? null,
    ]);
    appJsonSuccess($result);
} catch (WorkloadAnalyticsQueryException | WorkloadSourcePolicyException $error) {
    appLogEvent('workload.analytics.store_completion_rejected', ['error' => $error->getMessage()]);
    appJsonError($error->statusCode(), $error->getMessage());
} catch (Throwable $error) {
    appLogEvent('workload.analytics.store_completion_error', ['error' => $error->getMessage()]);
    appJsonError(500, '获取门店周期完成统计失败');
}
