<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/platform/OutboxService.php';

final class RecruitmentReminderProjection
{
    private PlatformOutboxService $outbox;

    public function __construct(private PDO $pdo)
    {
        $runner = new PlatformJobRunner(new PlatformPdoJobStore($pdo), new PlatformRetryPolicy(), PlatformJobRunner::defaultWorkerId('recruitment-outbox'));
        $this->outbox = new PlatformOutboxService(new PlatformPdoOutboxStore($pdo), $runner);
    }

    public function contactChanged(int $applicationId, string $status, ?string $scheduledAt, string $idempotencyKey): array
    {
        return $this->enqueue('recruitment.contact.changed', $applicationId, $idempotencyKey, [
            'application_id' => $applicationId,
            'contact_status' => $status,
            'scheduled_at' => $scheduledAt,
        ]);
    }

    public function hireConverted(int $applicationId, int $staffId, string $idempotencyKey): array
    {
        return $this->enqueue('recruitment.hire.converted', $applicationId, $idempotencyKey, [
            'application_id' => $applicationId,
            'employee_staff_id' => $staffId,
        ]);
    }

    private function enqueue(string $eventType, int $applicationId, string $idempotencyKey, array $payload): array
    {
        $eventKey = $eventType . ':' . $applicationId . ':' . substr(hash('sha256', $idempotencyKey), 0, 20);
        return $this->outbox->enqueue($eventKey, 'recruitment_application:' . $applicationId, 'recruitment:' . $applicationId, $eventKey, $eventType, $payload, true);
    }
}
