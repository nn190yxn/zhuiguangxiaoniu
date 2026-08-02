<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/admin/common.php';
require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__) . '/kernel/bootstrap.php';
require_once dirname(__DIR__) . '/platform/JobQueue.php';

header('Content-Type: application/json; charset=utf-8');
handleCORS();
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$context = platformApiContext(['domain' => 'wecom', 'action' => 'wecom.members.sync']);
$logger = new PlatformApiLogger();
platformApiInstallExceptionHandler($context, $logger);
$allowedMethods = ['GET', 'POST'];
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, $allowedMethods, true)) {
    throw new PlatformApiException(405, 'method_not_allowed', '仅支持 GET 或 POST 请求');
}

$auth = platformApiAuthContext();
$auth->requirePermission('wecom.sync');
$context = $context->withActor($auth->userId(), $auth->staffId());
$pdo = wecomDb();
wecomEnsureSchema($pdo);
platformRequireMigrationReadiness($pdo, ['202607310010', '202607310012']);
$migration = PlatformBusinessDomainRegistry::get('wecom');
$input = getRequestInput();
$rootDepartmentId = isset($_GET['department_id'])
    ? (int)$_GET['department_id']
    : (isset($input['department_id']) ? (int)$input['department_id'] : wecomRootDepartmentId());
$rootDepartmentId = max(1, $rootDepartmentId);

if ($method === 'GET') {
    $stmt = $pdo->prepare("SELECT id, status, attempts, max_attempts, available_at, started_at, finished_at, last_error_code, created_at, updated_at
        FROM platform_jobs
        WHERE job_type = 'wecom.members.sync' AND object_type = 'wecom_department' AND object_id = ?
        ORDER BY id DESC LIMIT 1");
    $stmt->execute([(string)$rootDepartmentId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $result = PlatformApiCompatibility::withMetadata([
        'log_id' => null,
        'result' => [
            'root_department_id' => $rootDepartmentId,
            'job' => $job,
        ],
    ], $migration['endpoint_version'], $migration['capabilities']);
    $logger->log('info', 'wecom.members.sync.query', $context, [
        'root_department_id' => $rootDepartmentId,
        'job_id' => $job['id'] ?? null,
        'status' => $job['status'] ?? null,
    ]);
    platformApiResponse($context, $result)->send();
}

$idempotencyKey = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
if ($idempotencyKey === '') {
    $slot = (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('YmdHi');
    $idempotencyKey = hash('sha256', 'wecom.members.sync:' . $rootDepartmentId . ':' . $slot);
}
$pdo->beginTransaction();
try {
    $queue = new PlatformJobQueueService(new PlatformPdoJobQueueStore($pdo));
    $job = $queue->enqueue(
        'wecom.members.sync',
        'wecom_department',
        (string)$rootDepartmentId,
        $idempotencyKey,
        ['root_department_id' => $rootDepartmentId, 'requested_by_staff_id' => $auth->staffId()],
        10,
        3
    );
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $error;
}

$result = PlatformApiCompatibility::withMetadata([
    'log_id' => null,
    'result' => [
        'root_department_id' => $rootDepartmentId,
        'job_id' => (int)$job['id'],
        'status' => (string)$job['status'],
    ],
], $migration['endpoint_version'], $migration['capabilities']);
$logger->log('info', 'wecom.members.sync.queued', $context, [
    'root_department_id' => $rootDepartmentId,
    'job_id' => (int)$job['id'],
    'status' => (string)$job['status'],
]);
platformApiResponse($context, $result, '企业微信成员同步已入队')->send();
