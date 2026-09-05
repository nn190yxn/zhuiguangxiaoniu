<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../common/context.php';
require_once __DIR__ . '/../kernel/bootstrap.php';
require_once __DIR__ . '/LearningLessonService.php';

header('Content-Type: application/json; charset=utf-8');
handleCORS();

$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? 'learning.lesson.complete' : 'learning.lesson.read';
$context = platformApiContext(['domain' => 'learning', 'action' => $action]);
$logger = new PlatformApiLogger();
platformApiInstallExceptionHandler($context, $logger);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    throw new PlatformApiException(405, 'method_not_allowed', '仅支持 GET 或 POST 请求');
}

$auth = platformApiAuthContext();
$auth->requireAuthenticated();
$context = $context->withActor($auth->userId(), $auth->staffId());
$lessonId = (int)($_GET['id'] ?? 0);
$payload = json_decode((string)file_get_contents('php://input'), true);
$payload = is_array($payload) ? $payload : [];
$expectedVersion = $_SERVER['HTTP_X_STATE_VERSION'] ?? ($payload['state_version'] ?? null);
$expectedVersion = $expectedVersion === null || $expectedVersion === '' ? null : (int)$expectedVersion;
$idempotencyKey = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ($payload['idempotency_key'] ?? '')));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $idempotencyKey === '') {
    throw new PlatformApiException(400, 'idempotency_key_required', '写请求必须提供有效的 Idempotency-Key');
}
$service = new LearningLessonService(getDB(), 'getResourceUrl');
$result = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? $service->complete((int)$auth->userId(), $lessonId, $expectedVersion, $idempotencyKey)
    : $service->read((int)$auth->userId(), $lessonId);
$migration = PlatformBusinessDomainRegistry::get('learning');
$result = PlatformApiCompatibility::withMetadata(
    $result,
    $migration['endpoint_version'],
    $migration['capabilities']
);

$logger->log('info', $action, $context, [
    'lesson_id' => $lessonId,
    'course_id' => $result['lesson']['course_id'] ?? null,
]);
platformApiResponse($context, $result)->send();
