<?php

declare(strict_types=1);

if (!function_exists('getJwtTokenFromRequest')) {
    function getJwtTokenFromRequest(): string
    {
        $authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            return trim($matches[1]);
        }
        return trim((string) ($_COOKIE['jwt_token'] ?? ''));
    }
}

if (!function_exists('issueJwtAuthCookie')) {
    function issueJwtAuthCookie(string $token): void
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        setcookie('jwt_token', $token, [
            'expires' => time() + JWT_EXPIRE,
            'path' => '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
