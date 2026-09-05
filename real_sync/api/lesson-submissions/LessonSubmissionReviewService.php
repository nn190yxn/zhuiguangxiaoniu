<?php
declare(strict_types=1);

final class LessonSubmissionReviewService
{
    private const EDITABLE_STATUSES = ['draft', 'editable', 'returned'];
    private const MANAGER_ROLES = ['manager', 'store_manager', 'shop_manager', '店长'];

    public function __construct(private PDO $pdo)
    {
    }

    public function submit(int $submissionId, int $actorStaffId, int $expectedStatusVersion): array
    {
        if ($submissionId <= 0 || $actorStaffId <= 0 || $expectedStatusVersion < 1) {
            throw new InvalidArgumentException('教案、员工身份或状态版本无效');
        }
        $submission = $this->submission($submissionId, $actorStaffId);
        if (!in_array((string) $submission['status'], self::EDITABLE_STATUSES, true)) {
            throw new PlatformApiException(409, 'lesson_submission_locked', '当前教案状态不允许提交审核');
        }
        if ((int) $submission['status_version'] !== $expectedStatusVersion) {
            throw new PlatformApiException(409, 'lesson_submission_conflict', '教案状态已变化，请刷新后重试', ['status_version' => (int) $submission['status_version']]);
        }

        $versionId = (int) ($submission['current_version_id'] ?? 0);
        $version = $this->version($submissionId, $versionId);
        $content = json_decode((string) $version['content_json'], true);
        if (!is_array($content)) throw new PlatformApiException(409, 'lesson_version_unavailable', '当前教案结构化版本无效');
        $validation = (new LessonAceRuleChecker())->check($content);
        if (!$validation['valid']) {
            throw new PlatformApiException(422, 'lesson_validation_failed', '请先补齐教案必填项', ['version_id' => $versionId, 'version_no' => (int) $version['version_no'], 'findings' => $validation['findings']]);
        }
        $pending = $this->pendingSuggestions($submissionId, $versionId);
        if ($pending !== []) {
            throw new PlatformApiException(422, 'lesson_suggestions_pending', '请先处理当前版本的优化建议', ['version_id' => $versionId, 'suggestions' => $pending]);
        }

        $storeId = (int) ($submission['store_id'] ?? 0);
        if ($storeId <= 0) throw new PlatformApiException(409, 'lesson_store_manager_unavailable', '当前教案没有关联门店，暂时无法创建店长审核任务');
        $manager = $this->manager($storeId);
        if (!$manager) throw new PlatformApiException(409, 'lesson_store_manager_unavailable', '当前门店没有可用的店长审核人');

        $this->pdo->beginTransaction();
        try {
            $freeze = $this->pdo->prepare('UPDATE lesson_versions SET is_submitted = 1, is_immutable = 1 WHERE id = ? AND submission_id = ? AND is_submitted = 0 AND is_immutable = 0');
            $freeze->execute([$versionId, $submissionId]);
            if ($freeze->rowCount() !== 1) throw new PlatformApiException(409, 'lesson_version_locked', '当前版本已提交或已锁定');
            $update = $this->pdo->prepare("UPDATE lesson_submissions SET status = 'store_review', status_version = status_version + 1 WHERE id = ? AND current_version_id = ? AND status_version = ? AND status IN ('draft', 'editable', 'returned')");
            $update->execute([$submissionId, $versionId, $expectedStatusVersion]);
            if ($update->rowCount() !== 1) throw new PlatformApiException(409, 'lesson_submission_conflict', '教案状态已变化，请刷新后重试');
            $task = $this->pdo->prepare("INSERT INTO lesson_review_tasks (submission_id, version_id, reviewer_staff_id, reviewer_role, stage, status) VALUES (?, ?, ?, 'manager', 'store_review', 'pending')");
            $task->execute([$submissionId, $versionId, (int) $manager['staff_id']]);
            $taskId = (int) $this->pdo->lastInsertId();
            $this->audit($submissionId, $versionId, $actorStaffId, 'submit_for_store_review', (string) $submission['status'], 'store_review', ['review_task_id' => $taskId, 'reviewer_staff_id' => (int) $manager['staff_id']]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        return ['submission_id' => $submissionId, 'version_id' => $versionId, 'version_no' => (int) $version['version_no'], 'status' => 'store_review', 'status_version' => $expectedStatusVersion + 1, 'review_task_id' => $taskId, 'reviewer_staff_id' => (int) $manager['staff_id'], 'reviewer_name' => (string) $manager['name']];
    }

    private function submission(int $id, int $actorStaffId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM lesson_submissions WHERE id = ? LIMIT 1'); $stmt->execute([$id]); $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new PlatformApiException(404, 'lesson_submission_not_found', '教案不存在');
        if ((int) ($row['author_staff_id'] ?? 0) !== $actorStaffId) throw new PlatformApiException(403, 'lesson_submission_forbidden', '只能提交自己创建的教案');
        return $row;
    }
    private function version(int $submissionId, int $versionId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, version_no, content_json, is_submitted, is_immutable FROM lesson_versions WHERE id = ? AND submission_id = ? LIMIT 1'); $stmt->execute([$versionId, $submissionId]); $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new PlatformApiException(409, 'lesson_version_unavailable', '当前教案没有可提交的结构化版本'); return $row;
    }
    private function pendingSuggestions(int $submissionId, int $versionId): array
    {
        $stmt = $this->pdo->prepare("SELECT id, suggestion_type, priority, field_path, message, reason, source_type, knowledge_item_id, knowledge_version_id FROM lesson_suggestions WHERE submission_id = ? AND version_id = ? AND decision = 'pending' ORDER BY priority DESC, id ASC"); $stmt->execute([$submissionId, $versionId]); return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    private function manager(int $storeId): ?array
    {
        $roles = implode(',', array_fill(0, count(self::MANAGER_ROLES), '?')); $stmt = $this->pdo->prepare("SELECT id AS staff_id, name, user_id, store_id, role FROM staffs WHERE status = 1 AND user_id IS NOT NULL AND user_id > 0 AND store_id = ? AND role IN ($roles) ORDER BY id ASC LIMIT 1"); $stmt->execute([$storeId, ...self::MANAGER_ROLES]); $row = $stmt->fetch(PDO::FETCH_ASSOC); return $row ?: null;
    }
    private function audit(int $submissionId, int $versionId, int $staffId, string $action, string $from, string $to, array $metadata): void
    {
        $this->pdo->prepare('INSERT INTO lesson_audit_logs (submission_id, version_id, actor_staff_id, action, from_status, to_status, metadata_json) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$submissionId, $versionId, $staffId, $action, $from, $to, json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
    }
}
