<?php

declare(strict_types=1);

final class DrillCoachingService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function recordAndReopen(int $coachingTaskId, int $coachId, string $notes, DateTimeImmutable $now): array
    {
        $this->pdo->beginTransaction();
        try {
            $task = $this->pdo->prepare("SELECT * FROM drill_coaching_tasks WHERE id = ? AND coach_staff_id = ? AND status IN ('open', 'in_progress') FOR UPDATE");
            $task->execute([$coachingTaskId, $coachId]);
            $row = $task->fetch(PDO::FETCH_ASSOC) ?: throw new DomainException('辅导任务不可处理。');
            if (trim($notes) === '') throw new DomainException('辅导记录不能为空。');
            $this->pdo->prepare("UPDATE drill_coaching_tasks SET status = 'completed', notes = ?, coaching_record_json = ?, completed_at = ?, reopened_at = ? WHERE id = ?")->execute([$notes, json_encode(['coach_staff_id' => $coachId, 'notes' => $notes, 'recorded_at' => $now->format(DATE_ATOM)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $now->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s'), $coachingTaskId]);
            $this->pdo->prepare("UPDATE drill_assignments SET status = 'retry_available', failed_attempts = 0, status_version = status_version + 1 WHERE id = ? AND status = 'coaching_required'")->execute([(int) $row['assignment_id']]);
            $this->pdo->commit();
            return ['coaching_task_id' => $coachingTaskId, 'assignment_id' => (int) $row['assignment_id'], 'status' => 'retry_available'];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }
}
