<?php

declare(strict_types=1);

require_once __DIR__ . '/_standard_common.php';

try {
    [$service, $user, $staff] = workloadStandardBootstrap(['POST']);
    $input = adminJsonInput();
    $result = $service->deleteDraft(
        (int) ($input['version_id'] ?? $input['id'] ?? 0),
        $user,
        $staff,
        workloadStandardIdempotencyKey()
    );
    jsonResponse(0, '岗位标准草稿已删除', $result);
} catch (Throwable $error) {
    workloadStandardFailure($error, '岗位标准草稿删除失败');
}
