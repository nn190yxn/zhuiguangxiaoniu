<?php

declare(strict_types=1);

require_once __DIR__ . '/_standard_common.php';

try {
    [$service, $user, $staff] = workloadStandardBootstrap(['POST']);
    $input = adminJsonInput();
    $result = $service->copyToDraft(
        (int) ($input['source_version_id'] ?? 0),
        $input,
        $user,
        $staff,
        workloadStandardIdempotencyKey()
    );
    jsonResponse(0, '岗位标准已复制为新草稿', $result);
} catch (Throwable $error) {
    workloadStandardFailure($error, '岗位标准复制失败');
}
