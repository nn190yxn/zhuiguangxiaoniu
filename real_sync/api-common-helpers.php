<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

function appRequestId(): string {
    static $requestId = null;
    if ($requestId !== null) {
        return $requestId;
    }
    $requestId = date('YmdHis') . '-' . bin2hex(random_bytes(4));
    return $requestId;
}

function appJsonSuccess(array $data = [], string $message = 'success'): void {
    jsonResponse(0, $message, $data);
}

function appJsonError(int $code, string $message, $data = null): void {
    jsonResponse($code, $message, $data);
}

function appInputArray(?array $input = null): array {
    if ($input !== null) {
        return $input;
    }
    $data = getRequestInput();
    return is_array($data) ? $data : [];
}

function appRequireString(array $input, string $key, string $label = ''): string {
    $value = trim((string)($input[$key] ?? ''));
    if ($value === '') {
        appJsonError(400, ($label ?: $key) . '不能为空');
    }
    return $value;
}

function appOptionalString(array $input, string $key, string $default = ''): string {
    return trim((string)($input[$key] ?? $default));
}

function appRequireInt(array $input, string $key, string $label = ''): int {
    if (!isset($input[$key]) || !is_numeric($input[$key])) {
        appJsonError(400, ($label ?: $key) . '必须是数字');
    }
    return (int)$input[$key];
}

function appRequireDate(array $input, string $key, string $label = ''): string {
    $value = appRequireString($input, $key, $label ?: $key);
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    if (!$dt || $dt->format('Y-m-d') !== $value) {
        appJsonError(400, ($label ?: $key) . '格式必须为YYYY-MM-DD');
    }
    return $value;
}

function appRequireEnum(array $input, string $key, array $allowed, string $label = ''): string {
    $value = appRequireString($input, $key, $label ?: $key);
    if (!in_array($value, $allowed, true)) {
        appJsonError(400, ($label ?: $key) . '不在允许范围内');
    }
    return $value;
}

function appLogEvent(string $event, array $context = []): void {
    $dir = dirname(__DIR__, 2) . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $safeContext = appSanitizeLogContext($context);
    $row = [
        'time' => date('c'),
        'request_id' => appRequestId(),
        'event' => $event,
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'uri' => $_SERVER['REQUEST_URI'] ?? '',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'context' => $safeContext,
    ];
    @file_put_contents($dir . '/api-' . date('Y-m-d') . '.log', json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function appSanitizeLogContext(array $context): array {
    $sensitive = ['password', 'token', 'jwt', 'authorization', 'openid', 'secret', 'api_key', 'apikey'];
    foreach ($context as $key => $value) {
        $lower = strtolower((string)$key);
        foreach ($sensitive as $needle) {
            if (strpos($lower, $needle) !== false) {
                $context[$key] = '***';
                continue 2;
            }
        }
        if (is_array($value)) {
            $context[$key] = appSanitizeLogContext($value);
        }
    }
    return $context;
}
