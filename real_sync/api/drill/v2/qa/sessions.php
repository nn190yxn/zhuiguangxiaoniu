<?php
declare(strict_types=1);

require_once __DIR__ . '/../_common.php';
require_once __DIR__ . '/../services/DrillQaService.php';

$context = drillV2Bootstrap(['GET', 'POST']);
$input = drillV2Input();
$pdo = getDB();
try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $action = (string) ($input['action'] ?? 'create');
        if ($action !== 'create') {
            drillV2Error(400, '不支持的会话操作');
        }
        $result = drillV2RunIdempotent($pdo, $context, 'drill.qa.session.create', $input, function () use ($pdo, $context, $input): array {
            return (new DrillQaService($pdo, DrillAiAdapter::fromProjectRuntime()))->createSession(
                (int) $context['staff_id'],
                (string) ($input['section_code'] ?? 'all'),
                (int) ($input['question_count'] ?? 10),
                new DateTimeImmutable('now')
            );
        });
        drillV2Success($result);
    }
    $sessionId = (int) ($input['session_id'] ?? $_GET['session_id'] ?? 0);
    if ($sessionId <= 0) {
        drillV2Error(400, '缺少有效的 session_id');
    }
    drillV2Success((new DrillQaService($pdo, DrillAiAdapter::fromProjectRuntime()))->sessionState((int) $context['staff_id'], $sessionId));
} catch (DrillAiRetryableException $error) {
    drillV2Success(['status' => 'retry_pending'], $error->getMessage(), 202);
} catch (DrillIdempotencyException $error) {
    drillV2Error($error->statusCode(), $error->getMessage(), [], $error->statusCode());
} catch (DomainException|InvalidArgumentException $error) {
    drillV2Error(400, $error->getMessage(), [], 400);
} catch (Throwable $error) {
    error_log('Drill v2 Q&A session failed: ' . $error->getMessage());
    drillV2Error(500, 'Q&A 会话处理失败', [], 500);
}
