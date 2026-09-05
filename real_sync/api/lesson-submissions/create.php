<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../common/context.php';
require_once __DIR__ . '/../kernel/bootstrap.php';
require_once __DIR__ . '/../platform/PrivateFileStorage.php';
require_once __DIR__ . '/LessonSubmissionService.php';

header('Content-Type: application/json; charset=utf-8');
handleCORS();

$context = platformApiContext(['domain' => 'lesson_review', 'action' => 'lesson_submission.create']);
$logger = new PlatformApiLogger();
platformApiInstallExceptionHandler($context, $logger);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    throw new PlatformApiException(405, 'method_not_allowed', '仅支持 POST 请求');
}

$auth = platformApiAuthContext();
$auth->requirePermission('lesson_submission.create');
$context = $context->withActor($auth->userId(), $auth->staffId());
$input = getRequestInput();
$staffId = (int) $auth->staffId();
$db = getDB();
$service = new LessonSubmissionService($db, new PlatformPrivateFileStorage());
$idempotencyKey = trim((string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
$response = (new PlatformIdempotencyService($db))->execute(
    $context,
    'lesson_submission.create',
    'staff:' . $staffId,
    $idempotencyKey,
    $input,
    static function () use ($service, $input, $staffId, $context, $logger): PlatformApiResponse {
        $result = $service->createWithinTransaction($input, $staffId);
        $migration = PlatformBusinessDomainRegistry::get('lesson_review');
        $result = PlatformApiCompatibility::withMetadata($result, $migration['endpoint_version'], $migration['capabilities']);
        $logger->log('info', 'lesson_submission.create', $context, ['submission_id' => $result['id'], 'version_id' => $result['current_version_id']]);
        return platformApiResponse($context, $result);
    }
);
$response->send();
