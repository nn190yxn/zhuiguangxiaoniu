<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../common/context.php';
require_once __DIR__ . '/../kernel/bootstrap.php';
require_once __DIR__ . '/LessonSubmissionService.php';
require_once __DIR__ . '/LessonDraftService.php';

header('Content-Type: application/json; charset=utf-8'); handleCORS();
$context = platformApiContext(['domain' => 'lesson_review', 'action' => 'lesson_submission.detail']);
$logger = new PlatformApiLogger(); platformApiInstallExceptionHandler($context, $logger);
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') throw new PlatformApiException(405, 'method_not_allowed', '仅支持 GET 请求');
$auth = platformApiAuthContext(); $auth->requirePermission('lesson_submission.create'); $context = $context->withActor($auth->userId(), $auth->staffId());
$result = (new LessonDraftService(getDB()))->detail((int) ($_GET['id'] ?? 0), (int) $auth->staffId());
$migration = PlatformBusinessDomainRegistry::get('lesson_review'); $result = PlatformApiCompatibility::withMetadata($result, $migration['endpoint_version'], $migration['capabilities']);
$logger->log('info', 'lesson_submission.detail', $context, ['submission_id' => (int) ($_GET['id'] ?? 0)]); platformApiResponse($context, $result)->send();
