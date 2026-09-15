<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/DrillConversationService.php';

$context = drillV2Bootstrap(['POST']);
$input = drillV2Input();
$pdo = getDB();
try {
    $result = drillV2RunIdempotent($pdo, $context, 'drill.turns.submit_text', $input, function () use ($pdo, $context, $input): array {
        return (new DrillConversationService($pdo, DrillAiAdapter::fromProjectRuntime()))->submitTextTurnWithGeneratedCustomer((int) ($input['attempt_id'] ?? 0), (int) $context['staff_id'], (int) ($input['status_version'] ?? 0), (string) ($input['content'] ?? ''), new DateTimeImmutable('now'));
    });
    drillV2Success($result);
} catch (DrillAiRetryableException $error) { drillV2Success(['status' => 'retry_pending', 'status_resource' => '/api/drill/v2/attempt-status.php?attempt_id=' . (int) ($input['attempt_id'] ?? 0)], $error->getMessage(), 202);
} catch (DrillIdempotencyException $error) { drillV2Error($error->statusCode(), $error->getMessage(), [], $error->statusCode());
} catch (DomainException|InvalidArgumentException $error) { drillV2Error(400, $error->getMessage(), [], 400);
} catch (Throwable $error) { error_log('Drill v2 turn failed: ' . $error->getMessage()); drillV2Error(500, '文本轮次提交失败', [], 500); }
