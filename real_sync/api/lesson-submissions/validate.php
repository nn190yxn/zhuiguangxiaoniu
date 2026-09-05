<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../common/context.php';
require_once __DIR__ . '/../kernel/bootstrap.php';
require_once __DIR__ . '/LessonSubmissionService.php';
require_once __DIR__ . '/LessonDraftService.php';
require_once __DIR__ . '/LessonAceRuleChecker.php';

header('Content-Type: application/json; charset=utf-8'); handleCORS();
$context = platformApiContext(['domain' => 'lesson_review', 'action' => 'lesson_submission.validate']);
$logger = new PlatformApiLogger(); platformApiInstallExceptionHandler($context, $logger);
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new PlatformApiException(405, 'method_not_allowed', '仅支持 POST 请求');
$auth = platformApiAuthContext(); $auth->requirePermission('lesson_submission.create'); $context = $context->withActor($auth->userId(), $auth->staffId());
$input = getRequestInput();
$submissionId = (int) ($input['submission_id'] ?? 0);
$detail = (new LessonDraftService(getDB()))->detail($submissionId, (int) $auth->staffId());
$currentVersion = $detail['current_version'] ?? null;
if (!is_array($currentVersion) || !is_array($currentVersion['content_json'] ?? null)) throw new PlatformApiException(409, 'lesson_version_unavailable', '当前教案没有可检查的结构化版本');
$hasRequestContent = array_key_exists('content', $input);
if ($hasRequestContent && !is_array($input['content'])) throw new InvalidArgumentException('content 必须是对象');
$result = (new LessonAceRuleChecker())->check($hasRequestContent ? $input['content'] : $currentVersion['content_json']);
$result['submission_id'] = $submissionId;
$result['version_id'] = (int) $currentVersion['id'];
$result['version_no'] = (int) $currentVersion['version_no'];
$result['content_source'] = $hasRequestContent ? 'request_content' : 'current_version';
$migration = PlatformBusinessDomainRegistry::get('lesson_review'); $result = PlatformApiCompatibility::withMetadata($result, $migration['endpoint_version'], $migration['capabilities']);
$logger->log('info', 'lesson_submission.validate', $context, ['submission_id' => $submissionId, 'version_id' => $result['version_id'], 'error_count' => $result['error_count'], 'warning_count' => $result['warning_count']]); platformApiResponse($context, $result)->send();
