<?php

declare(strict_types=1);

require_once __DIR__ . '/DrillMigrationService.php';

final class DrillLegacyFeedbackAdapter
{
    public function __construct(private PDO $pdo)
    {
    }

    public function resolveRecordingId(string $legacyId, int $userId): ?int
    {
        try {
            $mapping = (new DrillMigrationService($this->pdo))->historyForLegacyFeedback($legacyId, $userId);
            $recordingId = (int) ($mapping['legacy_recording_id'] ?? 0);
            return $recordingId > 0 ? $recordingId : null;
        } catch (Throwable) {
            // Migration tables may not exist until the additive migration is applied.
            return null;
        }
    }

    public function history(string $legacyId, int $userId): ?array
    {
        try {
            return (new DrillMigrationService($this->pdo))->historyForLegacyFeedback($legacyId, $userId);
        } catch (Throwable) {
            return null;
        }
    }
}
