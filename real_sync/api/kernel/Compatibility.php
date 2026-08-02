<?php
declare(strict_types=1);

final class PlatformApiCompatibility
{
    public const KERNEL_VERSION = '1.0.0';
    public const RESPONSE_CONTRACT_VERSION = '1.0';
    public const SYNC_CONTRACT_VERSION = '1.0';

    public static function withMetadata(
        array $data,
        string $endpointVersion,
        array $capabilities = []
    ): array {
        $data['meta'] = array_merge(
            is_array($data['meta'] ?? null) ? $data['meta'] : [],
            self::metadata($endpointVersion, $capabilities)
        );
        return $data;
    }

    public static function metadata(string $endpointVersion, array $capabilities = []): array
    {
        return [
            'api_kernel_version' => self::KERNEL_VERSION,
            'response_contract_version' => self::RESPONSE_CONTRACT_VERSION,
            'endpoint_version' => $endpointVersion,
            'capabilities' => array_values(array_unique(array_filter(array_map('strval', $capabilities)))),
        ];
    }

    public static function observeLegacyInvocation(
        PlatformRequestContext $context,
        ?PlatformApiLogger $logger = null
    ): array {
        $request = $context->toArray();
        $path = (string)(parse_url((string)$request['uri'], PHP_URL_PATH) ?? '');
        if ($path === '/api/platform/health.php' || $path === '/api/platform/capabilities.php') {
            return ['matched' => false, 'recorded' => false];
        }
        $catalog = require dirname(__DIR__) . '/platform/legacy_endpoint_catalog.php';
        $matches = array_values(array_filter($catalog, static fn(array $entry): bool =>
            $entry['endpoint'] === $path
            && $entry['method'] === $request['method']
            && $entry['domain'] === $request['domain']
        ));
        if ($matches === []) {
            return ['matched' => false, 'recorded' => false];
        }
        if (!class_exists('PlatformBusinessDomainRegistry')
            || !array_key_exists((string)$request['domain'], PlatformBusinessDomainRegistry::all())) {
            return ['matched' => false, 'recorded' => false];
        }
        $entry = $matches[0];
        foreach ($matches as $candidate) {
            if ($candidate['consumer'] === $request['client']) {
                $entry = $candidate;
                break;
            }
        }
        $entry['consumer'] = trim((string)$request['client']) === '' ? $entry['consumer'] : (string)$request['client'];
        if (!function_exists('getDB')) {
            return ['matched' => true, 'recorded' => false, 'error' => 'schema_not_ready'];
        }
        try {
            return ['matched' => true, ...LegacyEndpointGovernance::recordInvocation(
                getDB(), $entry, $context->requestId(), $logger, $context
            )];
        } catch (Throwable $error) {
            if ($logger !== null) {
                $logger->log('warning', 'legacy_endpoint.invocation_record_failed', $context, ['error_type' => get_class($error)]);
            }
            return ['matched' => true, 'recorded' => false, 'error' => 'schema_not_ready'];
        }
    }
}
