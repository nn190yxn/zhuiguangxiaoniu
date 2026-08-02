<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/JobDispatcher.php';
require_once dirname(__DIR__, 2) . '/drill/v2/services/DrillGovernanceService.php';

final class DrillGovernanceJobHandler implements PlatformJobHandler
{
    public function __construct(private PDO $db)
    {
    }

    public function handle(PlatformJobExecutionContext $context, array $payload): array
    {
        $actorStaffId = (int)($payload['actor_staff_id'] ?? 0);
        if ($actorStaffId < 0) {
            throw new PlatformJobPermanentFailure('invalid_drill_governance_actor');
        }

        $context->assertCurrent();
        $service = new DrillGovernanceService($this->db);
        $result = $service->expireAudio($actorStaffId, false);
        $context->heartbeatIfDue();
        $context->assertCurrent();
        $result['monitor'] = $service->monitor();
        return $result;
    }
}
