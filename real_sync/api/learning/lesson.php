<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../common/context.php';
require_once __DIR__ . '/../kernel/bootstrap.php';
require_once __DIR__ . '/LearningLessonService.php';

header('Content-Type: application/json; charset=utf-8');
handleCORS();

$context = platformApiContext(['domain' => 'learning', 'action' => 'learning.lesson.complete']);
$logger = new PlatformApiLogger();
platformApiInstallExceptionHandler($context, $logger);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    throw new PlatformApiException(405, 'method_not_allowed', '仅支持 GET 请求');
}

$auth = platformApiAuthContext();
$auth->requireAuthenticated();
$context = $context->withActor($auth->userId(), $auth->staffId());
$lessonId = (int)($_GET['id'] ?? 0);
$result = (new LearningLessonService(getDB(), 'getResourceUrl'))->readAndComplete(
    (int)$auth->userId(),
    $lessonId
);
$migration = PlatformBusinessDomainRegistry::get('learning');
$result = PlatformApiCompatibility::withMetadata(
    $result,
    $migration['endpoint_version'],
    $migration['capabilities']
);

$logger->log('info', 'learning.lesson.complete', $context, [
    'lesson_id' => $lessonId,
    'course_id' => $result['lesson']['course_id'] ?? null,
]);
platformApiResponse($context, $result)->send();
