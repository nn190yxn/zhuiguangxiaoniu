<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/DrillMediaService.php';

$context = drillV2Bootstrap(['POST']);
$input = drillV2Input();
$pdo = getDB();

try {
    $result = drillV2RunIdempotent($pdo, $context, 'drill.media.finalize_transcription', $input, function () use ($pdo, $context, $input): array {
        $service = new DrillMediaService($pdo);
        return $service->finalizeTranscription(
            (int) ($context['staff_id'] ?? 0),
            (int) ($input['audio_asset_id'] ?? 0),
            $input,
            new DateTimeImmutable('now')
        );
    });
    drillV2Success($result, 'success', 202);
} catch (DrillIdempotencyException $exception) {
    drillV2Error($exception->statusCode(), $exception->getMessage(), [], $exception->statusCode());
} catch (DomainException $exception) {
    drillV2Error(400, $exception->getMessage(), [], 400);
} catch (Throwable $exception) {
    error_log('Drill v2 audio transcript failed: ' . $exception->getMessage());
    drillV2Error(500, '音频转写完成失败', [], 500);
}
