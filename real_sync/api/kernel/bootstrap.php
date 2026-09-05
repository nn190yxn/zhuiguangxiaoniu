<?php
declare(strict_types=1);

require_once __DIR__ . '/ApiException.php';
require_once __DIR__ . '/RequestContext.php';
require_once __DIR__ . '/ApiResponse.php';
require_once __DIR__ . '/SensitiveData.php';
require_once __DIR__ . '/ApiLogger.php';
require_once __DIR__ . '/ExceptionMapper.php';
require_once __DIR__ . '/AuthContext.php';
require_once __DIR__ . '/LegacyAuthAdapter.php';
require_once __DIR__ . '/SyncProtocol.php';
require_once __DIR__ . '/StateVersion.php';
require_once __DIR__ . '/Compatibility.php';
require_once dirname(__DIR__, 2) . '/database/MigrationReadiness.php';
require_once dirname(__DIR__) . '/platform/HealthService.php';
require_once dirname(__DIR__) . '/platform/BusinessDomainRegistry.php';
require_once dirname(__DIR__) . '/platform/LegacyEndpointGovernance.php';
require_once dirname(__DIR__) . '/platform/IdempotencyService.php';

function platformApiContext(array $metadata = [], ?array $server = null): PlatformRequestContext
{
    static $contexts = [];
    $server ??= $_SERVER;
    $cacheKey = hash('sha256', serialize([$server, $metadata]));
    if (!isset($contexts[$cacheKey])) {
        $contexts[$cacheKey] = PlatformRequestContext::fromServer($server, $metadata);
        PlatformApiCompatibility::observeLegacyInvocation($contexts[$cacheKey], new PlatformApiLogger());
    }
    return $contexts[$cacheKey];
}

function platformApiResponse(
    PlatformRequestContext $context,
    mixed $data = [],
    string $message = 'success',
    int $httpStatus = 200
): PlatformApiResponse {
    return PlatformApiResponse::success($context, $data, $message, $httpStatus);
}

function platformApiErrorResponse(
    PlatformRequestContext $context,
    int|string $code,
    string $message,
    mixed $data = null,
    int $httpStatus = 400
): PlatformApiResponse {
    return PlatformApiResponse::error($context, $code, $message, $data, $httpStatus);
}

function platformApiInstallExceptionHandler(
    PlatformRequestContext $context,
    ?PlatformApiLogger $logger = null
): void {
    set_exception_handler(static function (Throwable $error) use ($context, $logger): never {
        PlatformExceptionMapper::response($error, $context, $logger)->send();
    });
}

function platformApiAuthContext(array $assignments = []): PlatformAuthContext
{
    return PlatformLegacyAuthAdapter::current($assignments);
}

function platformRequireMigrationReadiness(PDO $db, array $versions): array
{
    static $catalog = null;
    $catalog ??= require dirname(__DIR__, 2) . '/database/migration_catalog.php';
    $result = (new MigrationReadiness(new PdoMigrationReadinessDatabase($db), $catalog))->check($versions);
    if (!$result['ready']) {
        $issues = array_map(static fn(array $issue): array => array_intersect_key(
            $issue,
            array_flip(['version', 'type', 'target'])
        ), $result['issues']);
        throw new PlatformApiException(503, 'schema_not_ready', '数据库结构尚未就绪', ['issues' => $issues]);
    }
    return $result;
}
