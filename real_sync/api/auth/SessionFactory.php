<?php
declare(strict_types=1);

require_once __DIR__ . '/../kernel/bootstrap.php';
require_once __DIR__ . '/SessionStore.php';
require_once __DIR__ . '/SessionService.php';

function platformSessionService(PDO $db): PlatformSessionService
{
    return new PlatformSessionService(
        new PlatformPdoSessionStore($db),
        static fn(array $claims, int $ttl): string => generate_jwt(
            $claims['user_id'],
            $claims['username'],
            $claims['role'],
            $claims,
            $ttl
        ),
        JWT_ACCESS_EXPIRE,
        SESSION_REFRESH_EXPIRE
    );
}
