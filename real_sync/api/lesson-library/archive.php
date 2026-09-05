<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../common/context.php';
require_once __DIR__ . '/../kernel/bootstrap.php';
require_once __DIR__ . '/LessonArchiveService.php';

header('Content-Type: application/json; charset=utf-8');
handleCORS();
$context = platformApiContext(['domain' => 'lesson_review', 'action' => 'lesson_library.archive']);
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
$auth->requirePermission('lesson_review.supervisor_decide');
$context = $context->withActor($auth->userId(), $auth->staffId());
$input = getRequestInput();
$database = getDB();
platformRequireMigrationReadiness($database, ['202609050001']);
$result = (new LessonArchiveService($database))->archive(
    (int) ($input['submission_id'] ?? 0),
    (int) $auth->staffId(),
    (int) ($input['status_version'] ?? 0),
    $input['reason'] ?? null
);
$migration = PlatformBusinessDomainRegistry::get('lesson_review');
$result = PlatformApiCompatibility::withMetadata($result, $migration['endpoint_version'], $migration['capabilities']);
$logger->log('info', 'lesson_library.archive', $context, ['submission_id' => $result['submission_id'], 'approved_version_id' => $result['approved_version_id']]);
platformApiResponse($context, $result, '教案已归档')->send();
