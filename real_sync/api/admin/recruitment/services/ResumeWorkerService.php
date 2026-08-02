<?php

declare(strict_types=1);

require_once __DIR__ . '/ResumeProcessingService.php';

final class ResumeWorkerService
{
    private PDO $pdo;
    private ResumeProcessingService $processing;
    private string $workerId;

    public function __construct(PDO $pdo, ?string $workerId = null)
    {
        $this->pdo = $pdo;
        $this->processing = new ResumeProcessingService($pdo);
        $this->workerId = mb_substr($workerId ?: gethostname() . '-' . getmypid(), 0, 120, 'UTF-8');
    }

    public function run(int $limit = 4): array
    {
        $limit = max(1, min(20, $limit));
        $results = [];
        for ($index = 0; $index < $limit; $index++) {
            $job = $this->claim();
            if ($job === null) {
                break;
            }
            try {
                $result = $this->processing->processJob($job);
                $this->succeed((int) $job['id'], (int) $result['processing_version_id']);
                $results[] = ['job_id' => (int) $job['id'], 'status' => 'succeeded'];
            } catch (ResumeAiRetryableException $error) {
                $status = $this->retryAi($job, $error->getMessage());
                $results[] = ['job_id' => (int) $job['id'], 'status' => $status];
            } catch (RecruitmentAdminException $error) {
                if ($error->statusCode() === 503) {
                    $status = $this->retryAi($job, $error->getMessage());
                    $results[] = ['job_id' => (int) $job['id'], 'status' => $status];
                } else {
                    $this->fail($job, $error->getMessage(), 'processing_failed');
                    $results[] = ['job_id' => (int) $job['id'], 'status' => 'failed'];
                }
            } catch (Throwable $error) {
                $this->fail($job, $error->getMessage(), 'processing_failed');
                $results[] = ['job_id' => (int) $job['id'], 'status' => 'failed'];
            }
        }
        return ['worker_id' => $this->workerId, 'claimed_count' => count($results), 'jobs' => $results];
    }

    private function claim(): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->query(
                "SELECT * FROM recruitment_resume_jobs WHERE ((status IN ('pending', 'ai_pending_retry') AND available_at <= NOW()) OR (status = 'running' AND lease_expires_at < NOW())) ORDER BY priority ASC, id ASC LIMIT 1 FOR UPDATE"
            );
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$job) {
                $this->pdo->commit();
                return null;
            }
            $update = $this->pdo->prepare(
                "UPDATE recruitment_resume_jobs SET status = 'running', attempt_count = attempt_count + 1, locked_at = NOW(), locked_by = ?, lease_expires_at = DATE_ADD(NOW(), INTERVAL 5 MINUTE), failure_code = NULL, failure_message = NULL WHERE id = ?"
            );
            $update->execute([$this->workerId, (int) $job['id']]);
            $document = $this->pdo->prepare("UPDATE recruitment_resume_documents SET status = 'processing' WHERE id = ? AND status IN ('queued', 'failed', 'processing')");
            $document->execute([(int) $job['document_id']]);
            $batch = $this->pdo->prepare(
                "UPDATE recruitment_resume_batches batch JOIN recruitment_resume_documents document ON document.batch_id = batch.id SET batch.status = 'processing', batch.started_at = COALESCE(batch.started_at, NOW()) WHERE document.id = ? AND batch.status IN ('draft', 'uploaded', 'processing', 'partial_failed')"
            );
            $batch->execute([(int) $job['document_id']]);
            $this->pdo->commit();
            $job['attempt_count'] = (int) $job['attempt_count'] + 1;
            return $job;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function succeed(int $jobId, int $processingVersionId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE recruitment_resume_jobs SET status = 'succeeded', processing_version_id = ?, locked_at = NULL, locked_by = NULL, lease_expires_at = NULL WHERE id = ? AND locked_by = ?"
        );
        $stmt->execute([$processingVersionId, $jobId, $this->workerId]);
    }

    private function retryAi(array $job, string $message): string
    {
        $exhausted = (int) $job['attempt_count'] >= (int) $job['max_attempts'];
        $status = $exhausted ? 'ai_retry_exhausted' : 'ai_pending_retry';
        $delay = min(3600, 30 * (2 ** max(0, (int) $job['attempt_count'] - 1)));
        $stmt = $this->pdo->prepare(
            'UPDATE recruitment_resume_jobs SET status = ?, available_at = DATE_ADD(NOW(), INTERVAL ? SECOND), locked_at = NULL, locked_by = NULL, lease_expires_at = NULL, failure_code = ?, failure_message = ? WHERE id = ? AND locked_by = ?'
        );
        $stmt->execute([$status, $delay, 'ai_retryable', mb_substr($message, 0, 1000, 'UTF-8'), (int) $job['id'], $this->workerId]);
        return $status;
    }

    private function fail(array $job, string $message, string $code): void
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "UPDATE recruitment_resume_jobs SET status = 'failed', locked_at = NULL, locked_by = NULL, lease_expires_at = NULL, failure_code = ?, failure_message = ? WHERE id = ? AND locked_by = ?"
            );
            $stmt->execute([$code, mb_substr($message, 0, 1000, 'UTF-8'), (int) $job['id'], $this->workerId]);
            $document = $this->pdo->prepare(
                "UPDATE recruitment_resume_documents SET status = 'failed', failure_stage = 'processing', failure_code = ?, failure_message = ? WHERE id = ?"
            );
            $document->execute([$code, mb_substr($message, 0, 1000, 'UTF-8'), (int) $job['document_id']]);
            $files = $this->pdo->prepare(
                "UPDATE recruitment_resume_files file JOIN recruitment_resume_document_pages page ON page.resume_file_id = file.id SET file.status = 'failed', file.failure_stage = 'processing', file.failure_code = ?, file.failure_message = ? WHERE page.document_id = ?"
            );
            $files->execute([$code, mb_substr($message, 0, 1000, 'UTF-8'), (int) $job['document_id']]);
            $batch = $this->pdo->prepare(
                "UPDATE recruitment_resume_batches batch JOIN recruitment_resume_documents document ON document.batch_id = batch.id SET batch.status = 'partial_failed', batch.failed_count = batch.failed_count + 1 WHERE document.id = ?"
            );
            $batch->execute([(int) $job['document_id']]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}
