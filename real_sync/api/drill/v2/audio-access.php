<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/DrillMediaService.php';

$context = drillV2Bootstrap(['GET', 'POST']);
$input = drillV2Input();
if ($input === [] && isset($_GET['audio_asset_id'])) {
    $input['audio_asset_id'] = $_GET['audio_asset_id'];
}
$context['request_id'] = function_exists('appRequestId') ? appRequestId() : '';
$context['access_reason'] = trim((string) ($input['access_reason'] ?? $_GET['access_reason'] ?? 'audio_playback'));
$pdo = getDB();

try {
    $service = new DrillMediaService($pdo);
    if (filter_var($_GET['download'] ?? false, FILTER_VALIDATE_BOOL)) {
        $service->streamAudioAsset(
            (int) ($context['staff_id'] ?? 0),
            (int) ($input['audio_asset_id'] ?? 0),
            $context,
            new DateTimeImmutable('now')
        );
        exit;
    }
    $result = $service->accessAudioAsset(
        (int) ($context['staff_id'] ?? 0),
        (int) ($input['audio_asset_id'] ?? 0),
        $context,
        new DateTimeImmutable('now')
    );
    drillV2Success($result);
} catch (DomainException $exception) {
    drillV2Error(403, $exception->getMessage(), ['authorization_status' => 'pending_or_denied'], 403);
} catch (Throwable $exception) {
    error_log('Drill v2 audio access failed: ' . $exception->getMessage());
    drillV2Error(500, '音频资源读取失败', [], 500);
}
