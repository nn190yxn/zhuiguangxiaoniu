<?php
declare(strict_types=1);

require_once __DIR__ . '/ApiException.php';
require_once __DIR__ . '/ApiLogger.php';
require_once __DIR__ . '/ApiResponse.php';
require_once __DIR__ . '/SensitiveData.php';

final class PlatformExceptionMapper
{
    public static function response(
        Throwable $error,
        PlatformRequestContext $context,
        ?PlatformApiLogger $logger = null
    ): PlatformApiResponse {
        $logger ??= new PlatformApiLogger();
        $logger->log('error', 'api.exception', $context, [
            'error_type' => get_class($error),
            'error_code' => $error instanceof PlatformApiException ? $error->errorCode() : 'internal_error',
            'error_summary' => PlatformSensitiveData::summary($error->getMessage(), 'error'),
        ]);

        if ($error instanceof PlatformApiException) {
            return PlatformApiResponse::error(
                $context,
                $error->errorCode(),
                $error->getMessage(),
                PlatformSensitiveData::sanitize($error->errorData()),
                $error->httpStatus()
            );
        }
        if ($error instanceof InvalidArgumentException) {
            return PlatformApiResponse::error($context, 'validation_error', $error->getMessage(), null, 400);
        }
        if ($error instanceof DomainException) {
            return PlatformApiResponse::error($context, 'domain_error', $error->getMessage(), null, 422);
        }
        return PlatformApiResponse::error($context, 'internal_error', '服务暂时不可用', null, 500);
    }
}
