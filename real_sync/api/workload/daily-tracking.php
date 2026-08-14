<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/WorkloadDailyTrackingService.php';
handleCORS();

try {
    $context = appRequireStaffContext();
    if (!appCanAccessWorkload(['role' => $context['role'] ?? ''], $context)) {
        appJsonError(403, '无权限查看每日工作量追踪');
    }
    $pdo = workloadDb();
    workloadEnsureSchema($pdo);
    appJsonSuccess((new WorkloadDailyTrackingService($pdo))->dailyTracking($context, $_GET));
} catch (InvalidArgumentException $e) {
    appJsonError(400, $e->getMessage());
} catch (WorkloadPermissionScopeException $e) {
    appJsonError($e->statusCode(), $e->getMessage());
} catch (Throwable $e) {
    appLogEvent('workload.daily_tracking_error', ['error' => $e->getMessage()]);
    appJsonError(500, '获取每日工作量追踪失败');
}
