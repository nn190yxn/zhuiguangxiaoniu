<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/DrillMediaService.php';

$context = drillV2Bootstrap(['POST']);
$input = drillV2Input();
$pdo = getDB();

try {
    $result = drillV2RunIdempotent($pdo, $context, 'drill.media.upload_audio_chunk', $input, function () use ($pdo, $context, $input): array {
        $service = new DrillMediaService($pdo);
        return $service->uploadChunk(
            (int) ($context['staff_id'] ?? 0),
            (int) ($input['audio_asset_id'] ?? 0),
            $input,
            new DateTimeImmutable('now')
        );
    });
    drillV2Success($result, 'success', 201);
} catch (DrillIdempotencyException $exception) {
    drillV2Error($exception->statusCode(), $exception->getMessage(), [], $exception->statusCode());
} catch (DomainException $exception) {
    drillV2Error(400, $exception->getMessage(), [], 400);
} catch (Throwable $exception) {
    error_log('Drill v2 audio chunk failed: ' . $exception->getMessage());
    drillV2Error(500, '音频分片上传失败', [], 500);
}
