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
            'recruitment.resume.process:' . (string) $job['idempotency_hash'],
            ['recruitment_job_id' => (int) $job['id']],
            -(int) ($job['priority'] ?? 100),
            (int) ($job['max_attempts'] ?? 3)
        );
    }
}
