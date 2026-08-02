<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/kernel/bootstrap.php';

$context = platformApiContext([
    'domain' => 'platform',
    'action' => 'capabilities.read',
]);
$logger = new PlatformApiLogger();
platformApiInstallExceptionHandler($context, $logger);

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    throw new PlatformApiException(405, 'method_not_allowed', '仅支持 GET 请求');
}

$capabilities = [
    'request_id',
    'structured_errors',
    'version_metadata',
    'auth_context',
    'state_version_conflict',
    'mini_program_device_session',
    'mini_program_refresh_rotation',
    'mini_program_feature_versions',
    'sync_levels',
    'etag_validation',
    'incremental_cursor',
    'sync_tombstones',
    'server_drafts',
    'version_conflict_recovery',
];
$data = PlatformApiCompatibility::withMetadata([
    'api_kernel_version' => PlatformApiCompatibility::KERNEL_VERSION,
    'response_contract_version' => PlatformApiCompatibility::RESPONSE_CONTRACT_VERSION,
    'supported_clients' => ['web', 'pwa', 'mini_program'],
    'capabilities' => $capabilities,
    'client_sessions' => [
        'mini_program' => [
            'version' => '1.0',
            'mode' => 'device_session',
            'legacy_bearer_compatible' => true,
            'refresh_endpoint' => '/api/auth/mini-program-session.php?action=refresh',
        ],
    ],
    'mini_program' => [
        'contract_version' => '1.0',
        'fallback_mode' => 'explicit_allowlist',
        'features' => [
            'authentication' => ['enabled' => true, 'minimum_client_version' => '1.0.0'],
            'workload' => ['enabled' => true, 'minimum_client_version' => '1.0.0'],
            'profile' => ['enabled' => true, 'minimum_client_version' => '1.0.0'],
            'drill' => ['enabled' => true, 'minimum_client_version' => '1.0.0'],
            'policy' => ['enabled' => true, 'minimum_client_version' => '1.0.0'],
            'learning' => ['enabled' => true, 'minimum_client_version' => '1.0.0'],
            'knowledge' => ['enabled' => true, 'minimum_client_version' => '1.0.0'],
            'pass' => ['enabled' => true, 'minimum_client_version' => '1.0.0'],
            'notifications' => ['enabled' => true, 'minimum_client_version' => '1.0.0'],
            'reminder_settings' => ['enabled' => true, 'minimum_client_version' => '1.0.0'],
        ],
    ],
    'sync_contract' => [
        'version' => PlatformApiCompatibility::SYNC_CONTRACT_VERSION,
        'endpoint' => '/api/platform/sync.php',
        'levels' => PlatformSyncProtocol::levels(),
        'conflict_http_status' => 409,
        'background_recovery' => ['state_version', 'etag', 'incremental_cursor'],
    ],
], '1.3.0', $capabilities);

$logger->log('info', 'platform.capabilities.read', $context);
platformApiResponse($context, $data)->send();
