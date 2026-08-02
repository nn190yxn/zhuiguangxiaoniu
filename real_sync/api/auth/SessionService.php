<?php
declare(strict_types=1);

final class PlatformSessionService
{
    private Closure $accessTokenIssuer;
    private Closure $clock;

    public function __construct(
        private PlatformSessionStore $store,
        callable $accessTokenIssuer,
        private int $accessTtlSeconds = 900,
        private int $refreshTtlSeconds = 2592000,
        ?callable $clock = null
    ) {
        $this->accessTokenIssuer = Closure::fromCallable($accessTokenIssuer);
        $this->clock = $clock === null ? static fn(): int => time() : Closure::fromCallable($clock);
        if ($this->accessTtlSeconds < 60 || $this->refreshTtlSeconds <= $this->accessTtlSeconds) {
            throw new InvalidArgumentException('Invalid session token lifetime');
        }
    }

    public function issue(array $identity, string $clientType, string $deviceId = '', string $identityHash = ''): array
    {
        $identity = $this->normalizeIdentity($identity);
        $clientType = $this->normalizeClientType($clientType);
        $identityHash = strtolower(trim($identityHash));
        if ($clientType === 'mini_program' && !preg_match('/^[a-f0-9]{64}$/', $identityHash)) {
            throw new InvalidArgumentException('Mini program identity is incomplete');
        }
        $now = ($this->clock)();
        $sessionId = self::randomId();
        $familyId = self::randomId();
        $rawRefreshToken = self::newRefreshToken();
        $session = [
            'id' => $sessionId,
            'family_id' => $familyId,
            'user_id' => $identity['user_id'],
            'staff_id' => $identity['staff_id'],
            'username' => $identity['username'],
            'role' => $identity['role'],
            'client_type' => $clientType,
            'device_id' => mb_substr(trim($deviceId), 0, 120),
            'identity_hash' => $identityHash === '' ? null : $identityHash,
            'session_version' => $identity['session_version'],
            'status' => 'active',
            'created_at' => $now,
            'expires_at' => $now + $this->refreshTtlSeconds,
        ];
        $refreshToken = $this->refreshRecord($sessionId, $rawRefreshToken, $now);
        $this->store->createSession($session, $refreshToken);

        return $this->tokenResponse($session, $rawRefreshToken);
    }

    public function refresh(string $rawRefreshToken, int $currentSessionVersion): array
    {
        if (!preg_match('/^psr_[a-f0-9]{64}$/', $rawRefreshToken)) {
            throw new PlatformApiException(401, 'invalid_refresh_token', '刷新凭据无效');
        }
        $now = ($this->clock)();
        $replacementRaw = self::newRefreshToken();
        $replacement = $this->refreshRecord('', $replacementRaw, $now);
        $result = $this->store->rotateRefreshToken(
            self::refreshTokenHash($rawRefreshToken),
            $currentSessionVersion,
            $replacement,
            $now
        );
        $status = (string)($result['status'] ?? 'invalid');
        if ($status === 'rotated') {
            return $this->tokenResponse($result['session'], $replacementRaw);
        }

        $errors = [
            'reuse_detected' => ['refresh_token_reused', '检测到刷新凭据复用，会话已撤销'],
            'version_mismatch' => ['session_version_changed', '账号权限或状态已变化，请重新登录'],
            'expired' => ['refresh_token_expired', '刷新凭据已过期，请重新登录'],
            'revoked' => ['session_revoked', '会话已撤销，请重新登录'],
            'invalid' => ['invalid_refresh_token', '刷新凭据无效'],
        ];
        [$code, $message] = $errors[$status] ?? $errors['invalid'];
        throw new PlatformApiException(401, $code, $message);
    }

    public function inspectRefreshToken(string $rawRefreshToken): array
    {
        if (!preg_match('/^psr_[a-f0-9]{64}$/', $rawRefreshToken)) {
            throw new PlatformApiException(401, 'invalid_refresh_token', '刷新凭据无效');
        }
        $session = $this->store->findRefreshSession(self::refreshTokenHash($rawRefreshToken));
        if ($session === null) {
            throw new PlatformApiException(401, 'invalid_refresh_token', '刷新凭据无效');
        }
        return $session;
    }

    public function revokeFamily(string $familyId, string $reason = 'logout'): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $familyId)) {
            throw new InvalidArgumentException('Invalid session family');
        }
        $this->store->revokeSessionFamily($familyId, $reason, ($this->clock)());
    }

    private function tokenResponse(array $session, string $rawRefreshToken): array
    {
        $claims = [
            'user_id' => (int)$session['user_id'],
            'username' => (string)$session['username'],
            'role' => (string)$session['role'],
            'session_id' => (string)$session['id'],
            'session_family' => (string)$session['family_id'],
            'session_version' => (int)$session['session_version'],
            'client' => (string)$session['client_type'],
        ];

        return [
            'access_token' => ($this->accessTokenIssuer)($claims, $this->accessTtlSeconds),
            'token_type' => 'Bearer',
            'access_expires_in' => $this->accessTtlSeconds,
            'refresh_token' => $rawRefreshToken,
            'refresh_expires_in' => $this->refreshTtlSeconds,
            'session_id' => (string)$session['id'],
            'session_version' => (int)$session['session_version'],
        ];
    }

    private function refreshRecord(string $sessionId, string $rawToken, int $now): array
    {
        return [
            'id' => self::randomId(),
            'session_id' => $sessionId,
            'token_hash' => self::refreshTokenHash($rawToken),
            'status' => 'active',
            'created_at' => $now,
            'expires_at' => $now + $this->refreshTtlSeconds,
        ];
    }

    private function normalizeIdentity(array $identity): array
    {
        $userId = (int)($identity['user_id'] ?? 0);
        $sessionVersion = (int)($identity['session_version'] ?? -1);
        if ($userId <= 0 || $sessionVersion < 0) {
            throw new InvalidArgumentException('Session identity is incomplete');
        }
        return [
            'user_id' => $userId,
            'staff_id' => empty($identity['staff_id']) ? null : (int)$identity['staff_id'],
            'username' => mb_substr((string)($identity['username'] ?? ''), 0, 191),
            'role' => mb_substr((string)($identity['role'] ?? 'staff'), 0, 60),
            'session_version' => $sessionVersion,
        ];
    }

    private function normalizeClientType(string $clientType): string
    {
        $clientType = strtolower(trim($clientType));
        if (!in_array($clientType, ['web', 'pwa', 'mini_program', 'wecom'], true)) {
            throw new InvalidArgumentException('Unsupported session client');
        }
        return $clientType;
    }

    private static function randomId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private static function newRefreshToken(): string
    {
        return 'psr_' . bin2hex(random_bytes(32));
    }

    private static function refreshTokenHash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }
}
