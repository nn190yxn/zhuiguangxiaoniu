<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';
require_once dirname(__DIR__) . '/services/WorkloadStaffProfileService.php';
handleCORS();

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        appJsonError(405, '不支持的请求方法');
    }
    $context = appRequireStaffContext();
    $staffId = appRequireInt(['staff_id' => appOptionalString($_GET, 'staff_id', '')], 'staff_id', '员工 ID');
    $input = $_GET;
    $input['staff_id'] = $staffId;
    $pdo = workloadDb();
    workloadEnsureSchema($pdo);
    $service = new WorkloadStaffProfileService($pdo);
    $result = $service->profile($input, $context);
    appLogEvent('workload.analytics.staff_profile', [
        'viewer_staff_id' => $context['staff_id'] ?? null,
        'staff_id' => $staffId,
        'date_from' => $result['filters']['date_from'] ?? null,
        'date_to' => $result['filters']['date_to'] ?? null,
        'granularity' => $result['filters']['granularity'] ?? null,
        'metric_version' => $result['metric_version'] ?? null,
    ]);
    appJsonSuccess($result);
} catch (WorkloadAnalyticsQueryException | WorkloadSourcePolicyException $error) {
    appLogEvent('workload.analytics.staff_profile_rejected', ['error' => $error->getMessage()]);
    appJsonError($error->statusCode(), $error->getMessage());
} catch (Throwable $error) {
    appLogEvent('workload.analytics.staff_profile_error', ['error' => $error->getMessage()]);
    appJsonError(500, '获取员工工作量画像失败');
}
