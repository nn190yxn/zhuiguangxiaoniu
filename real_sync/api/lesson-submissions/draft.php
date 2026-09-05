<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../common/context.php';
require_once __DIR__ . '/../kernel/bootstrap.php';
require_once __DIR__ . '/LessonSubmissionService.php';
require_once __DIR__ . '/LessonDraftService.php';

header('Content-Type: application/json; charset=utf-8'); handleCORS();
$context = platformApiContext(['domain' => 'lesson_review', 'action' => 'lesson_submission.draft']);
$logger = new PlatformApiLogger(); platformApiInstallExceptionHandler($context, $logger);
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new PlatformApiException(405, 'method_not_allowed', '仅支持 POST 请求');
$auth = platformApiAuthContext(); $auth->requirePermission('lesson_submission.create'); $context = $context->withActor($auth->userId(), $auth->staffId());
$input = getRequestInput(); $content = $input['content'] ?? null;
if (!is_array($content)) throw new InvalidArgumentException('content 必须是对象');
$result = (new LessonDraftService(getDB()))->saveDraft((int) ($input['submission_id'] ?? 0), $content, (int) $auth->staffId(), (int) ($input['status_version'] ?? 0), (string) ($input['change_reason'] ?? ''));
$migration = PlatformBusinessDomainRegistry::get('lesson_review'); $result = PlatformApiCompatibility::withMetadata($result, $migration['endpoint_version'], $migration['capabilities']);
$logger->log('info', 'lesson_submission.draft', $context, ['submission_id' => $result['submission_id'], 'version_id' => $result['version_id']]); platformApiResponse($context, $result)->send();
