<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../common/context.php';
require_once __DIR__ . '/../kernel/bootstrap.php';
require_once __DIR__ . '/ExamSubmissionService.php';

header('Content-Type: application/json; charset=utf-8');
handleCORS();

$context = platformApiContext(['domain' => 'exam', 'action' => 'exam.submit']);
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
$auth->requireAuthenticated();
$context = $context->withActor($auth->userId(), null);
$input = getRequestInput();

$examId = (int) ($input['exam_id'] ?? 0);
$sourceExamId = (int) ($input['source_exam_id'] ?? $examId);
$selectedExamId = (int) ($input['selected_exam_id'] ?? $examId);
$paperCode = strtoupper(trim((string) ($input['paper_code'] ?? '')));
$answers = is_array($input['answers'] ?? null) ? $input['answers'] : [];
$timeSpent = (int) ($input['time_spent'] ?? 0);
if ($examId <= 0 || $sourceExamId <= 0 || $selectedExamId <= 0) {
    throw new PlatformApiException(400, 'exam_identity_required', '缺少考试标识');
}

$idempotencyKey = trim((string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
$request = [
    'source_exam_id' => $sourceExamId,
    'selected_exam_id' => $selectedExamId,
    'paper_code' => $paperCode,
    'answers' => $answers,
    'time_spent' => $timeSpent,
];
$db = getDB();
$result = (new PlatformIdempotencyService($db))->execute(
    $context,
    'exam.submit',
    'exam:' . $sourceExamId,
    $idempotencyKey,
    $request,
    static function () use ($auth, $context, $db, $request): PlatformApiResponse {
        $data = (new ExamSubmissionService($db))->submit((int) $auth->userId(), $request);
        return platformApiResponse($context, $data, '考试提交成功');
    }
);

$logger->log('info', 'exam.submit', $context, [
    'source_exam_id' => $sourceExamId,
    'selected_exam_id' => $selectedExamId,
    'replayed' => $result->replayed(),
]);
$result->send();
