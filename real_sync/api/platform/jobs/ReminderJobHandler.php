<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/JobDispatcher.php';
require_once dirname(__DIR__, 2) . '/reminder/_common.php';

final class ReminderJobHandler implements PlatformJobHandler
{
    private const PHASES = ['learning_required', 'first', 'second', 'store_summary', 'hq_summary'];

    public function __construct(private PDO $db)
    {
    }

    public function handle(PlatformJobExecutionContext $context, array $payload): array
    {
        $reportDate = (string)($payload['report_date'] ?? '');
        $phase = (string)($payload['phase'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate) || !in_array($phase, self::PHASES, true)) {
            throw new PlatformJobPermanentFailure('invalid_reminder_payload');
        }

        reminderEnsureSchema($this->db);
        $generated = $phase === 'learning_required'
            ? reminderBuildLearningJobs($this->db, $reportDate)
            : reminderBuildWorkloadJobs($this->db, $reportDate, $phase);

        $jobIds = [];
        foreach ($generated['jobs'] as $job) {
            $context->heartbeatIfDue();
            $jobId = reminderUpsertJob($this->db, $job);
            if ($jobId > 0) {
                $jobIds[] = $jobId;
            }
        }

        $dispatchResults = [];
        foreach ($jobIds as $jobId) {
            $context->heartbeatIfDue();
            $context->assertCurrent();
            $dispatchResults[] = reminderDispatchJob($this->db, $jobId);
        }

        return [
            'report_date' => $reportDate,
            'phase' => $phase,
            'generated' => count($generated['jobs']),
            'stored' => count($jobIds),
            'skipped' => count($generated['skipped']),
            'dispatched' => count($dispatchResults),
        ];
    }
}
