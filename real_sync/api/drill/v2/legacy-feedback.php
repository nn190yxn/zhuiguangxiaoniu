<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/DrillLegacyFeedbackAdapter.php';

try {
    $context = drillV2Bootstrap(['GET']);
    $legacyId = trim((string) ($_GET['id'] ?? $_GET['feedback_id'] ?? ''));
    if ($legacyId === '') {
        drillV2Error(400, '缺少旧反馈 ID。', [], 400);
    }
    $history = (new DrillLegacyFeedbackAdapter(getDB()))->history($legacyId, (int) $context['user_id']);
    if ($history === null) {
        drillV2Error(404, '历史反馈映射不存在。', [], 404);
    }
    drillV2Success([
        'legacy_feedback_id' => $history['legacy_feedback_id'],
        'legacy_analysis_id' => $history['legacy_analysis_id'],
        'legacy_recording_id' => $history['legacy_recording_id'],
        'history_instance_id' => (int) $history['history_instance_id'],
        'readonly' => true,
        'evaluation_context' => $history['evaluation_context'],
        'source_summary' => json_decode((string) $history['source_summary_json'], true),
    ]);
} catch (DomainException|InvalidArgumentException $error) {
    drillV2Error(400, $error->getMessage(), [], 400);
} catch (Throwable $error) {
    error_log('Drill legacy feedback adapter failed: ' . $error->getMessage());
    drillV2Error(500, '历史反馈查询失败。', [], 500);
}
