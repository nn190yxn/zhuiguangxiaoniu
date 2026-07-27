<?php

declare(strict_types=1);

require_once __DIR__ . '/_standard_common.php';

try {
    [$service, $user, $staff] = workloadStandardBootstrap(['POST']);
    $input = adminJsonInput();
    $versionId = (int) ($input['version_id'] ?? 0);
    $action = strtolower(trim((string) ($input['action'] ?? 'upsert')));
    $result = $service->mutateItems($versionId, $action, $input, $user, $staff, workloadStandardIdempotencyKey());
    jsonResponse(0, '岗位标准项目已更新', $result);
} catch (Throwable $error) {
    workloadStandardFailure($error, '岗位标准项目操作失败');
}
