<?php
declare(strict_types=1);

final class LessonArchiveService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function archive(int $submissionId, int $actorStaffId, int $expectedStatusVersion, ?string $reason = null): array
    {
        if ($submissionId <= 0 || $actorStaffId <= 0 || $expectedStatusVersion < 1) {
            throw new InvalidArgumentException('教案、员工身份或状态版本无效');
        }
        $reason = trim((string) $reason);

        $this->pdo->beginTransaction();
        try {
            $submission = $this->submission($submissionId);
            if ((int) $submission['status_version'] !== $expectedStatusVersion) {
                throw new PlatformApiException(409, 'lesson_submission_conflict', '教案状态已变化，请刷新后重试', ['status_version' => (int) $submission['status_version']]);
            }
            if ((string) $submission['status'] !== 'approved' || (string) $submission['library_status'] !== 'published') {
                throw new PlatformApiException(409, 'lesson_archive_state_invalid', '当前教案状态不允许归档');
            }

            $approvedVersionId = (int) ($submission['approved_version_id'] ?? 0);
            if ($approvedVersionId <= 0 || !$this->approvedVersionExists($submissionId, $approvedVersionId)) {
                throw new PlatformApiException(409, 'lesson_archive_version_invalid', '当前教案缺少有效的批准版本');
            }

            $update = $this->pdo->prepare("UPDATE lesson_submissions SET status = 'archived', library_status = 'archived', status_version = status_version + 1 WHERE id = ? AND approved_version_id = ? AND status_version = ? AND status = 'approved' AND library_status = 'published'");
            $update->execute([$submissionId, $approvedVersionId, $expectedStatusVersion]);
            if ($update->rowCount() !== 1) {
                throw new PlatformApiException(409, 'lesson_submission_conflict', '教案状态已变化，请刷新后重试');
            }

            $metadata = [
                'reason' => $reason !== '' ? $reason : null,
                'previous_library_status' => (string) $submission['library_status'],
                'library_published_at' => $submission['library_published_at'],
                'library_published_by_staff_id' => isset($submission['library_published_by_staff_id']) ? (int) $submission['library_published_by_staff_id'] : null,
            ];
            $this->audit($submissionId, $approvedVersionId, $actorStaffId, $metadata);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }

        return [
            'submission_id' => $submissionId,
            'approved_version_id' => $approvedVersionId,
            'status' => 'archived',
            'library_status' => 'archived',
            'status_version' => $expectedStatusVersion + 1,
        ];
    }

    private function submission(int $submissionId): array
    {
        $sql = 'SELECT id, status, status_version, approved_version_id, library_status, library_published_at, library_published_by_staff_id FROM lesson_submissions WHERE id = ?';
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute([$submissionId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new PlatformApiException(404, 'lesson_submission_not_found', '教案不存在');
        }
        return $row;
    }

    private function approvedVersionExists(int $submissionId, int $approvedVersionId): bool
    {
        $statement = $this->pdo->prepare('SELECT id FROM lesson_versions WHERE id = ? AND submission_id = ? AND is_submitted = 1 AND is_immutable = 1 LIMIT 1');
        $statement->execute([$approvedVersionId, $submissionId]);
        return $statement->fetchColumn() !== false;
    }

    private function audit(int $submissionId, int $versionId, int $actorStaffId, array $metadata): void
    {
        $statement = $this->pdo->prepare('INSERT INTO lesson_audit_logs (submission_id, version_id, actor_staff_id, action, from_status, to_status, metadata_json) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([
            $submissionId,
            $versionId,
            $actorStaffId,
            'lesson_archived',
            'approved',
            'archived',
            json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
    }
}
