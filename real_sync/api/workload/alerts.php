<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/WorkloadAlertManagementService.php';
handleCORS();

try {
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'POST'], true)) appJsonError(405, '不支持的请求方法');
    $context = appRequireStaffContext();
    $pdo = workloadDb();
    workloadEnsureSchema($pdo);
    $service = new WorkloadAlertManagementService($pdo);
    if ($method === 'GET') appJsonSuccess($service->list($_GET, $context));
    $action = strtolower(trim((string) ($_GET['action'] ?? 'resolve')));
    if ($action !== 'resolve') appJsonError(400, '无效预警操作');
    $input = appInputArray();
    $result = $service->resolve(
        appRequireInt($input, 'event_id', '预警事件 ID'),
        appOptionalString($input, 'comment'),
        $context
    );
    appJsonSuccess($result, '预警已处理');
} catch (WorkloadAlertManagementException | WorkloadPermissionScopeException $error) {
    appJsonError($error->statusCode(), $error->getMessage());
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    appLogEvent('workload.alert_management_error', ['error' => $error->getMessage()]);
    appJsonError(500, '预警操作失败');
}
