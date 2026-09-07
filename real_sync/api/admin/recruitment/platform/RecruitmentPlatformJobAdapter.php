<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/platform/JobQueue.php';

final class RecruitmentPlatformJobAdapter
{
    public function __construct(private PDO $pdo)
    {
    }

    public function enqueue(array $job): array
    {
        $queue = new PlatformJobQueueService(new PlatformPdoJobQueueStore($this->pdo));
        return $queue->enqueue(
            'recruitment.resume.process',
            'recruitment_resume_job',
            (string) $job['id'],
            // platform_jobs.idempotency_key is CHAR(64); the job hash already provides a stable key.
            (string) $job['idempotency_hash'],
            ['recruitment_job_id' => (int) $job['id']],
            -(int) ($job['priority'] ?? 100),
            (int) ($job['max_attempts'] ?? 3)
        );
    }

    public function dispatchBatch(int $batchId, int $limit = 100): array
    {
        $limit = max(1, min(100, $limit));
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "SELECT job.* FROM recruitment_resume_jobs job
                 JOIN recruitment_resume_documents document ON document.id = job.document_id
                 LEFT JOIN platform_jobs queued ON queued.job_type = 'recruitment.resume.process' AND queued.idempotency_key = job.idempotency_hash
                 WHERE document.batch_id = ? AND job.status IN ('pending', 'ai_pending_retry') AND queued.id IS NULL
                 ORDER BY job.priority ASC, job.id ASC LIMIT {$limit}"
            );
            $stmt->execute([$batchId]);
            $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($jobs as $job) {
                $this->enqueue($job);
            }
            $this->pdo->commit();
            return [
                'batch_id' => $batchId,
                'dispatched_count' => count($jobs),
                'message' => $jobs ? '已投递 ' . count($jobs) . ' 个任务到统一 Worker 队列' : '当前批次没有待投递任务；已入队任务会继续处理，失败文档请使用重试入口',
            ];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function retry(array $job): void
    {
        $queued = $this->enqueue($job);
        // Reuse the same job and preserve its processing version and attempt history.
        $stmt = $this->pdo->prepare("UPDATE platform_jobs SET status = 'pending', max_attempts = attempt_count + 3,
            available_at = CURRENT_TIMESTAMP, completed_at = NULL, error_code = NULL, error_summary = NULL,
            recovery_required = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'dead_letter'");
        $stmt->execute([(int) $queued['id']]);
    }
}
