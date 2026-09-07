<?php
declare(strict_types=1);
require_once __DIR__ . '/LessonKnowledgeMatcher.php';

final class LessonSuggestionService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function decide(int $submissionId, int $suggestionId, string $decision, int $actorStaffId, ?array $content, int $expectedStatusVersion): array
    {
        if (!in_array($decision, ['accepted', 'ignored'], true)) throw new InvalidArgumentException('建议决定必须为 accepted 或 ignored');
        if ($submissionId <= 0 || $suggestionId <= 0 || $actorStaffId <= 0 || $expectedStatusVersion < 1) throw new InvalidArgumentException('建议决定参数无效');
        $submission = $this->submission($submissionId, $actorStaffId);
        if (!in_array((string) $submission['status'], ['draft', 'editable', 'returned'], true)) throw new PlatformApiException(409, 'lesson_submission_locked', '当前教案状态不允许处理建议');
        if ((int) $submission['status_version'] !== $expectedStatusVersion) throw new PlatformApiException(409, 'lesson_submission_conflict', '教案状态已变化，请刷新后重试');

        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT status_version, current_version_id, status FROM lesson_submissions WHERE id = ?' . ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
            $lock->execute([$submissionId]);
            $locked = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$locked || (int) $locked['status_version'] !== $expectedStatusVersion || (int) $locked['current_version_id'] !== (int) $submission['current_version_id'] || !in_array($locked['status'], ['draft', 'editable', 'returned'], true)) throw new PlatformApiException(409, 'lesson_submission_conflict', '教案状态已变化，请刷新');
            $query = $this->pdo->prepare('SELECT * FROM lesson_suggestions WHERE id = ? AND submission_id = ?' . ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
            $query->execute([$suggestionId, $submissionId]);
            $suggestion = $query->fetch(PDO::FETCH_ASSOC);
            if (!$suggestion) throw new PlatformApiException(404, 'lesson_suggestion_not_found', '建议不存在');
            if ((int) $suggestion['version_id'] !== (int) ($submission['current_version_id'] ?? 0)) throw new PlatformApiException(409, 'lesson_suggestion_stale', '建议属于旧版本，请刷新当前教案');
            if ((string) $suggestion['decision'] !== 'pending') throw new PlatformApiException(409, 'lesson_suggestion_decided', '该建议已经处理过');

            $versionId = (int) ($submission['current_version_id'] ?? 0);
            $versionNo = $this->nextVersionNo($submissionId);
            $changedFields = [];
            if ($decision === 'accepted') {
                if (!is_array($content)) throw new InvalidArgumentException('采纳建议必须提交当前教案内容');
                $versionQuery = $this->pdo->prepare('SELECT content_json FROM lesson_versions WHERE id = ? AND submission_id = ?');
                $versionQuery->execute([$versionId, $submissionId]);
                $previousContent = json_decode((string) $versionQuery->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
                $path = (string) ($suggestion['field_path'] ?? '');
                if (!preg_match('/^(safety\.(physical|psychological)|objectives\.(athletic|cognitive|engagement)|reflection\.(athletic|cognitive|engagement)|phases\.\d+\.(content|activity)|equipment|progressions|assistant_responsibilities)$/', $path)) throw new InvalidArgumentException('建议字段无法自动采纳');
                $before = &$previousContent; $after = &$content;
                foreach (explode('.', $path) as $key) {
                    if (!is_array($before) || !is_array($after) || !array_key_exists($key, $after)) throw new InvalidArgumentException('建议字段结构无效');
                    $before = &$before[$key]; $after = &$after[$key];
                }
                if ((!is_string($after) && !is_array($after)) || (is_array($after) && array_filter($after, static fn($value): bool => !is_string($value) && !(is_array($before) && in_array($value, $before, true))))) throw new InvalidArgumentException('建议字段内容无效');
                if ($before === $after) throw new InvalidArgumentException('建议内容没有变化');
                $before = $after;
                unset($before, $after);
                if ($previousContent !== $content) throw new InvalidArgumentException('采纳建议只能修改目标字段，请先保存其他修改');
                $json = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $changedFields = [(string) ($suggestion['field_path'] ?? '')];
                $insert = $this->pdo->prepare("INSERT INTO lesson_versions (submission_id, version_no, content_json, source_snapshot_json, changed_fields_json, version_type, created_by) VALUES (?, ?, ?, ?, ?, 'draft', ?)");
                $insert->execute([$submissionId, $versionNo, $json, json_encode(['previous_version_id' => $versionId, 'suggestion_id' => $suggestionId, 'decision' => 'accepted'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), json_encode($changedFields, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $actorStaffId]);
                $newVersionId = (int) $this->pdo->lastInsertId();
                $update = $this->pdo->prepare("UPDATE lesson_submissions SET current_version_id = ?, status_version = status_version + 1 WHERE id = ? AND current_version_id = ? AND status_version = ? AND status IN ('draft', 'editable', 'returned')");
                $update->execute([$newVersionId, $submissionId, $versionId, $expectedStatusVersion]);
                if ($update->rowCount() !== 1) throw new PlatformApiException(409, 'lesson_submission_conflict', '教案状态已变化，请刷新后重试');
                $versionId = $newVersionId;
            }
            $decisionUpdate = $this->pdo->prepare("UPDATE lesson_suggestions SET decision = ?, decided_by = ?, decided_at = NOW() WHERE id = ? AND decision = 'pending'");
            $decisionUpdate->execute([$decision, $actorStaffId, $suggestionId]);
            if ($decisionUpdate->rowCount() !== 1) throw new PlatformApiException(409, 'lesson_suggestion_conflict', '建议状态已变化，请刷新后重试');
            if ($decision === 'accepted') (new LessonKnowledgeMatcher($this->pdo))->optimize($submissionId, $actorStaffId, null, $versionId);
            $this->pdo->prepare('INSERT INTO lesson_audit_logs (submission_id, version_id, actor_staff_id, action, from_status, to_status, metadata_json) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$submissionId, $versionId, $actorStaffId, 'suggestion_' . $decision, (string) $submission['status'], (string) $submission['status'], json_encode(['suggestion_id' => $suggestionId, 'previous_version_id' => (int) ($suggestion['version_id'] ?? 0)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        return ['submission_id' => $submissionId, 'suggestion_id' => $suggestionId, 'decision' => $decision, 'version_id' => $versionId, 'version_no' => $versionNo, 'status_version' => $decision === 'accepted' ? $expectedStatusVersion + 1 : $expectedStatusVersion];
    }

    private function submission(int $submissionId, int $actorStaffId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM lesson_submissions WHERE id = ? LIMIT 1');
        $stmt->execute([$submissionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new PlatformApiException(404, 'lesson_submission_not_found', '教案不存在');
        if ((int) ($row['author_staff_id'] ?? 0) !== $actorStaffId) throw new PlatformApiException(403, 'lesson_submission_forbidden', '只能处理自己创建的教案');
        return $row;
    }

    private function nextVersionNo(int $submissionId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(version_no), 0) + 1 FROM lesson_versions WHERE submission_id = ?');
        $stmt->execute([$submissionId]);
        return max(1, (int) $stmt->fetchColumn());
    }
}
