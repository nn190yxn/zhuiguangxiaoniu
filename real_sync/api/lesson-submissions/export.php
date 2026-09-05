<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../common/context.php';
require_once __DIR__ . '/../kernel/bootstrap.php';
require_once __DIR__ . '/../platform/PrivateFileStorage.php';
require_once __DIR__ . '/LessonExportService.php';

header('Content-Type: application/json; charset=utf-8');
handleCORS();
$context = platformApiContext(['domain' => 'lesson_review', 'action' => 'lesson_submission.export']);
$logger = new PlatformApiLogger();
platformApiInstallExceptionHandler($context, $logger);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$auth = platformApiAuthContext();
$auth->requirePermission('lesson_submission.export');
$context = $context->withActor($auth->userId(), $auth->staffId());
$staffId = (int) $auth->staffId();
$db = getDB();
$service = new LessonExportService($db, new PlatformPrivateFileStorage());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = getRequestInput();
    $submissionId = (int) ($input['submission_id'] ?? 0);
    $format = strtolower(trim((string) ($input['format'] ?? '')));
    $requestedVersionId = (int) ($input['version_id'] ?? 0) ?: null;
    $versionId = $service->resolveVersionId($submissionId, $format, $staffId, $requestedVersionId);
    $idempotencyKey = trim((string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
    $request = ['submission_id' => $submissionId, 'version_id' => $versionId, 'format' => $format];
    $response = (new PlatformIdempotencyService($db))->execute(
        $context,
        'lesson_submission.export',
        'submission:' . $submissionId . ':version:' . $versionId . ':format:' . $format,
        $idempotencyKey,
        $request,
        static function () use ($service, $submissionId, $format, $staffId, $versionId, $context): PlatformApiResponse {
            $result = $service->createWithinTransaction($submissionId, $format, $staffId, $versionId);
            $migration = PlatformBusinessDomainRegistry::get('lesson_review');
            $result = PlatformApiCompatibility::withMetadata($result, $migration['endpoint_version'], $migration['capabilities']);
            return platformApiResponse($context, $result, '导出已完成');
        }
    );
    $response->send();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    throw new PlatformApiException(405, 'method_not_allowed', '仅支持 GET 或 POST 请求');
}

$result = $service->download((int) ($_GET['id'] ?? 0), $staffId);
$row = $result['row'];
header('Content-Type: ' . $result['download']['mime_type']);
header('Content-Length: ' . $result['download']['byte_size']);
header('Content-Disposition: attachment; filename="download.' . $row['format'] . '"; filename*=UTF-8\'\'' . rawurlencode($result['download']['filename']));
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
(new PlatformPrivateFileStorage())->stream($result['download']);
exit;
