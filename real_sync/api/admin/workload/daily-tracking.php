<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/common.php';
require_once dirname(__DIR__, 2) . '/workload/_common.php';
require_once dirname(__DIR__, 2) . '/workload/services/WorkloadDailyTrackingService.php';

try {
    adminRequireAuth('adminCanAccessWorkload');
    $pdo = workloadDb();
    workloadEnsureSchema($pdo);
    $result = (new WorkloadDailyTrackingService($pdo))->dailyTracking(appRequireStaffContext(), $_GET);
    jsonResponse(0, 'success', $result);
} catch (InvalidArgumentException $e) {
    jsonResponse(400, $e->getMessage());
} catch (WorkloadPermissionScopeException $e) {
    jsonResponse($e->statusCode(), $e->getMessage());
} catch (Throwable $e) {
    appLogEvent('admin.workload.daily_tracking_error', ['error' => $e->getMessage()]);
    jsonResponse(500, '获取每日工作量追踪失败');
}
