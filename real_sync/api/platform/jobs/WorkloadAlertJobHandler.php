<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/JobDispatcher.php';
require_once dirname(__DIR__, 2) . '/workload/platform/WorkloadPlatformAdapter.php';
require_once dirname(__DIR__, 2) . '/workload/services/WorkloadAlertWorkerService.php';

final class WorkloadAlertJobHandler implements PlatformJobHandler
{
    public function __construct(private PDO $db)
    {
    }

    public function handle(PlatformJobExecutionContext $context, array $payload): array
    {
        $rawNow = trim((string)($payload['now'] ?? ''));
        try {
            $now = $rawNow === '' ? null : new DateTimeImmutable($rawNow, new DateTimeZone('Asia/Shanghai'));
        } catch (Throwable) {
            throw new PlatformJobPermanentFailure('invalid_workload_alert_time');
        }
        WorkloadPlatformAdapter::assertReady($this->db);
        $context->assertCurrent();
        $result = (new WorkloadAlertWorkerService($this->db))->run($now);
        $context->heartbeatIfDue();
        $context->assertCurrent();
        return $result;
    }
}
