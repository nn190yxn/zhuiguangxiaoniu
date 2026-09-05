<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../common/context.php';
require_once __DIR__ . '/../kernel/bootstrap.php';
require_once __DIR__ . '/LessonSuggestionService.php';

header('Content-Type: application/json; charset=utf-8');
handleCORS();
$context = platformApiContext(['domain' => 'lesson_review', 'action' => 'lesson_submission.suggestion_decision']);
$logger = new PlatformApiLogger();
platformApiInstallExceptionHandler($context, $logger);
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new PlatformApiException(405, 'method_not_allowed', '仅支持 POST 请求');
$auth = platformApiAuthContext();
$auth->requirePermission('lesson_submission.optimize');
$context = $context->withActor($auth->userId(), $auth->staffId());
$input = getRequestInput();
$result = (new LessonSuggestionService(getDB()))->decide(
    (int) ($input['submission_id'] ?? 0),
    (int) ($input['suggestion_id'] ?? 0),
    (string) ($input['decision'] ?? ''),
    (int) $auth->staffId(),
    array_key_exists('content', $input) ? (is_array($input['content']) ? $input['content'] : null) : null,
    (int) ($input['status_version'] ?? 0)
);
$migration = PlatformBusinessDomainRegistry::get('lesson_review');
$result = PlatformApiCompatibility::withMetadata($result, $migration['endpoint_version'], $migration['capabilities']);
$logger->log('info', 'lesson_submission.suggestion_decision', $context, ['submission_id' => $result['submission_id'], 'suggestion_id' => $result['suggestion_id'], 'decision' => $result['decision']]);
platformApiResponse($context, $result)->send();
