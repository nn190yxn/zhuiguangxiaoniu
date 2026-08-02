<?php
declare(strict_types=1);

require_once __DIR__ . '/RequestContext.php';

final class PlatformApiResponse
{
    private function __construct(
        private int $httpStatus,
        private int|string $code,
        private string $message,
        private mixed $data,
        private string $requestId
    ) {
    }

    public static function success(
        PlatformRequestContext $context,
        mixed $data = [],
        string $message = 'success',
        int $httpStatus = 200
    ): self {
        return new self(self::normalizeHttpStatus($httpStatus, 200), 0, $message, $data, $context->requestId());
    }

    public static function error(
        PlatformRequestContext $context,
        int|string $code,
        string $message,
        mixed $data = null,
        int $httpStatus = 400
    ): self {
        return new self(self::normalizeHttpStatus($httpStatus, 500), $code, $message, $data, $context->requestId());
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function payload(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'data' => $this->data,
            'request_id' => $this->requestId,
        ];
    }

    public function json(): string
    {
        $json = json_encode($this->payload(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('API response JSON encoding failed');
        }
        return $json;
    }

    public function send(): never
    {
        http_response_code($this->httpStatus);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-ID: ' . $this->requestId);
        echo $this->json();
        exit;
    }

    private static function normalizeHttpStatus(int $status, int $fallback): int
    {
        return $status >= 100 && $status <= 599 ? $status : $fallback;
    }
}
