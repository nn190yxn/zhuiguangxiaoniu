<?php

declare(strict_types=1);

require_once __DIR__ . '/_standard_common.php';

try {
    [$service, $user, $staff] = workloadStandardBootstrap(['GET', 'POST']);
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $id = (int) ($_GET['id'] ?? 0);
        jsonResponse(0, 'ok', $id > 0
            ? $service->getStandard($id)
            : $service->listStandards([
                'role_code' => $_GET['role_code'] ?? '',
                'status' => $_GET['status'] ?? 'all',
            ]));
    }
    $input = adminJsonInput();
    $id = (int) ($input['id'] ?? 0);
    $result = $id > 0
        ? $service->updateDraft($id, $input, $user, $staff, workloadStandardIdempotencyKey())
        : $service->createDraft($input, $user, $staff, workloadStandardIdempotencyKey());
    jsonResponse(0, $id > 0 ? '岗位标准草稿已更新' : '岗位标准草稿已创建', $result);
} catch (Throwable $error) {
    workloadStandardFailure($error, '岗位标准操作失败');
}
