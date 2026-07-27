<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';
require_once dirname(__DIR__) . '/services/WorkloadCrossAnalysisService.php';
handleCORS();

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        appJsonError(405, '不支持的请求方法');
    }
    $context = appRequireStaffContext();
    $pdo = workloadDb();
    workloadEnsureSchema($pdo);
    $service = new WorkloadCrossAnalysisService($pdo);
    $result = $service->analyze($_GET, $context);
    appLogEvent('workload.analytics.cross_analysis', [
        'staff_id' => $context['staff_id'] ?? null,
        'date_from' => $result['filters']['date_from'] ?? null,
        'date_to' => $result['filters']['date_to'] ?? null,
        'primary_dimension' => $result['dimensions']['primary'] ?? null,
        'secondary_dimension' => $result['dimensions']['secondary'] ?? null,
        'cell_count' => count($result['matrix'] ?? []),
        'metric_version' => $result['metric_version'] ?? null,
    ]);
    appJsonSuccess($result);
} catch (WorkloadBusinessPeriodException $error) {
    appLogEvent('workload.analytics.cross_analysis_rejected', ['error' => $error->getMessage()]);
    appJsonError(400, $error->getMessage());
} catch (WorkloadAnalyticsQueryException | WorkloadSourcePolicyException $error) {
    appLogEvent('workload.analytics.cross_analysis_rejected', ['error' => $error->getMessage()]);
    appJsonError($error->statusCode(), $error->getMessage());
} catch (Throwable $error) {
    appLogEvent('workload.analytics.cross_analysis_error', ['error' => $error->getMessage()]);
    appJsonError(500, '获取工作量交叉分析失败');
}
