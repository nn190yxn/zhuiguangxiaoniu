<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/JobDispatcher.php';
require_once dirname(__DIR__, 2) . '/workload/platform/WorkloadPlatformJobAdapter.php';

final class WorkloadExportJobHandler implements PlatformJobHandler
{
    public function __construct(private PDO $db)
    {
    }

    public function handle(PlatformJobExecutionContext $context, array $payload): array
    {
        $context->assertCurrent();
        $result = (new WorkloadPlatformJobAdapter($this->db))->processNextExport(
            static function () use ($context): void {
                $context->heartbeatIfDue();
                $context->assertCurrent();
            }
        );
        $context->heartbeatIfDue();
        return $result;
    }
}
