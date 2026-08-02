<?php
declare(strict_types=1);

final class PlatformRequestContext
{
    private const REQUEST_ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/';

    public function __construct(
        private string $requestId,
        private string $method,
        private string $uri,
        private string $client,
        private string $version,
        private string $domain,
        private string $action,
        private ?int $actorUserId = null,
        private ?int $actorStaffId = null
    ) {
    }

    public static function fromServer(array $server, array $metadata = []): self
    {
        $incomingRequestId = trim((string)($server['HTTP_X_REQUEST_ID'] ?? ''));
        $requestId = self::isValidRequestId($incomingRequestId)
            ? $incomingRequestId
            : self::generateRequestId();

        return new self(
            $requestId,
            strtoupper((string)($server['REQUEST_METHOD'] ?? 'GET')),
            (string)($server['REQUEST_URI'] ?? ''),
            self::safeLabel((string)($metadata['client'] ?? $server['HTTP_X_CLIENT'] ?? 'web'), 'web'),
            self::safeLabel((string)($metadata['version'] ?? $server['HTTP_X_CLIENT_VERSION'] ?? 'unknown'), 'unknown'),
            self::safeLabel((string)($metadata['domain'] ?? 'platform'), 'platform'),
            self::safeLabel((string)($metadata['action'] ?? 'request'), 'request'),
            isset($metadata['actor_user_id']) ? (int)$metadata['actor_user_id'] : null,
            isset($metadata['actor_staff_id']) ? (int)$metadata['actor_staff_id'] : null
        );
    }

    public static function isValidRequestId(string $requestId): bool
    {
        return preg_match(self::REQUEST_ID_PATTERN, $requestId) === 1;
    }

    public function withActor(?int $userId, ?int $staffId): self
    {
        $copy = clone $this;
        $copy->actorUserId = $userId;
        $copy->actorStaffId = $staffId;
        return $copy;
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function toArray(): array
    {
        return [
            'request_id' => $this->requestId,
            'method' => $this->method,
            'uri' => $this->uri,
            'client' => $this->client,
            'version' => $this->version,
            'domain' => $this->domain,
            'action' => $this->action,
            'actor_user_id' => $this->actorUserId,
            'actor_staff_id' => $this->actorStaffId,
        ];
    }

    private static function generateRequestId(): string
    {
        return gmdate('YmdHis') . '-' . bin2hex(random_bytes(8));
    }

    private static function safeLabel(string $value, string $fallback): string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/', $value) !== 1) {
            return $fallback;
        }
        return $value;
    }
}
