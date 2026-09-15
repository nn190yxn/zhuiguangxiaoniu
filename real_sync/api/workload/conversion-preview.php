<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/WorkloadConversionPreviewService.php';
handleCORS();

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        appJsonError(405, '不支持的请求方法');
    }
    $context = appRequireStaffContext();
    if (!appCanEditAll($context)) {
        appJsonError(403, '无权限预览换算规则草稿');
    }
    $roleCode = appRequireString($_GET, 'role_code', '岗位编码');
    $versionCode = appRequireString($_GET, 'version_code', '版本编码');
    $pdo = workloadDb();
    workloadEnsureSchema($pdo);
    appJsonSuccess((new WorkloadConversionPreviewService($pdo))->preview($roleCode, $versionCode));
} catch (WorkloadConversionRuleException $error) {
    appJsonError(400, $error->getMessage());
} catch (Throwable $error) {
    appLogEvent('workload.conversion_preview_error', ['error' => $error->getMessage()]);
    appJsonError(500, '获取换算规则预览失败');
}
