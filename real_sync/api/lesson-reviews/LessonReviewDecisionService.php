<?php
declare(strict_types=1);

final class LessonReviewDecisionService
{
    private const SUPERVISOR_ROLES = ['teaching_supervisor', 'supervisor', '教学主管', '督导'];

    public function __construct(private PDO $pdo)
    {
    }

    public function decide(int $taskId, int $reviewerStaffId, string $decision, ?string $comments = null, array $allowedStages = ['store_review', 'supervisor_review']): array
    {
        if ($taskId <= 0 || $reviewerStaffId <= 0) throw new InvalidArgumentException('审核任务或审核人身份无效');
        $decision = strtolower(trim($decision));
        if (!in_array($decision, ['approved', 'returned'], true)) throw new InvalidArgumentException('审核决定必须为 approved 或 returned');
        $comments = trim((string) $comments);
        if ($decision === 'returned' && $comments === '') throw new InvalidArgumentException('退回审核必须填写原因');

        $this->pdo->beginTransaction();
        try {
            $task = $this->task($taskId, $reviewerStaffId);
            $submission = $this->submission((int) $task['submission_id']);
            if ((string) $task['status'] !== 'pending') throw new PlatformApiException(409, 'lesson_review_task_handled', '审核任务已处理');
            if ((int) $submission['current_version_id'] !== (int) $task['version_id']) throw new PlatformApiException(409, 'lesson_review_version_mismatch', '审核版本已变化，请重新读取任务');
            $fromStatus = (string) $submission['status'];
            $stage = (string) $task['stage'];
            if (!in_array($stage, $allowedStages, true)) throw new PlatformApiException(403, 'lesson_review_stage_forbidden', '当前角色无权处理该审核阶段');
            if (($stage === 'store_review' && (string) $task['reviewer_role'] !== 'manager') || ($stage === 'supervisor_review' && !in_array((string) $task['reviewer_role'], self::SUPERVISOR_ROLES, true))) throw new PlatformApiException(409, 'lesson_review_stage_invalid', '审核任务阶段无效');
            if ($fromStatus !== $stage) throw new PlatformApiException(409, 'lesson_review_stage_conflict', '教案审核阶段已变化，请刷新后重试');

            $nextStatus = 'returned'; $nextTaskId = null; $approvedVersionId = null; $nextReviewer = null; $libraryStatus = null;
            if ($decision === 'approved' && $stage === 'store_review') {
                $nextReviewer = $this->supervisor();
                if (!$nextReviewer) throw new PlatformApiException(409, 'lesson_supervisor_unavailable', '当前没有可用的教学主管审核人');
                $nextStatus = 'supervisor_review';
            } elseif ($decision === 'approved' && $stage === 'supervisor_review') {
                $nextStatus = 'approved'; $approvedVersionId = (int) $task['version_id']; $libraryStatus = 'published';
            }
            $updateTask = $this->pdo->prepare("UPDATE lesson_review_tasks SET status = 'completed', decision = ?, comments = ?, decided_at = NOW() WHERE id = ? AND reviewer_staff_id = ? AND status = 'pending'");
            $updateTask->execute([$decision, $comments !== '' ? $comments : null, $taskId, $reviewerStaffId]);
            if ($updateTask->rowCount() !== 1) throw new PlatformApiException(409, 'lesson_review_task_conflict', '审核任务状态已变化，请刷新后重试');
            if ($nextReviewer) {
                $insert = $this->pdo->prepare("INSERT INTO lesson_review_tasks (submission_id, version_id, reviewer_staff_id, reviewer_role, stage, status) VALUES (?, ?, ?, 'teaching_supervisor', 'supervisor_review', 'pending')");
                $insert->execute([(int) $task['submission_id'], (int) $task['version_id'], (int) $nextReviewer['staff_id']]); $nextTaskId = (int) $this->pdo->lastInsertId();
            }
            if ($libraryStatus === 'published') {
                $updateSubmission = $this->pdo->prepare("UPDATE lesson_submissions SET status = 'approved', approved_version_id = ?, library_status = 'published', library_published_at = NOW(), library_published_by_staff_id = ?, status_version = status_version + 1 WHERE id = ? AND current_version_id = ? AND status_version = ? AND status = 'supervisor_review'");
                $updateSubmission->execute([$approvedVersionId, $reviewerStaffId, (int) $task['submission_id'], (int) $task['version_id'], (int) $submission['status_version']]);
            } else {
                $updateSubmission = $this->pdo->prepare('UPDATE lesson_submissions SET status = ?, status_version = status_version + 1 WHERE id = ? AND current_version_id = ? AND status_version = ?');
                $updateSubmission->execute([$nextStatus, (int) $task['submission_id'], (int) $task['version_id'], (int) $submission['status_version']]);
            }
            if ($updateSubmission->rowCount() !== 1) throw new PlatformApiException(409, 'lesson_submission_conflict', '教案状态已变化，请刷新后重试');
            $this->audit((int) $task['submission_id'], (int) $task['version_id'], $reviewerStaffId, 'review_' . $decision, $fromStatus, $nextStatus, ['review_task_id' => $taskId, 'reviewer_role' => $task['reviewer_role'], 'comments' => $comments, 'next_review_task_id' => $nextTaskId, 'library_status' => $libraryStatus]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        return ['review_task_id' => $taskId, 'submission_id' => (int) $task['submission_id'], 'version_id' => (int) $task['version_id'], 'decision' => $decision, 'comments' => $comments, 'status' => $nextStatus, 'next_review_task_id' => $nextTaskId, 'approved_version_id' => $approvedVersionId, 'library_status' => $libraryStatus];
    }

    private function task(int $id, int $reviewerStaffId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM lesson_review_tasks WHERE id = ? AND reviewer_staff_id = ?' . $this->lockClause());
        $stmt->execute([$id, $reviewerStaffId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new PlatformApiException(404, 'lesson_review_task_not_found', '审核任务不存在或无权处理');
        return $row;
    }

    private function submission(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT id, status, status_version, current_version_id FROM lesson_submissions WHERE id = ?' . $this->lockClause());
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new PlatformApiException(404, 'lesson_submission_not_found', '教案不存在');
        return $row;
    }

    private function lockClause(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
    }
    private function supervisor(): ?array { $roles = implode(',', array_fill(0, count(self::SUPERVISOR_ROLES), '?')); $stmt = $this->pdo->prepare("SELECT id AS staff_id, name, role FROM staffs WHERE status = 1 AND user_id IS NOT NULL AND user_id > 0 AND role IN ($roles) ORDER BY id ASC LIMIT 1"); $stmt->execute(self::SUPERVISOR_ROLES); $row = $stmt->fetch(PDO::FETCH_ASSOC); return $row ?: null; }
    private function audit(int $submissionId, int $versionId, int $staffId, string $action, string $from, string $to, array $metadata): void { $this->pdo->prepare('INSERT INTO lesson_audit_logs (submission_id, version_id, actor_staff_id, action, from_status, to_status, metadata_json) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$submissionId, $versionId, $staffId, $action, $from, $to, json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]); }
}
