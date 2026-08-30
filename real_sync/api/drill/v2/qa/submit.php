<?php
declare(strict_types=1);

require_once __DIR__ . '/../_common.php';
require_once __DIR__ . '/../services/DrillQaService.php';

$context = drillV2Bootstrap(['POST']);
$input = drillV2Input();
$pdo = getDB();
try {
    $result = drillV2RunIdempotent($pdo, $context, 'drill.qa.answer.submit', $input, function () use ($pdo, $context, $input): array {
        return (new DrillQaService($pdo, DrillAiAdapter::fromProjectRuntime()))->submitAnswer(
            (int) $context['staff_id'],
            (int) ($input['session_id'] ?? 0),
            (string) ($input['answer'] ?? ''),
            new DateTimeImmutable('now')
        );
    });
    drillV2Success($result);
} catch (DrillAiRetryableException $error) {
    drillV2Success(['status' => 'retry_pending'], $error->getMessage(), 202);
} catch (DrillIdempotencyException $error) {
    drillV2Error($error->statusCode(), $error->getMessage(), [], $error->statusCode());
} catch (DomainException|InvalidArgumentException $error) {
    drillV2Error(400, $error->getMessage(), [], 400);
} catch (Throwable $error) {
    error_log('Drill v2 Q&A submit failed: ' . $error->getMessage());
    drillV2Error(500, '回答提交失败', [], 500);
}
