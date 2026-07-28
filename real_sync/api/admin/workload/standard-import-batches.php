<?php

declare(strict_types=1);

require_once __DIR__ . '/_standard_common.php';
require_once dirname(__DIR__) . '/services/WorkloadStandardImportService.php';

try {
    [, $user, $staff] = workloadStandardBootstrap(['GET', 'POST']);
    $service = new WorkloadStandardImportService(getDB());
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $batchId = (int) ($_GET['id'] ?? $_GET['batch_id'] ?? 0);
        jsonResponse(0, 'ok', $batchId > 0 ? $service->getBatch($batchId) : $service->listBatches($_GET));
    }
    $input = adminJsonInput();
    $batchId = (int) ($input['batch_id'] ?? $input['id'] ?? 0);
    if (($input['action'] ?? 'confirm') !== 'confirm') {
        throw new WorkloadRoleRuleAdminException('导入批次操作无效');
    }
    $result = $service->confirm($batchId, $input, $user, $staff, workloadStandardIdempotencyKey());
    jsonResponse(0, !empty($input['publish']) ? '导入标准已生成并发布' : '导入标准草稿已生成', $result);
} catch (Throwable $error) {
    workloadStandardFailure($error, '岗位标准导入批次操作失败');
}
