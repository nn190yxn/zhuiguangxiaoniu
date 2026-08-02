<?php
declare(strict_types=1);

const PLATFORM_PWA_REFRESH_COOKIE = 'platform_refresh';
const PLATFORM_PWA_CSRF_COOKIE = 'platform_csrf';
const PLATFORM_PWA_REFRESH_PATH = '/api/auth/refresh.php';

function platformPwaSetSessionCookies(string $refreshToken, string $sessionId, int $maxAge): void
{
    setcookie(PLATFORM_PWA_REFRESH_COOKIE, $refreshToken, [
        'expires' => time() + $maxAge,
        'path' => PLATFORM_PWA_REFRESH_PATH,
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    setcookie(PLATFORM_PWA_CSRF_COOKIE, platformPwaCreateCsrfToken($sessionId), [
        'expires' => time() + $maxAge,
        'path' => '/',
        'secure' => true,
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
}

function platformPwaClearSessionCookies(): void
{
    foreach ([
        [PLATFORM_PWA_REFRESH_COOKIE, PLATFORM_PWA_REFRESH_PATH, true],
        [PLATFORM_PWA_CSRF_COOKIE, '/', false],
    ] as [$name, $path, $httpOnly]) {
        setcookie($name, '', [
            'expires' => time() - 3600,
            'path' => $path,
            'secure' => true,
            'httponly' => $httpOnly,
            'samesite' => 'Lax',
        ]);
    }
}

function platformPwaCreateCsrfToken(string $sessionId): string
{
    $payload = $sessionId . '.' . bin2hex(random_bytes(16));
    return $payload . '.' . hash_hmac('sha256', $payload, JWT_SECRET);
}

function platformPwaValidateCsrf(string $token, string $sessionId): bool
{
    $parts = explode('.', $token);
    if (count($parts) !== 3 || !hash_equals($sessionId, $parts[0])) {
        return false;
    }
    $payload = $parts[0] . '.' . $parts[1];
    return preg_match('/^[a-f0-9]{32}$/', $parts[1]) === 1
        && hash_equals(hash_hmac('sha256', $payload, JWT_SECRET), $parts[2]);
}

function platformPwaRequireTrustedOrigin(): void
{
    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    $allowed = array_values(array_filter(array_map('trim', explode(',', ALLOWED_ORIGINS))));
    if ($origin === '' || !in_array($origin, $allowed, true)) {
        throw new PlatformApiException(403, 'untrusted_origin', '请求来源不受信任');
    }
}
