<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/common/context.php';
require_once __DIR__ . '/services/DrillIdempotencyService.php';

function drillV2Respond(int $code, string $message, array $data = [], ?int $httpStatus = null): void
{
    $status = $httpStatus ?? ($code === 0 ? 200 : $code);
    if ($status < 100 || $status > 599) {
        $status = $code === 0 ? 200 : 400;
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'code' => $code,
        'message' => $message,
        'data' => $data,
        'request_id' => appRequestId(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function drillV2Success(array $data = [], string $message = 'success', int $httpStatus = 200): void
{
    drillV2Respond(0, $message, $data, $httpStatus);
}

function drillV2Error(int $code, string $message, array $data = [], ?int $httpStatus = null): void
{
    drillV2Respond($code, $message, $data, $httpStatus);
}

function drillV2Bootstrap(array $allowedMethods): array
{
    handleCORS();
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $allowedMethods = array_values(array_unique(array_map('strtoupper', $allowedMethods)));
    if (!in_array($method, $allowedMethods, true)) {
        header('Allow: ' . implode(', ', $allowedMethods));
        drillV2Error(405, '不支持的请求方法', ['allowed_methods' => $allowedMethods], 405);
    }

    $context = appGetCurrentStaffContext();
    if (empty($context['authenticated'])) {
        appLogEvent('drill.v2.auth_required_failed');
        drillV2Error(401, '请先登录', [], 401);
    }
    return $context;
}

function drillV2Input(): array
{
    $input = getRequestInput();
    return is_array($input) ? $input : [];
}

function drillV2IdempotencyKey(bool $required = true): string
{
    $key = trim((string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
    if ($required && ($key === '' || strlen($key) > 128)) {
        drillV2Error(400, '写请求必须提供有效的 Idempotency-Key');
    }
    return $key;
}

function drillV2RunIdempotent(
    PDO $pdo,
    array $context,
    string $action,
    array $request,
    callable $operation
): array {
    $service = new DrillIdempotencyService($pdo);
    return $service->execute(
        (int) ($context['user_id'] ?? 0),
        $action,
        drillV2IdempotencyKey(),
        $request,
        $operation
    );
}
