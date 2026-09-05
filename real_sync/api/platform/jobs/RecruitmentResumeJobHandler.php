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
        try {
            $result = (new ResumeProcessingService($this->db))->processJob($job);
        } catch (Throwable $error) {
            $finalAttempt = $context->lease()->attemptCount >= $context->lease()->maxAttempts;
            $permanent = $error instanceof PlatformJobPermanentFailure
                || $error instanceof PlatformJobAmbiguousFailure;
            if ($finalAttempt || $permanent) {
                $this->markRecruitmentFailure($job, $error->getMessage());
            }
            throw $error;
        }
        $update = $this->db->prepare("UPDATE recruitment_resume_jobs SET status = 'succeeded', processing_version_id = ?, locked_at = NULL, locked_by = NULL, lease_expires_at = NULL WHERE id = ?");
        $update->execute([(int) ($result['processing_version_id'] ?? 0), $jobId]);
        $context->assertCurrent();
        return ['recruitment_job_id' => $jobId, 'status' => 'succeeded'] + $result;
    }

    private function markRecruitmentFailure(array $job, string $message): void
    {
        $documentId = (int) ($job['document_id'] ?? 0);
        $message = function_exists('mb_substr')
            ? mb_substr($message, 0, 1000, 'UTF-8')
            : substr($message, 0, 1000);
        $this->db->beginTransaction();
        try {
            $updateJob = $this->db->prepare(
                "UPDATE recruitment_resume_jobs
                 SET status = 'failed', locked_at = NULL, locked_by = NULL, lease_expires_at = NULL,
                     failure_code = 'platform_dead_letter', failure_message = ?
                 WHERE id = ? AND status NOT IN ('succeeded', 'failed')"
            );
            $updateJob->execute([$message, (int) $job['id']]);

            $updateDocument = $this->db->prepare(
                "UPDATE recruitment_resume_documents
                 SET status = 'failed', failure_stage = 'processing',
                     failure_code = 'platform_dead_letter', failure_message = ?
                 WHERE id = ? AND status <> 'completed'"
            );
            $updateDocument->execute([$message, $documentId]);

            $updateBatch = $this->db->prepare(
                "UPDATE recruitment_resume_batches batch
                 JOIN recruitment_resume_documents document ON document.batch_id = batch.id
                 SET batch.status = 'partial_failed', batch.failed_count = batch.failed_count + 1
                 WHERE document.id = ? AND batch.status NOT IN ('completed', 'cancelled')"
            );
            $updateBatch->execute([$documentId]);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }
}
