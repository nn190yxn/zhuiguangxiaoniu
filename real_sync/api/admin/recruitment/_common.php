<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/common.php';
require_once __DIR__ . '/services/RecruitmentPermissionService.php';

final class RecruitmentAdminException extends RuntimeException
{
    private int $statusCode;
    private ?array $details;

    public function __construct(string $message, int $statusCode = 400, ?array $details = null)
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
        $this->details = $details;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function details(): ?array
    {
        return $this->details;
    }
}

function recruitmentAdminBootstrap(string $permission, array $allowedMethods = ['GET', 'POST']): array
{
    header('Content-Type: application/json; charset=utf-8');
    handleCORS();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
    if (!in_array($method, $allowedMethods, true)) {
        jsonResponse(405, '请求方法不被支持', null);
    }

    [$userId, $user, $staff] = adminRequirePermission($permission);
    $db = getDB();
    $permissionService = new RecruitmentPermissionService($db);
    $safeUser = is_array($user) ? $user : ['user_id' => (int) $userId];
    $safeStaff = is_array($staff) ? $staff : [];
    return [
        'db' => $db,
        'method' => $method,
        'user_id' => (int) $userId,
        'user' => $safeUser,
        'staff' => $safeStaff,
        'permission_service' => $permissionService,
        'recruitment_scope' => $permissionService->scopeFor($safeUser, $safeStaff),
        'idempotency_key' => recruitmentAdminIdempotencyKey(),
    ];
}

function recruitmentAdminIdempotencyKey(): string
{
    return trim((string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
}

function recruitmentAdminInput(): array
{
    $input = adminJsonInput();
    if ($input !== []) {
        return $input;
    }
    return $_POST ?: $_GET;
}

function recruitmentAdminRequireIdempotency(array $context): void
{
    if (in_array($context['method'], ['POST', 'PUT', 'PATCH', 'DELETE'], true)
        && (string) $context['idempotency_key'] === '') {
        throw new RecruitmentAdminException('写请求必须提供有效的 Idempotency-Key');
    }
}

function recruitmentAdminHandlePlaceholder(string $resource, string $permission, array $allowedMethods = ['GET', 'POST']): void
{
    try {
        $context = recruitmentAdminBootstrap($permission, $allowedMethods);
        recruitmentAdminRequireIdempotency($context);
        jsonResponse(501, '招聘接口业务实现尚未启用', [
            'resource' => $resource,
            'method' => $context['method'],
            'staff_id' => (int) ($context['staff']['id'] ?? 0),
            'user_id' => (int) $context['user_id'],
            'scope' => $context['recruitment_scope'],
            'idempotency_key' => $context['idempotency_key'],
            'input' => recruitmentAdminInput(),
        ]);
    } catch (Throwable $error) {
        recruitmentAdminFailure($error, '招聘接口处理失败');
    }
}

function recruitmentAdminFailure(Throwable $error, string $fallback): never
{
    if ($error instanceof RecruitmentAdminException) {
        jsonResponse($error->statusCode(), $error->getMessage(), $error->details());
    }
    error_log('[admin.recruitment] ' . $error->getMessage());
    jsonResponse(500, $fallback, null);
}
