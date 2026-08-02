<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/common/context.php';
require_once dirname(__DIR__) . '/kernel/bootstrap.php';
require_once __DIR__ . '/SyncService.php';

header('Content-Type: application/json; charset=utf-8');
handleCORS();
header('Access-Control-Allow-Methods: GET, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Request-ID, If-None-Match');

$context = platformApiContext([
    'domain' => 'platform',
    'action' => 'sync.' . (string)($_GET['action'] ?? 'changes'),
]);
$logger = new PlatformApiLogger();
platformApiInstallExceptionHandler($context, $logger);

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$auth = platformApiAuthContext();
$auth->requireAuthenticated();
$authData = $auth->toArray();
$staffId = (int)($authData['staff_id'] ?? 0);
if ($staffId <= 0) {
    throw new PlatformApiException(403, 'staff_identity_required', '当前账号未关联员工身份');
}
$context = $context->withActor($auth->userId(), $auth->staffId());
$scopeHash = PlatformSyncProtocol::scopeHash($authData);
$db = getDB();
platformRequireMigrationReadiness($db, ['202607310004']);
$service = new PlatformSyncService(
    new PlatformPdoSyncStore($db),
    hash('sha256', JWT_SECRET . '|platform-sync-cursor')
);
$action = (string)($_GET['action'] ?? 'changes');

if ($method === 'GET' && $action === 'levels') {
    $data = PlatformApiCompatibility::withMetadata([
        'sync_contract_version' => PlatformApiCompatibility::SYNC_CONTRACT_VERSION,
        'sync_levels' => PlatformSyncProtocol::levels(),
    ], '1.0.0', ['sync_levels', 'background_recovery']);
    platformApiResponse($context, $data)->send();
}

if ($method === 'GET' && $action === 'changes') {
    $filters = array_filter([
        'domain' => trim((string)($_GET['domain'] ?? '')),
        'object_type' => trim((string)($_GET['object_type'] ?? '')),
    ], static fn(string $value): bool => $value !== '');
    $result = $service->incremental(
        $scopeHash,
        isset($_GET['cursor']) ? (string)$_GET['cursor'] : null,
        (int)($_GET['limit'] ?? 100),
        $filters
    );
    header('ETag: ' . $result['etag']);
    if (PlatformSyncProtocol::matchesEtag($_SERVER['HTTP_IF_NONE_MATCH'] ?? null, $result['etag'])) {
        http_response_code(304);
        exit;
    }
    $logger->log('info', 'platform.sync.changes', $context, [
        'item_count' => count($result['items']),
        'tombstone_count' => count($result['tombstones']),
        'has_more' => $result['has_more'],
    ]);
    platformApiResponse($context, $result)->send();
}

$input = getRequestInput();
$domain = trim((string)($input['domain'] ?? $_GET['domain'] ?? ''));
$objectType = trim((string)($input['object_type'] ?? $_GET['object_type'] ?? ''));
$objectId = trim((string)($input['object_id'] ?? $_GET['object_id'] ?? ''));

if ($method === 'GET' && $action === 'draft') {
    platformApiResponse($context, [
        'draft' => $service->getDraft($staffId, $domain, $objectType, $objectId),
    ])->send();
}

if ($method === 'PUT' && $action === 'draft') {
    $draft = $service->saveDraft(
        $staffId,
        $domain,
        $objectType,
        $objectId,
        (int)($input['draft_version'] ?? -1),
        (int)($input['base_state_version'] ?? -1),
        is_array($input['payload'] ?? null) ? $input['payload'] : [],
        (string)($input['source_client'] ?? 'web'),
        isset($input['source_device_id']) ? (string)$input['source_device_id'] : null,
        (int)($input['ttl_seconds'] ?? 86400)
    );
    $logger->log('info', 'platform.sync.draft_saved', $context, [
        'domain' => $domain,
        'object_type' => $objectType,
        'object_id' => $objectId,
        'draft_version' => $draft['draft_version'],
    ]);
    platformApiResponse($context, ['draft' => $draft])->send();
}

if ($method === 'DELETE' && $action === 'draft') {
    $tombstone = $service->deleteDraft(
        $staffId,
        $domain,
        $objectType,
        $objectId,
        (int)($input['draft_version'] ?? -1),
        $scopeHash
    );
    $logger->log('info', 'platform.sync.draft_deleted', $context, [
        'domain' => $domain,
        'object_type' => $objectType,
        'object_id' => $objectId,
        'state_version' => $tombstone['state_version'],
    ]);
    platformApiResponse($context, ['tombstone' => $tombstone])->send();
}

throw new PlatformApiException(405, 'method_not_allowed', '同步操作或请求方法无效');
