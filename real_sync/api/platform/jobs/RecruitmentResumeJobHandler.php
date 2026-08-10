<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/JobDispatcher.php';
require_once dirname(__DIR__, 2) . '/admin/recruitment/_common.php';
require_once dirname(__DIR__, 2) . '/admin/recruitment/services/ResumeProcessingService.php';

final class RecruitmentResumeJobHandler implements PlatformJobHandler
{
    public function __construct(private PDO $db)
    {
    }

    public function handle(PlatformJobExecutionContext $context, array $payload): array
    {
        $jobId = (int) ($payload['recruitment_job_id'] ?? 0);
        if ($jobId <= 0) {
            throw new PlatformJobPermanentFailure('invalid_recruitment_job_payload');
        }
        $context->assertCurrent();
        $stmt = $this->db->prepare('SELECT * FROM recruitment_resume_jobs WHERE id = ? LIMIT 1');
        $stmt->execute([$jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$job) {
            throw new PlatformJobPermanentFailure('recruitment_job_not_found');
        }
        if ((string) $job['status'] === 'succeeded') {
            return ['recruitment_job_id' => $jobId, 'status' => 'succeeded', 'replayed' => true];
        }
        $context->heartbeatIfDue();
        $result = (new ResumeProcessingService($this->db))->processJob($job);
        $update = $this->db->prepare("UPDATE recruitment_resume_jobs SET status = 'succeeded', processing_version_id = ?, locked_at = NULL, locked_by = NULL, lease_expires_at = NULL WHERE id = ?");
        $update->execute([(int) ($result['processing_version_id'] ?? 0), $jobId]);
        $context->assertCurrent();
        return ['recruitment_job_id' => $jobId, 'status' => 'succeeded'] + $result;
    }
}
