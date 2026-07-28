<?php

declare(strict_types=1);

require_once __DIR__ . '/_standard_common.php';
require_once dirname(__DIR__) . '/services/WorkloadStandardImportParser.php';
require_once dirname(__DIR__) . '/services/WorkloadStandardImportService.php';

try {
    [, $user, $staff] = workloadStandardBootstrap(['POST']);
    if (!isset($_FILES['file']) || (int) $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new WorkloadRoleRuleAdminException('请选择可读取的 CSV 或 XLSX 文件');
    }
    $payload = (new WorkloadStandardImportParser())->parse(
        (string) $_FILES['file']['tmp_name'],
        (string) $_FILES['file']['name']
    );
    $result = (new WorkloadStandardImportService(getDB()))->preflight(
        $payload['records'], $payload['metadata'], $user, $staff, workloadStandardIdempotencyKey()
    );
    jsonResponse(0, '岗位标准导入预检完成', $result);
} catch (WorkloadStandardImportParserException $error) {
    jsonResponse(400, $error->getMessage(), null);
} catch (Throwable $error) {
    workloadStandardFailure($error, '岗位标准导入预检失败');
}
