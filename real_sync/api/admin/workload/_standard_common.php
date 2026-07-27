<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/common.php';
require_once dirname(__DIR__) . '/services/WorkloadRoleRuleAdminService.php';

function workloadStandardBootstrap(array $methods): array
{
    header('Content-Type: application/json; charset=utf-8');
    handleCORS();
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
    if (!in_array($_SERVER['REQUEST_METHOD'], $methods, true)) {
        jsonResponse(405, '请求方法不被支持', null);
    }
    [$userId, $user, $staff] = adminRequirePermission('workload.standard_manage');
    return [
        new WorkloadRoleRuleAdminService(getDB()),
        is_array($user) ? $user : ['user_id' => (int) $userId],
        is_array($staff) ? $staff : [],
    ];
}

function workloadStandardIdempotencyKey(): string
{
    return trim((string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
}

function workloadStandardFailure(Throwable $error, string $fallback): never
{
    if ($error instanceof WorkloadRoleRuleAdminException) {
        jsonResponse($error->statusCode(), $error->getMessage(), $error->details() ?: null);
    }
    error_log('[admin.workload.standard] ' . $error->getMessage());
    jsonResponse(500, $fallback, null);
}
