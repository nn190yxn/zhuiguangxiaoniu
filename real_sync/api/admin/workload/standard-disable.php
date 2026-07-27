<?php

declare(strict_types=1);

require_once __DIR__ . '/_standard_common.php';

try {
    [$service, $user, $staff] = workloadStandardBootstrap(['POST']);
    $input = adminJsonInput();
    $result = $service->disable(
        (int) ($input['version_id'] ?? $input['id'] ?? 0),
        $input,
        $user,
        $staff,
        workloadStandardIdempotencyKey()
    );
    jsonResponse(0, '岗位标准已设置截止日期', $result);
} catch (Throwable $error) {
    workloadStandardFailure($error, '岗位标准停用失败');
}
