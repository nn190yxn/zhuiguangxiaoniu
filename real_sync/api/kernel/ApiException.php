<?php
declare(strict_types=1);

final class PlatformApiException extends RuntimeException
{
    private int $httpStatus;
    private int|string $errorCode;
    private array $errorData;

    public function __construct(
        int $httpStatus,
        int|string $errorCode,
        string $message,
        array $data = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->httpStatus = self::normalizeHttpStatus($httpStatus);
        $this->errorCode = $errorCode;
        $this->errorData = $data;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function errorCode(): int|string
    {
        return $this->errorCode;
    }

    public function errorData(): array
    {
        return $this->errorData;
    }

    private static function normalizeHttpStatus(int $status): int
    {
        return $status >= 400 && $status <= 599 ? $status : 500;
    }
}
