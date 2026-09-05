<?php
declare(strict_types=1);

final class LessonSubmissionService
{
    private const MAX_FILE_BYTES = 50 * 1024 * 1024;

    private const MIME_TYPES = [
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'xls' => ['application/vnd.ms-excel', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'doc' => ['application/msword', 'application/vnd.ms-word', 'application/octet-stream'],
    ];

    public function __construct(private PDO $pdo, private PlatformPrivateFileStorage $storage)
    {
    }

    public static function validateMetadata(array $input): array
    {
        $fields = [
            'store_name' => '门店名称',
            'author_name' => '作者姓名',
            'course_line' => '课程线',
            'class_level' => '班级或级别',
            'lesson_date' => '上课日期',
            'title' => '教案标题',
        ];
        $metadata = [];
        foreach ($fields as $field => $label) {
            $value = trim((string) ($input[$field] ?? ''));
            if ($value === '') {
                throw new InvalidArgumentException($label . '不能为空');
            }
            if (mb_strlen($value, 'UTF-8') > ($field === 'title' ? 255 : 128)) {
                throw new InvalidArgumentException($label . '长度超出限制');
            }
            $metadata[$field] = $value;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $metadata['lesson_date']);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (!$date || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
            throw new InvalidArgumentException('上课日期格式必须为 YYYY-MM-DD');
        }

        $metadata['store_id'] = max(0, (int) ($input['store_id'] ?? 0)) ?: null;
        return $metadata;
    }

    public function create(array $input, int $actorStaffId): array
    {
        if ($this->pdo->inTransaction()) {
            return $this->createWithinTransaction($input, $actorStaffId);
        }

        $this->pdo->beginTransaction();
        try {
            $result = $this->createWithinTransaction($input, $actorStaffId);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function createWithinTransaction(array $input, int $actorStaffId): array
    {
        if ($actorStaffId <= 0) {
            throw new InvalidArgumentException('当前账号缺少员工身份');
        }
        $metadata = self::validateMetadata($input);
        $content = json_encode(self::emptyLessonContent($metadata), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $insert = $this->pdo->prepare(
            'INSERT INTO lesson_submissions '
            . '(store_id, store_name, author_staff_id, author_name, course_line, class_level, lesson_date, title, status, created_by) '
            . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)"
        );
        $insert->execute([
            $metadata['store_id'],
            $metadata['store_name'],
            $actorStaffId,
            $metadata['author_name'],
            $metadata['course_line'],
            $metadata['class_level'],
            $metadata['lesson_date'],
            $metadata['title'],
            $actorStaffId,
        ]);
        $submissionId = (int) $this->pdo->lastInsertId();

        $version = $this->pdo->prepare(
            "INSERT INTO lesson_versions (submission_id, version_no, content_json, version_type, created_by) VALUES (?, 1, ?, 'draft', ?)"
        );
        $version->execute([$submissionId, $content, $actorStaffId]);
        $versionId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('UPDATE lesson_submissions SET current_version_id = ? WHERE id = ?')
            ->execute([$versionId, $submissionId]);
        $this->audit($submissionId, $versionId, $actorStaffId, 'create', null, 'draft');

        return ['id' => $submissionId, 'current_version_id' => $versionId, 'status' => 'draft', 'metadata' => $metadata];
    }

    public function upload(int $submissionId, array $file, int $actorStaffId): array
    {
        if ($submissionId <= 0 || $actorStaffId <= 0) {
            throw new InvalidArgumentException('教案或员工身份无效');
        }
        $submission = $this->submission($submissionId);
        if ((int) ($submission['author_staff_id'] ?? 0) !== $actorStaffId) {
            throw new PlatformApiException(403, 'lesson_submission_forbidden', '只能上传自己创建的教案文件');
        }
        if (!in_array((string) $submission['status'], ['draft', 'parse_failed', 'returned'], true)) {
            throw new PlatformApiException(409, 'lesson_submission_locked', '当前教案状态不允许替换原始文件');
        }

        $originalName = trim((string) ($file['name'] ?? ''));
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!isset(self::MIME_TYPES[$extension])) {
            throw new InvalidArgumentException('仅支持 .xlsx、.xls、.docx、.doc 文件');
        }
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('文件上传失败，错误码：' . $error);
        }
        $temporaryPath = (string) ($file['tmp_name'] ?? '');
        if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
            throw new InvalidArgumentException('上传临时文件无效');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size < 1 || $size > self::MAX_FILE_BYTES) {
            throw new PlatformApiException(413, 'lesson_file_too_large', '教案文件必须大于 0 且不超过 50MB');
        }

        $declaredMime = strtolower(trim((string) ($file['type'] ?? '')));
        $stored = $this->storage->storeFile([
            'source_path' => $temporaryPath,
            'original_name' => $originalName,
            'declared_mime_type' => $declaredMime,
            'allowed_mime_types' => self::MIME_TYPES[$extension],
            'max_bytes' => self::MAX_FILE_BYTES,
            'namespace' => 'lesson-submissions/submission-' . $submissionId,
        ]);

        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO lesson_source_files '
                . '(submission_id, original_name, storage_key, mime_type, extension, byte_size, sha256, uploaded_by) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $submissionId,
                $stored['original_name'],
                $stored['storage_key'],
                $stored['mime_type'],
                $extension,
                $stored['byte_size'],
                $stored['sha256'],
                $actorStaffId,
            ]);
            $fileId = (int) $this->pdo->lastInsertId();
            $this->audit($submissionId, (int) ($submission['current_version_id'] ?? 0), $actorStaffId, 'upload', (string) $submission['status'], (string) $submission['status'], ['source_file_id' => $fileId]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }

        return ['id' => $fileId, 'submission_id' => $submissionId, 'original_name' => $stored['original_name'], 'mime_type' => $stored['mime_type'], 'byte_size' => $stored['byte_size'], 'sha256' => $stored['sha256'], 'storage_key' => $stored['storage_key']];
    }

    public function parseUploadedFile(int $submissionId, int $sourceFileId, int $actorStaffId): array
    {
        if ($submissionId <= 0 || $sourceFileId <= 0 || $actorStaffId <= 0) {
            throw new InvalidArgumentException('教案、原始文件或员工身份无效');
        }
        $submission = $this->submission($submissionId);
        if ((int) ($submission['author_staff_id'] ?? 0) !== $actorStaffId) {
            throw new PlatformApiException(403, 'lesson_submission_forbidden', '只能解析自己创建的教案');
        }
        if (!in_array((string) $submission['status'], ['draft', 'parse_failed', 'returned'], true)) {
            throw new PlatformApiException(409, 'lesson_submission_locked', '当前教案状态不允许解析原始文件');
        }

        $sourceStatement = $this->pdo->prepare('SELECT id, original_name, storage_key, extension FROM lesson_source_files WHERE id = ? AND submission_id = ? LIMIT 1');
        $sourceStatement->execute([$sourceFileId, $submissionId]);
        $source = $sourceStatement->fetch(PDO::FETCH_ASSOC);
        if (!$source) throw new InvalidArgumentException('原始教案文件不存在');

        $extension = strtolower((string) $source['extension']);
        $parserVersion = in_array($extension, ['xlsx', 'xls'], true) ? 'lesson-xlsx-v1' : 'lesson-docx-v1';
        try {
            $path = $this->storage->resolveForRead((string) $source['storage_key']);
            $parsed = in_array($extension, ['xlsx', 'xls'], true)
                ? (new LessonWorkbookParser())->parse($path, (string) $source['original_name'])
                : (new LessonWordParser())->parse($path, (string) $source['original_name']);
        } catch (Throwable $error) {
            return $this->recordParseFailure($submissionId, $sourceFileId, $parserVersion, $error, $actorStaffId);
        }

        $parsedContent = is_array($parsed['content'] ?? null) ? $parsed['content'] : [];
        $fieldMapping = is_array($parsedContent['mapping'] ?? null) ? $parsedContent['mapping'] : [];
        unset($parsedContent['mapping']);
        $content = array_replace_recursive(self::emptyLessonContent($this->submissionMetadata($submission)), $parsedContent);
        $content['metadata'] = array_replace(
            is_array($content['metadata'] ?? null) ? $content['metadata'] : [],
            $this->submissionMetadata($submission)
        );
        foreach (['equipment', 'phases', 'progressions'] as $field) {
            if (!is_array($content[$field] ?? null)) {
                $content[$field] = array_values(array_filter(array_map('trim', preg_split('/[\r\n,，、;；]+/u', (string) ($content[$field] ?? '')) ?: [])));
            }
        }
        $locationMap = $parsed;
        unset($locationMap['content']);
        $locationMap['mapping'] = $fieldMapping;

        $this->pdo->beginTransaction();
        try {
            $run = $this->pdo->prepare("INSERT INTO lesson_parse_runs (submission_id, source_file_id, parser_version, status, location_map_json, started_at, completed_at) VALUES (?, ?, ?, 'completed', ?, NOW(), NOW())");
            $run->execute([$submissionId, $sourceFileId, $parserVersion, json_encode($locationMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
            $runId = (int) $this->pdo->lastInsertId();
            $versionNo = $this->nextVersionNo($submissionId);
            $version = $this->pdo->prepare("INSERT INTO lesson_versions (submission_id, version_no, content_json, source_snapshot_json, changed_fields_json, version_type, created_by) VALUES (?, ?, ?, ?, ?, 'parsed', ?)");
            $version->execute([
                $submissionId,
                $versionNo,
                json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                json_encode(['source_file_id' => $sourceFileId, 'parse_run_id' => $runId, 'parser_version' => $parserVersion], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                json_encode(array_keys($parsedContent), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $actorStaffId,
            ]);
            $versionId = (int) $this->pdo->lastInsertId();
            $update = $this->pdo->prepare("UPDATE lesson_submissions SET current_version_id = ?, status = 'editable', status_version = status_version + 1 WHERE id = ? AND current_version_id = ? AND status_version = ? AND status IN ('draft', 'parse_failed', 'returned')");
            $update->execute([$versionId, $submissionId, (int) ($submission['current_version_id'] ?? 0), (int) $submission['status_version']]);
            if ($update->rowCount() !== 1) throw new PlatformApiException(409, 'lesson_submission_conflict', '教案状态已变化，请重新读取后再解析');
            $this->audit($submissionId, $versionId, $actorStaffId, 'parse_completed', (string) $submission['status'], 'editable', ['parse_run_id' => $runId, 'source_file_id' => $sourceFileId]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }

        return ['submission_id' => $submissionId, 'source_file_id' => $sourceFileId, 'parse_run_id' => $runId, 'current_version_id' => $versionId, 'version_no' => $versionNo, 'status' => 'editable', 'format' => (string) ($parsed['format'] ?? $extension)];
    }

    public function recordParseFailure(int $submissionId, int $sourceFileId, string $parserVersion, Throwable $error, int $actorStaffId): array
    {
        $submission = $this->submission($submissionId);
        if ((int) ($submission['author_staff_id'] ?? 0) !== $actorStaffId) {
            throw new PlatformApiException(403, 'lesson_submission_forbidden', '只能处理自己创建的教案');
        }
        $source = $this->pdo->prepare('SELECT id FROM lesson_source_files WHERE id = ? AND submission_id = ? LIMIT 1');
        $source->execute([$sourceFileId, $submissionId]);
        if (!$source->fetchColumn()) throw new InvalidArgumentException('原始教案文件不存在');
        $message = trim($error->getMessage());
        if ($message === '') $message = '教案文件无法自动解析';
        $content = json_encode(self::emptyLessonContent($submission), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $this->pdo->beginTransaction();
        try {
            $run = $this->pdo->prepare('INSERT INTO lesson_parse_runs (submission_id, source_file_id, parser_version, status, error_code, error_message, completed_at) VALUES (?, ?, ?, \'failed\', ?, ?, NOW())');
            $run->execute([$submissionId, $sourceFileId, trim($parserVersion) ?: 'lesson-parser-v1', 'parse_failed', mb_substr($message, 0, 2000, 'UTF-8')]);
            $runId = (int) $this->pdo->lastInsertId();
            $versionNo = $this->nextVersionNo($submissionId);
            $version = $this->pdo->prepare("INSERT INTO lesson_versions (submission_id, version_no, content_json, source_snapshot_json, version_type, created_by) VALUES (?, ?, ?, ?, 'manual_template', ?)");
            $version->execute([$submissionId, $versionNo, $content, json_encode(['source_file_id' => $sourceFileId, 'parse_run_id' => $runId], JSON_THROW_ON_ERROR), $actorStaffId]);
            $versionId = (int) $this->pdo->lastInsertId();
            $this->pdo->prepare("UPDATE lesson_submissions SET current_version_id = ?, status = 'parse_failed', status_version = status_version + 1 WHERE id = ?")
                ->execute([$versionId, $submissionId]);
            $this->audit($submissionId, $versionId, $actorStaffId, 'parse_failed', (string) $submission['status'], 'parse_failed', ['parse_run_id' => $runId, 'source_file_id' => $sourceFileId]);
            $this->pdo->commit();
        } catch (Throwable $failure) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $failure;
        }
        return ['submission_id' => $submissionId, 'parse_run_id' => $runId, 'current_version_id' => $versionId, 'status' => 'parse_failed', 'manual_entry_available' => true, 'error_message' => $message];
    }

    public function beginManualEntry(int $submissionId, int $actorStaffId): array
    {
        $submission = $this->submission($submissionId);
        if ((int) ($submission['author_staff_id'] ?? 0) !== $actorStaffId) {
            throw new PlatformApiException(403, 'lesson_submission_forbidden', '只能编辑自己创建的教案');
        }
        if ((string) $submission['status'] !== 'parse_failed') {
            throw new PlatformApiException(409, 'lesson_manual_entry_unavailable', '当前教案没有可恢复的解析失败任务');
        }
        $this->pdo->prepare("UPDATE lesson_submissions SET status = 'editable', status_version = status_version + 1 WHERE id = ? AND status = 'parse_failed'")
            ->execute([$submissionId]);
        $this->audit($submissionId, (int) ($submission['current_version_id'] ?? 0), $actorStaffId, 'manual_entry', 'parse_failed', 'editable');
        return ['submission_id' => $submissionId, 'current_version_id' => (int) ($submission['current_version_id'] ?? 0), 'status' => 'editable'];
    }

    private function submission(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM lesson_submissions WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new PlatformApiException(404, 'lesson_submission_not_found', '教案不存在');
        }
        return $row;
    }

    private function nextVersionNo(int $submissionId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(version_no), 0) + 1 FROM lesson_versions WHERE submission_id = ?');
        $stmt->execute([$submissionId]);
        return max(1, (int) $stmt->fetchColumn());
    }

    private function submissionMetadata(array $submission): array
    {
        $metadata = [];
        foreach (['store_name', 'author_name', 'course_line', 'class_level', 'lesson_date', 'title'] as $field) {
            $metadata[$field] = (string) ($submission[$field] ?? '');
        }
        $metadata['store_id'] = max(0, (int) ($submission['store_id'] ?? 0)) ?: null;
        return $metadata;
    }

    private function audit(int $submissionId, int $versionId, int $staffId, string $action, ?string $fromStatus, ?string $toStatus, array $metadata = []): void
    {
        $this->pdo->prepare(
            'INSERT INTO lesson_audit_logs (submission_id, version_id, actor_staff_id, action, from_status, to_status, metadata_json) VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$submissionId, $versionId ?: null, $staffId, $action, $fromStatus, $toStatus, $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : null]);
    }

    public static function emptyLessonContent(array $metadata): array
    {
        return [
            'metadata' => $metadata,
            'objectives' => ['athletic' => '', 'cognitive' => '', 'engagement' => ''],
            'learner_focus' => '',
            'safety' => ['physical' => '', 'psychological' => ''],
            'equipment' => [],
            'phases' => [],
            'progressions' => [],
            'assistant_responsibilities' => '',
            'reflection' => ['athletic' => '', 'cognitive' => '', 'engagement' => ''],
        ];
    }
}
