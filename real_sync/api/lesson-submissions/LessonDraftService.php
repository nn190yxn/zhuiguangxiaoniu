<?php

declare(strict_types=1);

final class LessonDraftService
{
    private const EDITABLE_STATUSES = ['draft', 'editable', 'returned'];

    public function __construct(private PDO $pdo)
    {
    }

    public function detail(int $submissionId, int $actorStaffId): array
    {
        $submission = $this->submission($submissionId, $actorStaffId);
        $versions = $this->rows('SELECT id, version_no, content_json, source_snapshot_json, changed_fields_json, version_type, is_submitted, is_immutable, created_by, created_at FROM lesson_versions WHERE submission_id = ? ORDER BY version_no DESC', [$submissionId]);
        $current = null;
        foreach ($versions as $version) {
            if ((int) $version['id'] === (int) ($submission['current_version_id'] ?? 0)) {
                $current = $this->version($version);
                break;
            }
        }
        return [
            'submission' => $submission,
            'current_version' => $current,
            'versions' => array_map([$this, 'version'], $versions),
            'source_files' => $this->rows('SELECT id, original_name, mime_type, extension, byte_size, sha256, status, uploaded_by, created_at FROM lesson_source_files WHERE submission_id = ? ORDER BY created_at DESC, id DESC', [$submissionId]),
            'parse_runs' => $this->rows('SELECT id, source_file_id, parser_version, status, location_map_json, error_code, error_message, started_at, completed_at, created_at FROM lesson_parse_runs WHERE submission_id = ? ORDER BY created_at DESC, id DESC', [$submissionId]),
            'suggestions' => $this->rows(
                'SELECT s.id, s.version_id, s.suggestion_type, s.priority, s.field_path, s.message, s.reason, s.source_type, '
                . 's.knowledge_item_id, s.knowledge_version_id, s.decision, s.decided_by, s.decided_at, s.created_at, '
                . 'k.item_code AS knowledge_item_code, COALESCE(kv.title, k.title) AS knowledge_item_title '
                . 'FROM lesson_suggestions s LEFT JOIN knowledge_items k ON k.id = s.knowledge_item_id '
                . 'LEFT JOIN knowledge_item_versions kv ON kv.knowledge_item_id = s.knowledge_item_id AND kv.version_id = s.knowledge_version_id '
                . 'WHERE s.submission_id = ? ORDER BY s.created_at DESC, s.id DESC',
                [$submissionId]
            ),
        ];
    }

    public function saveDraft(int $submissionId, array $content, int $actorStaffId, int $expectedStatusVersion, ?string $changeReason = null): array
    {
        if ($expectedStatusVersion < 1) throw new InvalidArgumentException('状态版本无效');
        $submission = $this->submission($submissionId, $actorStaffId);
        if (!in_array((string) $submission['status'], self::EDITABLE_STATUSES, true)) {
            throw new PlatformApiException(409, 'lesson_submission_locked', '当前教案版本已锁定，无法保存草稿');
        }
        if ((int) $submission['status_version'] !== $expectedStatusVersion) {
            throw new PlatformApiException(409, 'lesson_submission_conflict', '教案状态已变化，请重新读取后再保存', ['status_version' => (int) $submission['status_version'], 'current_version_id' => (int) ($submission['current_version_id'] ?? 0)]);
        }
        $content = $this->normalizeContent($content, $submission);
        $previous = $this->currentContent((int) ($submission['current_version_id'] ?? 0), $submissionId);
        $changedFields = $this->changedFields($previous, $content);
        if ($changedFields === []) throw new InvalidArgumentException('教案内容没有变化');

        $json = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $reason = trim((string) $changeReason);
        $this->pdo->beginTransaction();
        try {
            $next = $this->pdo->prepare('SELECT COALESCE(MAX(version_no), 0) + 1 FROM lesson_versions WHERE submission_id = ?');
            $next->execute([$submissionId]);
            $versionNo = max(1, (int) $next->fetchColumn());
            $insert = $this->pdo->prepare('INSERT INTO lesson_versions (submission_id, version_no, content_json, source_snapshot_json, changed_fields_json, version_type, created_by) VALUES (?, ?, ?, ?, ?, \'draft\', ?)');
            $insert->execute([$submissionId, $versionNo, $json, json_encode(['previous_version_id' => (int) ($submission['current_version_id'] ?? 0), 'change_reason' => $reason], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), json_encode($changedFields, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $actorStaffId]);
            $versionId = (int) $this->pdo->lastInsertId();
            $update = $this->pdo->prepare('UPDATE lesson_submissions SET current_version_id = ?, status_version = status_version + 1 WHERE id = ? AND status_version = ? AND status IN (\'draft\', \'editable\', \'returned\')');
            $update->execute([$versionId, $submissionId, $expectedStatusVersion]);
            if ($update->rowCount() !== 1) throw new PlatformApiException(409, 'lesson_submission_conflict', '教案状态已变化，请重新读取后再保存');
            $this->audit($submissionId, $versionId, $actorStaffId, 'draft_save', (string) $submission['status'], (string) $submission['status'], ['changed_fields' => $changedFields, 'change_reason' => $reason]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        return ['submission_id' => $submissionId, 'version_id' => $versionId, 'version_no' => $versionNo, 'status' => (string) $submission['status'], 'status_version' => $expectedStatusVersion + 1, 'changed_fields' => $changedFields];
    }

    private function submission(int $id, int $actorStaffId): array
    {
        if ($id <= 0 || $actorStaffId <= 0) throw new InvalidArgumentException('教案或员工身份无效');
        $stmt = $this->pdo->prepare('SELECT * FROM lesson_submissions WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new PlatformApiException(404, 'lesson_submission_not_found', '教案不存在');
        if ((int) ($row['author_staff_id'] ?? 0) !== $actorStaffId) throw new PlatformApiException(403, 'lesson_submission_forbidden', '只能查看和编辑自己创建的教案');
        return $row;
    }

    private function currentContent(int $versionId, int $submissionId): array
    {
        $stmt = $this->pdo->prepare('SELECT content_json FROM lesson_versions WHERE id = ? AND submission_id = ? LIMIT 1');
        $stmt->execute([$versionId, $submissionId]);
        $json = $stmt->fetchColumn();
        if (!is_string($json) || $json === '') return [];
        $content = json_decode($json, true);
        return is_array($content) ? $content : [];
    }

    private function normalizeContent(array $content, array $submission): array
    {
        $base = LessonSubmissionService::emptyLessonContent($submission);
        foreach (['metadata', 'objectives', 'safety', 'reflection'] as $section) {
            if (isset($content[$section]) && !is_array($content[$section])) throw new InvalidArgumentException('教案字段结构无效：' . $section);
        }
        return array_replace_recursive($base, $content);
    }

    private function changedFields(array $before, array $after, string $prefix = ''): array
    {
        $fields = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($before[$key] ?? null) && is_array($after[$key] ?? null)) $fields = [...$fields, ...$this->changedFields($before[$key], $after[$key], $path)];
            elseif (($before[$key] ?? null) !== ($after[$key] ?? null)) $fields[] = $path;
        }
        return $fields;
    }

    private function rows(string $sql, array $params): array
    {
        $stmt = $this->pdo->prepare($sql); $stmt->execute($params); return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function version(array $version): array
    {
        foreach (['content_json', 'source_snapshot_json', 'changed_fields_json'] as $field) {
            if (isset($version[$field]) && is_string($version[$field]) && $version[$field] !== '') $version[$field] = json_decode($version[$field], true) ?? [];
        }
        return $version;
    }

    private function audit(int $submissionId, int $versionId, int $staffId, string $action, string $from, string $to, array $metadata): void
    {
        $this->pdo->prepare('INSERT INTO lesson_audit_logs (submission_id, version_id, actor_staff_id, action, from_status, to_status, metadata_json) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$submissionId, $versionId, $staffId, $action, $from, $to, json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    }
}
