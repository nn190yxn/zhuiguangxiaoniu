<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/WorkloadDailyStatusService.php';
require_once dirname(__DIR__) . '/kernel/bootstrap.php';
require_once __DIR__ . '/platform/WorkloadPlatformAdapter.php';
handleCORS();

$platformContext = platformApiContext(['domain' => 'workload', 'action' => 'workload.daily_status.read']);
$platformLogger = new PlatformApiLogger();
platformApiInstallExceptionHandler($platformContext, $platformLogger);

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') appJsonError(405, '仅支持 GET 请求');
    $context = appRequireStaffContext();
    $platformAuth = platformApiAuthContext();
    $platformAuth->requireAuthenticated();
    $platformContext = $platformContext->withActor($platformAuth->userId(), $platformAuth->staffId());
    $staffId = (int) ($context['staff_id'] ?? 0);
    $storeId = (int) ($context['store_id'] ?? 0);
    $roleCode = appRoleCode((string) ($context['role'] ?? ''));
    $pdo = workloadDb();
    WorkloadPlatformAdapter::assertSubmissionReady($pdo);
    $result = (new WorkloadDailyStatusService($pdo))->forEmployee($staffId, $storeId, $roleCode);
    $migration = PlatformBusinessDomainRegistry::get('workload');
    $result = PlatformApiCompatibility::withMetadata($result, $migration['endpoint_version'], $migration['capabilities']);
    $platformLogger->log('info', 'workload.daily_status.read', $platformContext, ['staff_id' => $staffId, 'store_id' => $storeId]);
    platformApiResponse($platformContext, $result)->send();
} catch (PlatformApiException $e) {
    throw $e;
} catch (Throwable $e) {
    appLogEvent('workload.my_status_error', ['error' => $e->getMessage()]);
    appJsonError(500, '获取每日工作量状态失败');
}
