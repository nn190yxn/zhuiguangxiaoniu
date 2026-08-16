<?php

declare(strict_types=1);

require_once __DIR__ . '/ResumeDocumentService.php';
require_once dirname(__DIR__) . '/platform/RecruitmentPlatformFileAdapter.php';

final class ResumeUploadService
{
    private const MAX_FILE_BYTES = 20 * 1024 * 1024;
    private const MAX_BATCH_BYTES = 2 * 1024 * 1024 * 1024;
    private const MAX_BATCH_FILES = 500;
    private const ALLOWED_TYPES = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
    ];

    private PDO $pdo;
    private RecruitmentPermissionService $permissionService;
    private ResumeDocumentService $documentService;
    private RecruitmentPlatformFileAdapter $fileAdapter;

    public function __construct(PDO $pdo, RecruitmentPermissionService $permissionService, ?string $storageRoot = null)
    {
        $this->pdo = $pdo;
        $this->permissionService = $permissionService;
        $this->documentService = new ResumeDocumentService($pdo);
        $this->fileAdapter = new RecruitmentPlatformFileAdapter($pdo, $storageRoot);
    }

    public function listBatches(array $scope, array $filters = []): array
    {
        $this->ensureSchema();
        [$singleScopeWhere, $singleScopeParams] = $this->permissionService->requirementWhereClause($scope, 'requirement');
        [$mixedScopeWhere, $mixedScopeParams] = $this->permissionService->requirementWhereClause($scope, 'mixed_requirement');
        $where = ["((batch.intake_mode = 'single_requirement' AND {$singleScopeWhere}) OR (batch.intake_mode = 'mixed_requirements' AND EXISTS (SELECT 1 FROM recruitment_resume_batch_requirements mixed_batch_requirement JOIN recruitment_requirements mixed_requirement ON mixed_requirement.id = mixed_batch_requirement.requirement_id WHERE mixed_batch_requirement.batch_id = batch.id AND {$mixedScopeWhere})))"];
        $params = array_merge($singleScopeParams, $mixedScopeParams);
        $requirementId = (int) ($filters['requirement_id'] ?? 0);
        if ($requirementId > 0) {
            $where[] = 'batch.requirement_id = ?';
            $params[] = $requirementId;
        }
        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && $status !== 'all') {
            $where[] = 'batch.status = ?';
            $params[] = $status;
        }
        $stmt = $this->pdo->prepare(
            'SELECT batch.*, requirement.requirement_no, COALESCE(requirement.position_name_snapshot, \'混合岗位\') AS position_name_snapshot, rule.version_no AS rule_version_no, '
            . '(SELECT COUNT(*) FROM recruitment_resume_batch_requirements batch_requirement WHERE batch_requirement.batch_id = batch.id) AS candidate_requirement_count, '
            . '(SELECT COUNT(*) FROM recruitment_resume_batch_requirements batch_requirement WHERE batch_requirement.batch_id = batch.id AND batch_requirement.classification_ready = 0) AS awaiting_rule_requirement_count, '
            . "(SELECT COUNT(*) FROM recruitment_resume_files file WHERE file.batch_id = batch.id AND file.status IN ('queued', 'processing')) AS pending_file_count, "
            . "(SELECT COUNT(*) FROM recruitment_resume_files file WHERE file.batch_id = batch.id AND file.status = 'skipped') AS skipped_file_count "
            . 'FROM recruitment_resume_batches batch '
            . 'LEFT JOIN recruitment_requirements requirement ON requirement.id = batch.requirement_id '
            . 'LEFT JOIN recruitment_rule_versions rule ON rule.id = batch.rule_version_id '
            . 'WHERE ' . implode(' AND ', $where) . ' ORDER BY batch.id DESC LIMIT 100'
        );
        $stmt->execute($params);
        return ['list' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'scope' => $scope];
    }

    public function createBatch(array $input, array $scope, array $operatorStaff, string $idempotencyKey): array
    {
        $this->ensureSchema();
        $requirementId = $this->positiveId($input['requirement_id'] ?? 0, '招聘需求 ID');
        if (!$this->permissionService->canAccessRequirement($scope, $requirementId)) {
            throw new RecruitmentAdminException('你没有权限为该招聘需求创建批次', 403);
        }
        $request = ['requirement_id' => $requirementId, 'rule_version_id' => (int) ($input['rule_version_id'] ?? 0), 'batch_note' => trim((string) ($input['batch_note'] ?? ''))];
        return $this->idempotentWrite('batch.create', $idempotencyKey, $request, (int) ($operatorStaff['id'] ?? 0), function () use ($request, $operatorStaff): array {
            $requirement = $this->approvedRequirement((int) $request['requirement_id']);
            $rule = (int) $request['rule_version_id'] > 0
                ? $this->publishedRule((int) $request['rule_version_id'])
                : $this->latestPublishedRule($requirement);
            $this->assertRuleMatchesRequirement($requirement, $rule);
            $stmt = $this->pdo->prepare(
                "INSERT INTO recruitment_resume_batches (batch_no, requirement_id, rule_version_id, status, batch_note, created_by) VALUES (?, ?, ?, 'draft', ?, ?)"
            );
            $stmt->execute([
                $this->newBatchNo(),
                $request['requirement_id'],
                (int) $rule['id'],
                mb_substr((string) $request['batch_note'], 0, 1000, 'UTF-8'),
                (int) ($operatorStaff['id'] ?? 0) ?: null,
            ]);
            return $this->batchById((int) $this->pdo->lastInsertId());
        });
    }

    public function createMixedBatch(array $input, array $scope, array $operatorStaff, string $idempotencyKey): array
    {
        $this->ensureSchema();
        $candidateIds = array_values(array_unique(array_filter(array_map('intval', (array) ($input['candidate_position_ids'] ?? [])))));
        $request = [
            'candidate_position_ids' => $candidateIds,
            'batch_note' => trim((string) ($input['batch_note'] ?? '')),
        ];
        return $this->idempotentWrite('batch.create_mixed', $idempotencyKey, $request, (int) ($operatorStaff['id'] ?? 0), function () use ($candidateIds, $scope, $operatorStaff, $request): array {
            $requirements = $this->mixedCandidateRequirements($scope, $candidateIds);
            if (!$requirements) {
                throw new RecruitmentAdminException('当前账号没有可用于混合上传的招聘岗位，请先录入岗位需求或确认数据权限', 422);
            }

            $scopeSnapshot = [];
            foreach ($requirements as $requirement) {
                $rule = $this->latestPublishedRuleOrNull($requirement);
                $scopeSnapshot[] = [
                    'requirement_id' => (int) $requirement['id'],
                    'requirement_no' => (string) $requirement['requirement_no'],
                    'position_name' => (string) $requirement['position_name_snapshot'],
                    'rule_version_id' => $rule ? (int) $rule['id'] : null,
                    'rule_status' => $rule ? 'published' : 'awaiting_publish',
                ];
            }
            $scopeJson = json_encode($scopeSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $scopeHash = hash('sha256', $scopeJson);
            $stmt = $this->pdo->prepare(
                "INSERT INTO recruitment_resume_batches (batch_no, requirement_id, rule_version_id, intake_mode, candidate_scope_json, candidate_scope_hash, classification_status, status, batch_note, created_by) VALUES (?, NULL, NULL, 'mixed_requirements', ?, ?, 'awaiting_upload', 'draft', ?, ?)"
            );
            $stmt->execute([
                $this->newBatchNo(),
                $scopeJson,
                $scopeHash,
                mb_substr((string) $request['batch_note'], 0, 1000, 'UTF-8'),
                (int) ($operatorStaff['id'] ?? 0) ?: null,
            ]);
            $batchId = (int) $this->pdo->lastInsertId();
            $link = $this->pdo->prepare(
                'INSERT INTO recruitment_resume_batch_requirements (batch_id, requirement_id, rule_version_id, rule_status_snapshot, classification_ready) VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($scopeSnapshot as $item) {
                $link->execute([
                    $batchId,
                    (int) $item['requirement_id'],
                    $item['rule_version_id'],
                    (string) $item['rule_status'],
                    $item['rule_version_id'] === null ? 0 : 1,
                ]);
            }
            $batch = $this->batchById($batchId);
            $batch['candidate_requirements'] = $scopeSnapshot;
            $batch['awaiting_rule_requirement_count'] = count(array_filter($scopeSnapshot, static fn (array $item): bool => $item['rule_version_id'] === null));
            return $batch;
        });
    }

    public function upload(int $batchId, array $files, array $scope, array $operatorStaff): array
    {
        $this->ensureSchema();
        $batch = $this->accessibleBatch($batchId, $scope, true);
        $normalized = $this->normalizeFiles($files);
        if (!$normalized) {
            throw new RecruitmentAdminException('请选择需要上传的简历文件');
        }
        $currentCount = (int) $batch['file_count'];
        $currentBytes = (int) $batch['total_bytes'];
        if ($currentCount + count($normalized) > self::MAX_BATCH_FILES) {
            throw new RecruitmentAdminException('单批次最多上传 500 份简历', 413);
        }
        $incomingBytes = array_sum(array_map(static fn (array $file): int => (int) ($file['size'] ?? 0), $normalized));
        if ($currentBytes + $incomingBytes > self::MAX_BATCH_BYTES) {
            throw new RecruitmentAdminException('单批次累计文件大小不能超过 2GB', 413);
        }

        $results = [];
        foreach ($normalized as $file) {
            try {
                $results[] = ['ok' => true] + $this->storeFile($batch, $file, $scope, $operatorStaff);
            } catch (Throwable $error) {
                $results[] = [
                    'ok' => false,
                    'name' => (string) ($file['name'] ?? ''),
                    'message' => $error->getMessage(),
                ];
            }
        }
        $successCount = count(array_filter($results, static fn (array $result): bool => !empty($result['ok'])));
        return [
            'batch' => $this->batchById($batchId),
            'files' => $results,
            'accepted_count' => $successCount,
            'rejected_count' => count($results) - $successCount,
        ];
    }

    public function resolveDuplicate(int $eventId, string $action, string $note, array $scope, array $operatorStaff): array
    {
        $action = strtolower(trim($action));
        $statusByAction = ['skip' => 'skipped', 'reuse' => 'reused', 'continue' => 'continued'];
        if (!isset($statusByAction[$action])) {
            throw new RecruitmentAdminException('重复简历处理动作无效');
        }
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM recruitment_resume_duplicate_events WHERE id = ? LIMIT 1 FOR UPDATE');
            $stmt->execute([$eventId]);
            $event = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$event) {
                throw new RecruitmentAdminException('重复事件不存在', 404);
            }
            $batch = $this->accessibleBatch((int) $event['batch_id'], $scope, true);
            if ((string) $event['status'] !== 'pending') {
                throw new RecruitmentAdminException('重复事件已经处理', 409);
            }
            $file = $this->fileById((int) $event['current_file_id']);
            if ($action === 'skip') {
                $updateFile = $this->pdo->prepare("UPDATE recruitment_resume_files SET status = 'skipped' WHERE id = ?");
                $updateFile->execute([(int) $file['id']]);
            } else {
                $document = $this->documentService->createForFile($file, (int) ($operatorStaff['id'] ?? 0));
                $evidence = json_decode((string) ($event['evidence_json'] ?? '{}'), true) ?: [];
                $evidence['resolution_document_id'] = (int) $document['id'];
                $evidence['reuse_requested'] = $action === 'reuse';
                $evidenceStmt = $this->pdo->prepare('UPDATE recruitment_resume_duplicate_events SET evidence_json = ? WHERE id = ?');
                $evidenceStmt->execute([json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $eventId]);
                $updateFile = $this->pdo->prepare("UPDATE recruitment_resume_files SET status = 'queued' WHERE id = ?");
                $updateFile->execute([(int) $file['id']]);
            }
            $resolve = $this->pdo->prepare('UPDATE recruitment_resume_duplicate_events SET status = ?, resolved_by = ?, resolved_at = NOW(), resolution_note = ? WHERE id = ?');
            $resolve->execute([$statusByAction[$action], (int) ($operatorStaff['id'] ?? 0) ?: null, mb_substr(trim($note), 0, 1000, 'UTF-8'), $eventId]);
            $this->refreshBatchCounters((int) $batch['id']);
            $this->pdo->commit();
            return ['id' => $eventId, 'status' => $statusByAction[$action]];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function listDuplicateEvents(array $scope, array $filters = []): array
    {
        $this->ensureSchema();
        $batchId = (int) ($filters['batch_id'] ?? 0);
        $status = trim((string) ($filters['status'] ?? 'pending'));
        if (!in_array($status, ['pending', 'skipped', 'reused', 'continued', 'all'], true)) {
            throw new RecruitmentAdminException('重复状态筛选无效');
        }
        $where = ['event.status ' . ($status === 'all' ? '<> ?' : '= ?')];
        $params = [$status === 'all' ? '__invalid__' : $status];
        if ($batchId > 0) {
            $where[] = 'event.batch_id = ?';
            $params[] = $batchId;
        }
        $stmt = $this->pdo->prepare(
            'SELECT event.id, event.batch_id, event.duplicate_type, event.status, event.evidence_json, event.created_at, '
            . 'current_file.original_name AS current_file_name, historical_file.original_name AS historical_file_name '
            . 'FROM recruitment_resume_duplicate_events event '
            . 'JOIN recruitment_resume_batches batch ON batch.id = event.batch_id '
            . 'LEFT JOIN recruitment_resume_files current_file ON current_file.id = event.current_file_id '
            . 'LEFT JOIN recruitment_resume_files historical_file ON historical_file.id = event.historical_file_id '
            . 'WHERE ' . implode(' AND ', $where) . ' ORDER BY event.id ASC LIMIT 100'
        );
        $stmt->execute($params);
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $item) {
            try {
                $this->accessibleBatch((int) $item['batch_id'], $scope);
            } catch (Throwable $error) {
                continue;
            }
            $evidence = json_decode((string) ($item['evidence_json'] ?? '{}'), true);
            $items[] = [
                'id' => (int) $item['id'],
                'batch_id' => (int) $item['batch_id'],
                'duplicate_type' => (string) $item['duplicate_type'],
                'status' => (string) $item['status'],
                'current_file_name' => (string) ($item['current_file_name'] ?? ''),
                'historical_file_name' => (string) ($item['historical_file_name'] ?? ''),
                'evidence' => is_array($evidence) ? $evidence : [],
                'created_at' => $item['created_at'],
            ];
        }
        return ['list' => $items, 'total' => count($items), 'limit' => 100];
    }

    public function resolveDuplicateBatch(array $eventIds, string $action, string $note, array $scope, array $operatorStaff): array
    {
        $eventIds = array_values(array_unique(array_filter(array_map('intval', $eventIds))));
        if (!$eventIds || count($eventIds) > 100) {
            throw new RecruitmentAdminException('批量重复处理需选择 1 至 100 条记录');
        }
        if (!in_array($action, ['skip', 'reuse', 'continue'], true)) {
            throw new RecruitmentAdminException('批量重复处理动作无效');
        }
        $success = [];
        $failed = [];
        foreach ($eventIds as $eventId) {
            try {
                $success[] = $this->resolveDuplicate($eventId, $action, $note, $scope, $operatorStaff);
            } catch (Throwable $error) {
                $failed[] = ['id' => $eventId, 'message' => $error->getMessage(), 'status' => $error instanceof RecruitmentAdminException ? $error->statusCode() : 500];
            }
        }
        return ['action' => $action, 'requested_count' => count($eventIds), 'success_count' => count($success), 'failed_count' => count($failed), 'success' => $success, 'failed' => $failed];
    }

    public function documentService(): ResumeDocumentService
    {
        return $this->documentService;
    }

    public function accessibleBatch(int $batchId, array $scope, bool $lock = false): array
    {
        $sql = 'SELECT batch.*, requirement.status AS requirement_status, requirement.position_id, requirement.position_name_snapshot '
            . 'FROM recruitment_resume_batches batch LEFT JOIN recruitment_requirements requirement ON requirement.id = batch.requirement_id '
            . 'WHERE batch.id = ? LIMIT 1' . ($lock ? ' FOR UPDATE' : '');
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$this->positiveId($batchId, '批次 ID')]);
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$batch) {
            throw new RecruitmentAdminException('简历批次不存在', 404);
        }
        if ((string) ($batch['intake_mode'] ?? 'single_requirement') === 'mixed_requirements') {
            $requirementIds = $this->batchRequirementIds((int) $batch['id']);
            if (!$requirementIds || !array_filter($requirementIds, fn (int $id): bool => $this->permissionService->canAccessRequirement($scope, $id))) {
                throw new RecruitmentAdminException('你没有权限访问该简历批次', 403);
            }
            return $batch;
        }
        if (!$this->permissionService->canAccessRequirement($scope, (int) $batch['requirement_id'])) {
            throw new RecruitmentAdminException('你没有权限访问该简历批次', 403);
        }
        return $batch;
    }

    private function storeFile(array $batch, array $file, array $scope, array $operatorStaff): array
    {
        $this->validateUpload($file);
        $name = mb_substr(basename((string) $file['name']), 0, 255, 'UTF-8');
        $tmp = (string) $file['tmp_name'];
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mime = $this->detectMime($tmp);
        if (!in_array($mime, self::ALLOWED_TYPES[$extension], true)) {
            throw new RecruitmentAdminException('文件内容与扩展名不一致');
        }
        $this->pdo->beginTransaction();
        try {
            $lockedBatch = $this->accessibleBatch((int) $batch['id'], $scope, true);
            if ((int) $lockedBatch['file_count'] >= self::MAX_BATCH_FILES || (int) $lockedBatch['total_bytes'] + (int) $file['size'] > self::MAX_BATCH_BYTES) {
                throw new RecruitmentAdminException('批次容量已达到上限', 413);
            }
            $stored = $this->fileAdapter->storeUploadedFile(
                $file,
                (int) $batch['id'],
                (int) ($operatorStaff['id'] ?? 0),
                array_values(array_unique(array_merge(...array_values(self::ALLOWED_TYPES)))),
                self::MAX_FILE_BYTES
            );
            $sha256 = (string) $stored['sha256'];
            $duplicate = $this->exactDuplicate($sha256, (int) $stored['byte_size']);
            $filenameMatch = $this->filenameMatch($name, $scope, $lockedBatch);
            $stmt = $this->pdo->prepare(
                'INSERT INTO recruitment_resume_files (batch_id, original_name, storage_key, platform_asset_id, mime_type, byte_size, sha256, status, duplicate_of_file_id, filename_match_json, uploaded_by) '
                . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $initialStatus = $duplicate ? 'uploaded' : 'queued';
            $stmt->execute([
                (int) $batch['id'], $name, (string) $stored['storage_key'], (int) $stored['platform_asset_id'], $mime, (int) $stored['byte_size'], $sha256, $initialStatus,
                $duplicate ? (int) $duplicate['id'] : null,
                json_encode($filenameMatch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                (int) ($operatorStaff['id'] ?? 0) ?: null,
            ]);
            $fileId = (int) $this->pdo->lastInsertId();
            $source = $this->pdo->prepare('INSERT INTO recruitment_resume_file_sources (resume_file_id, batch_id, source_type, original_name, uploaded_by) VALUES (?, ?, ?, ?, ?)');
            $source->execute([$fileId, (int) $batch['id'], 'admin_upload', $name, (int) ($operatorStaff['id'] ?? 0) ?: null]);
            $storedFile = $this->fileById($fileId);
            $duplicateEventId = null;
            if ($duplicate) {
                $event = $this->pdo->prepare(
                    "INSERT INTO recruitment_resume_duplicate_events (duplicate_type, batch_id, current_file_id, historical_file_id, historical_document_id, evidence_json, status) VALUES ('exact_document', ?, ?, ?, ?, ?, 'pending')"
                );
                $event->execute([
                    (int) $batch['id'], $fileId, (int) $duplicate['id'], $duplicate['document_id'] ?? null,
                    json_encode(['sha256' => $sha256, 'byte_size' => (int) $file['size']], JSON_UNESCAPED_SLASHES),
                ]);
                $duplicateEventId = (int) $this->pdo->lastInsertId();
            } else {
                $this->documentService->createForFile($storedFile, (int) ($operatorStaff['id'] ?? 0));
            }
            $this->refreshBatchCounters((int) $batch['id']);
            $this->pdo->commit();
            return [
                'id' => $fileId,
                'name' => $name,
                'mime_type' => $mime,
                'byte_size' => (int) $stored['byte_size'],
                'sha256' => $sha256,
                'status' => $initialStatus,
                'duplicate' => (bool) $duplicate,
                'duplicate_event_id' => $duplicateEventId,
                'filename_match' => $filenameMatch,
            ];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function approvedRequirement(int $id): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM recruitment_requirements WHERE id = ? AND status = 'approved' LIMIT 1 FOR UPDATE");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RecruitmentAdminException('仅已批准招聘需求可创建批次', 409);
        }
        return $row;
    }

    private function publishedRule(int $id): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM recruitment_rule_versions WHERE id = ? AND status = 'published' LIMIT 1 FOR UPDATE");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RecruitmentAdminException('批次必须绑定已发布岗位规则', 409);
        }
        return $row;
    }

    private function latestPublishedRule(array $requirement): array
    {
        $positionId = (int) ($requirement['position_id'] ?? 0);
        $positionName = trim((string) ($requirement['position_name_snapshot'] ?? ''));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM recruitment_rule_versions WHERE status = 'published' AND ((? > 0 AND position_id = ?) OR position_name_snapshot = ?) ORDER BY version_no DESC, id DESC LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$positionId, $positionId, $positionName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RecruitmentAdminException('该招聘需求尚未配置已发布岗位规则', 409);
        }
        return $row;
    }

    private function assertRuleMatchesRequirement(array $requirement, array $rule): void
    {
        $requirementPosition = (int) ($requirement['position_id'] ?? 0);
        $rulePosition = (int) ($rule['position_id'] ?? 0);
        $sameId = $requirementPosition > 0 && $rulePosition > 0 && $requirementPosition === $rulePosition;
        $sameName = trim((string) $requirement['position_name_snapshot']) === trim((string) $rule['position_name_snapshot']);
        if (!$sameId && !$sameName) {
            throw new RecruitmentAdminException('岗位规则与招聘需求不匹配', 409);
        }
    }

    private function exactDuplicate(string $sha256, int $byteSize): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT file.*, page.document_id FROM recruitment_resume_files file '
            . 'LEFT JOIN recruitment_resume_document_pages page ON page.resume_file_id = file.id '
            . "WHERE file.sha256 = ? AND file.byte_size = ? AND file.status <> 'skipped' ORDER BY file.id ASC LIMIT 1"
        );
        $stmt->execute([$sha256, $byteSize]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function filenameMatch(string $filename, array $scope, array $batch): array
    {
        if ((string) ($batch['intake_mode'] ?? 'single_requirement') === 'mixed_requirements') {
            $candidateIds = $this->batchRequirementIds((int) $batch['id']);
            if (!$candidateIds) {
                return ['candidates' => [], 'confidence' => 0.0, 'requires_confirmation' => true];
            }
            $placeholders = implode(', ', array_fill(0, count($candidateIds), '?'));
            $stmt = $this->pdo->prepare("SELECT id, requirement_no, position_name_snapshot FROM recruitment_requirements WHERE id IN ({$placeholders})");
            $stmt->execute($candidateIds);
        } else {
        [$scopeWhere, $params] = $this->permissionService->requirementWhereClause($scope, 'requirement');
        $stmt = $this->pdo->prepare(
            "SELECT id, requirement_no, position_name_snapshot FROM recruitment_requirements requirement WHERE status = 'approved' AND " . $scopeWhere
        );
        $stmt->execute($params);
        }
        $matches = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $requirement) {
            $title = trim((string) $requirement['position_name_snapshot']);
            if ($title !== '' && mb_stripos($filename, $title, 0, 'UTF-8') !== false) {
                $matches[] = [
                    'requirement_id' => (int) $requirement['id'],
                    'requirement_no' => (string) $requirement['requirement_no'],
                    'matched_text' => $title,
                    'confidence' => 1.0,
                ];
            }
        }
        return [
            'candidates' => $matches,
            'confidence' => count($matches) === 1 ? 1.0 : 0.0,
            'requires_confirmation' => count($matches) !== 1,
        ];
    }

    private function refreshBatchCounters(int $batchId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE recruitment_resume_batches batch SET "
            . 'file_count = (SELECT COUNT(*) FROM recruitment_resume_files file WHERE file.batch_id = batch.id), '
            . "total_bytes = (SELECT COALESCE(SUM(file.byte_size), 0) FROM recruitment_resume_files file WHERE file.batch_id = batch.id), "
            . "duplicate_count = (SELECT COUNT(*) FROM recruitment_resume_duplicate_events duplicate_event WHERE duplicate_event.batch_id = batch.id), "
            . "status = CASE WHEN batch.status = 'draft' THEN 'uploaded' ELSE batch.status END, classification_status = CASE WHEN batch.intake_mode = 'mixed_requirements' AND EXISTS (SELECT 1 FROM recruitment_resume_batch_requirements batch_requirement WHERE batch_requirement.batch_id = batch.id AND batch_requirement.classification_ready = 0) THEN 'awaiting_rules' WHEN batch.intake_mode = 'mixed_requirements' THEN 'queued' ELSE batch.classification_status END WHERE batch.id = ?"
        );
        $stmt->execute([$batchId]);
    }

    private function idempotentWrite(string $action, string $key, array $request, int $staffId, callable $operation): array
    {
        $key = trim($key);
        if ($key === '' || strlen($key) > 128) {
            throw new RecruitmentAdminException('写请求必须提供有效的 Idempotency-Key');
        }
        $hash = hash('sha256', json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare('INSERT IGNORE INTO recruitment_idempotency_keys (idempotency_key, action, request_hash, operator_staff_id) VALUES (?, ?, ?, ?)');
            $insert->execute([$key, $action, $hash, $staffId ?: null]);
            if ($insert->rowCount() !== 1) {
                $existing = $this->pdo->prepare('SELECT request_hash, response_json FROM recruitment_idempotency_keys WHERE idempotency_key = ? AND action = ? FOR UPDATE');
                $existing->execute([$key, $action]);
                $row = $existing->fetch(PDO::FETCH_ASSOC);
                if (!$row || !hash_equals((string) $row['request_hash'], $hash)) {
                    throw new RecruitmentAdminException('Idempotency-Key 已用于不同请求', 409);
                }
                $response = json_decode((string) ($row['response_json'] ?? ''), true);
                if (!is_array($response)) {
                    throw new RecruitmentAdminException('同一写请求正在处理中', 409);
                }
                $this->pdo->commit();
                return $response + ['idempotent' => true];
            }
            $result = $operation();
            $update = $this->pdo->prepare('UPDATE recruitment_idempotency_keys SET response_json = ? WHERE idempotency_key = ? AND action = ?');
            $update->execute([json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $key, $action]);
            $this->pdo->commit();
            return $result + ['idempotent' => false];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function validateUpload(array $file): void
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new RecruitmentAdminException('文件上传不完整，错误码：' . $error);
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size < 1 || $size > self::MAX_FILE_BYTES) {
            throw new RecruitmentAdminException('单个简历文件必须小于等于 20MB', 413);
        }
        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!isset(self::ALLOWED_TYPES[$extension])) {
            throw new RecruitmentAdminException('仅支持 PDF、JPG、JPEG、PNG 和 WEBP');
        }
        if (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new RecruitmentAdminException('上传临时文件无效');
        }
    }

    private function detectMime(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if (!$finfo) {
            throw new RecruitmentAdminException('服务器缺少文件类型检测能力', 500);
        }
        $mime = (string) finfo_file($finfo, $path);
        finfo_close($finfo);
        return strtolower(trim($mime));
    }

    private function normalizeFiles(array $files): array
    {
        if (isset($files['tmp_name']) && is_array($files['tmp_name'])) {
            $normalized = [];
            foreach ($files['tmp_name'] as $index => $tmpName) {
                $normalized[] = [
                    'name' => $files['name'][$index] ?? '',
                    'type' => $files['type'][$index] ?? '',
                    'tmp_name' => $tmpName,
                    'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $files['size'][$index] ?? 0,
                ];
            }
            return $normalized;
        }
        return isset($files['tmp_name']) ? [$files] : [];
    }

    private function fileById(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM recruitment_resume_files WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RecruitmentAdminException('简历文件不存在', 404);
        }
        return $row;
    }

    private function batchById(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM recruitment_resume_batches WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['id' => $id];
    }

    private function mixedCandidateRequirements(array $scope, array $candidateIds): array
    {
        [$scopeWhere, $scopeParams] = $this->permissionService->requirementWhereClause($scope, 'requirement');
        $where = ["requirement.status <> 'closed'", $scopeWhere];
        $params = $scopeParams;
        if ($candidateIds) {
            $where[] = 'requirement.id IN (' . implode(', ', array_fill(0, count($candidateIds), '?')) . ')';
            array_push($params, ...$candidateIds);
        }
        $stmt = $this->pdo->prepare('SELECT * FROM recruitment_requirements requirement WHERE ' . implode(' AND ', $where) . ' ORDER BY requirement.id ASC');
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function latestPublishedRuleOrNull(array $requirement): ?array
    {
        $positionId = (int) ($requirement['position_id'] ?? 0);
        $positionName = trim((string) ($requirement['position_name_snapshot'] ?? ''));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM recruitment_rule_versions WHERE status = 'published' AND ((? > 0 AND position_id = ?) OR position_name_snapshot = ?) ORDER BY version_no DESC, id DESC LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$positionId, $positionId, $positionName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function batchRequirementIds(int $batchId): array
    {
        $stmt = $this->pdo->prepare('SELECT requirement_id FROM recruitment_resume_batch_requirements WHERE batch_id = ? ORDER BY requirement_id ASC');
        $stmt->execute([$batchId]);
        return array_values(array_unique(array_filter(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)))));
    }

    private function newBatchNo(): string
    {
        return 'RB' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    }

    private function positiveId($value, string $label): int
    {
        $id = (int) $value;
        if ($id <= 0) {
            throw new RecruitmentAdminException($label . '必须为正整数');
        }
        return $id;
    }

    private function ensureSchema(): void
    {
        foreach ([
            'recruitment_resume_batches', 'recruitment_resume_files', 'recruitment_resume_file_sources',
            'recruitment_resume_documents', 'recruitment_resume_document_pages', 'recruitment_resume_jobs',
            'recruitment_resume_duplicate_events', 'recruitment_idempotency_keys', 'recruitment_resume_batch_requirements',
        ] as $table) {
            if (!adminTableExists($this->pdo, $table)) {
                throw new RecruitmentAdminException('招聘数据库迁移尚未执行：' . $table, 500);
            }
        }
    }
}
