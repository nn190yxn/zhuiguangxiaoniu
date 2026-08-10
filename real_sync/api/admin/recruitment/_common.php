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

function recruitmentAdminIdempotent(PDO $db, string $action, string $key, array $request, callable $operation): array
{
    $key = trim($key);
    if ($key === '' || strlen($key) > 128) {
        throw new RecruitmentAdminException('写请求必须提供有效的 Idempotency-Key');
    }
    if (!adminTableExists($db, 'recruitment_idempotency_keys')) {
        throw new RecruitmentAdminException('招聘幂等记录表尚未完成迁移', 503);
    }
    $hash = hash('sha256', json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $db->beginTransaction();
    try {
        $insert = $db->prepare('INSERT IGNORE INTO recruitment_idempotency_keys (idempotency_key, action, request_hash, operator_staff_id) VALUES (?, ?, ?, ?)');
        $insert->execute([$key, $action, $hash, null]);
        if ($insert->rowCount() !== 1) {
            $existing = $db->prepare('SELECT request_hash, response_json FROM recruitment_idempotency_keys WHERE idempotency_key = ? AND action = ? FOR UPDATE');
            $existing->execute([$key, $action]);
            $row = $existing->fetch(PDO::FETCH_ASSOC);
            if (!$row || !hash_equals((string) $row['request_hash'], $hash)) {
                throw new RecruitmentAdminException('Idempotency-Key 已用于不同请求', 409);
            }
            $response = json_decode((string) ($row['response_json'] ?? ''), true);
            if (!is_array($response)) {
                throw new RecruitmentAdminException('同一写请求正在处理中', 409);
            }
            $db->commit();
            if (!empty($response['__error'])) {
                throw new RecruitmentAdminException((string) ($response['message'] ?? '同一写请求执行失败'), (int) ($response['status'] ?? 400));
            }
            return $response + ['idempotent' => true];
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }

    try {
        $result = $operation();
        $stored = $result;
    } catch (Throwable $error) {
        $stored = [
            '__error' => true,
            'message' => $error->getMessage(),
            'status' => $error instanceof RecruitmentAdminException ? $error->statusCode() : 500,
        ];
        $update = $db->prepare('UPDATE recruitment_idempotency_keys SET response_json = ? WHERE idempotency_key = ? AND action = ?');
        $update->execute([json_encode($stored, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $key, $action]);
        throw $error;
    }
    $update = $db->prepare('UPDATE recruitment_idempotency_keys SET response_json = ? WHERE idempotency_key = ? AND action = ?');
    $update->execute([json_encode($stored, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $key, $action]);
    return $result + ['idempotent' => false];
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
