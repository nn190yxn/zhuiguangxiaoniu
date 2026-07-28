<?php

declare(strict_types=1);

require_once __DIR__ . '/DrillContentVersionStateMachine.php';

final class DrillContentVersionBinding
{
    public static function lock(
        int $scenarioVersionId,
        array $personaSnapshot,
        int $rubricVersionId
    ): array {
        if ($scenarioVersionId <= 0 || $rubricVersionId <= 0) {
            throw new InvalidArgumentException('Content version references must be positive integers.');
        }

        return [
            'scenario_version_id' => $scenarioVersionId,
            'persona_snapshot' => $personaSnapshot,
            'persona_snapshot_hash' => DrillContentVersionStateMachine::snapshotHash($personaSnapshot),
            'rubric_version_id' => $rubricVersionId,
        ];
    }
}
