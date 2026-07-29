<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';
require_once dirname(__DIR__) . '/services/DrillMediaService.php';
require_once dirname(__DIR__) . '/services/DrillConversationService.php';

$context = drillV2Bootstrap(['POST']);
$input = drillV2Input();
$pdo = getDB();
try {
    $result = drillV2RunIdempotent($pdo, $context, 'drill.turns.finalize_audio', $input, function () use ($pdo, $context, $input): array {
        $now = new DateTimeImmutable('now');
        $finalized = (new DrillMediaService($pdo))->finalizeTranscription((int) $context['staff_id'], (int) ($input['audio_asset_id'] ?? 0), $input, $now);
        $turn = (new DrillConversationService($pdo, DrillAiAdapter::fromProjectRuntime()))->submitTextTurnWithGeneratedCustomer(
            (int) $finalized['attempt_id'],
            (int) $context['staff_id'],
            (int) ($input['status_version'] ?? 0),
            (string) $finalized['content'],
            $now
        );
        return $finalized + ['turn' => $turn, 'status_resource' => '/api/drill/v2/attempt-status.php?attempt_id=' . (int) $finalized['attempt_id']];
    });
    drillV2Success($result, 'accepted', 202);
} catch (DrillAiRetryableException $error) { drillV2Success(['status' => 'retry_pending', 'status_resource' => '/api/drill/v2/attempt-status.php?attempt_id=' . (int) ($input['attempt_id'] ?? 0)], $error->getMessage(), 202);
} catch (DrillIdempotencyException $error) { drillV2Error($error->statusCode(), $error->getMessage(), [], $error->statusCode());
} catch (DomainException $error) { drillV2Error(400, $error->getMessage(), [], 400);
} catch (Throwable $error) { error_log('Drill v2 turn finalize failed: ' . $error->getMessage()); drillV2Error(500, '语音轮次完成失败', [], 500); }
