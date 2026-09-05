<?php
declare(strict_types=1);

final class LessonReviewQueryService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function list(int $reviewerStaffId, ?string $status = null, ?string $stage = null): array
    {
        if ($reviewerStaffId <= 0) throw new InvalidArgumentException('审核人身份无效');
        $where = ['task.reviewer_staff_id = ?']; $params = [$reviewerStaffId];
        if ($status !== null && trim($status) !== '') { $where[] = 'task.status = ?'; $params[] = trim($status); }
        if ($stage !== null && trim($stage) !== '') { $where[] = 'task.stage = ?'; $params[] = trim($stage); }
        $stmt = $this->pdo->prepare('SELECT task.id, task.submission_id, task.version_id, task.reviewer_staff_id, task.reviewer_role, task.stage, task.status, task.decision, task.comments, task.decided_at, task.created_at, submission.store_id, submission.store_name, submission.author_name, submission.course_line, submission.class_level, submission.lesson_date, submission.title, version.version_no, version.is_submitted, version.is_immutable FROM lesson_review_tasks task JOIN lesson_submissions submission ON submission.id = task.submission_id JOIN lesson_versions version ON version.id = task.version_id AND version.submission_id = task.submission_id WHERE ' . implode(' AND ', $where) . ' ORDER BY task.status ASC, task.created_at DESC, task.id DESC');
        $stmt->execute($params); return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function detail(int $taskId, int $reviewerStaffId): array
    {
        if ($taskId <= 0 || $reviewerStaffId <= 0) throw new InvalidArgumentException('审核任务或审核人身份无效');
        $stmt = $this->pdo->prepare('SELECT task.*, submission.store_id, submission.store_name, submission.author_staff_id, submission.author_name, submission.course_line, submission.class_level, submission.lesson_date, submission.title, submission.status AS submission_status, submission.status_version, version.version_no, version.content_json, version.source_snapshot_json, version.changed_fields_json, version.is_submitted, version.is_immutable FROM lesson_review_tasks task JOIN lesson_submissions submission ON submission.id = task.submission_id JOIN lesson_versions version ON version.id = task.version_id AND version.submission_id = task.submission_id WHERE task.id = ? AND task.reviewer_staff_id = ? LIMIT 1');
        $stmt->execute([$taskId, $reviewerStaffId]); $task = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$task) throw new PlatformApiException(404, 'lesson_review_task_not_found', '审核任务不存在或无权查看');
        foreach (['content_json', 'source_snapshot_json', 'changed_fields_json'] as $field) $task[$field] = $this->decode($task[$field] ?? null);
        return ['task' => $task, 'source_files' => $this->rows('SELECT id, original_name, mime_type, extension, byte_size, sha256, status, uploaded_by, created_at FROM lesson_source_files WHERE submission_id = ? ORDER BY created_at DESC, id DESC', [(int) $task['submission_id']]), 'suggestions' => $this->rows('SELECT id, version_id, suggestion_type, priority, field_path, message, reason, source_type, knowledge_item_id, knowledge_version_id, decision, decided_by, decided_at, created_at FROM lesson_suggestions WHERE submission_id = ? AND version_id = ? ORDER BY id ASC', [(int) $task['submission_id'], (int) $task['version_id']]), 'versions' => array_map(function (array $version): array { foreach (['content_json', 'source_snapshot_json', 'changed_fields_json'] as $field) $version[$field] = $this->decode($version[$field] ?? null); return $version; }, $this->rows('SELECT id, version_no, content_json, source_snapshot_json, changed_fields_json, version_type, is_submitted, is_immutable, created_by, created_at FROM lesson_versions WHERE submission_id = ? ORDER BY version_no DESC', [(int) $task['submission_id']])), 'review_history' => $this->rows('SELECT review.id, review.version_id, version.version_no, review.reviewer_staff_id, review.reviewer_role, review.stage, review.status, review.decision, review.comments, review.decided_at, review.created_at, staff.name AS reviewer_name FROM lesson_review_tasks review JOIN lesson_versions version ON version.id = review.version_id AND version.submission_id = review.submission_id LEFT JOIN staffs staff ON staff.id = review.reviewer_staff_id WHERE review.submission_id = ? ORDER BY review.created_at DESC, review.id DESC', [(int) $task['submission_id']]), 'exports' => $this->rows('SELECT id, version_id, format, storage_key, status, error_message, created_by, created_at, completed_at FROM lesson_exports WHERE submission_id = ? AND version_id = ? ORDER BY created_at DESC, id DESC', [(int) $task['submission_id'], (int) $task['version_id']])];
    }

    private function rows(string $sql, array $params): array { $stmt = $this->pdo->prepare($sql); $stmt->execute($params); return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []; }
    private function decode(mixed $value): mixed { if (!is_string($value) || trim($value) === '') return []; $decoded = json_decode($value, true); return is_array($decoded) ? $decoded : []; }
}
