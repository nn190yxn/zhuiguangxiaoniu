<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/platform/RecruitmentPlatformJobAdapter.php';

final class RecruitmentRuleException extends RuntimeException
{
    private int $statusCode;
    private array $details;

    public function __construct(string $message, int $statusCode = 400, array $details = [])
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
        $this->details = $details;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function details(): array
    {
        return $this->details;
    }
}

final class RecruitmentRuleService
{
    private const STATUSES = ['draft', 'in_review', 'published', 'archived'];
    private const SENSITIVE_TERMS = ['性别', '男', '女', '婚育', '婚姻', '怀孕', '民族', '籍贯', '宗教', '年龄'];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function listRules(array $filters = []): array
    {
        $this->ensureSchema();
        $where = [];
        $params = [];
        $status = trim(strtolower((string) ($filters['status'] ?? '')));
        if ($status !== '' && $status !== 'all') {
            $where[] = 'status = ?';
            $params[] = $this->normalizeStatus($status);
        }
        $positionId = (int) ($filters['position_id'] ?? 0);
        if ($positionId > 0) {
            $where[] = 'position_id = ?';
            $params[] = $positionId;
        }
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            if (mb_strlen($keyword, 'UTF-8') > 100) {
                throw new RecruitmentRuleException('搜索关键词不能超过 100 个字符');
            }
            $where[] = '(position_name_snapshot LIKE ? OR job_description LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($params, $like, $like);
        }
        $sql = 'SELECT * FROM recruitment_rule_versions';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY position_name_snapshot ASC, version_no DESC, id DESC LIMIT 200';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $items = array_map([$this, 'formatRule'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        return ['list' => $items, 'total' => count($items)];
    }

    public function getRule(int $id): array
    {
        $this->ensureSchema();
        return $this->getById($this->positiveId($id, '规则版本 ID'));
    }

    public function createDraft(array $input, array $operatorUser, array $operatorStaff, string $idempotencyKey): array
    {
        $data = $this->normalizeInput($input);
        return $this->write('rule.create', $idempotencyKey, $data, $operatorUser, $operatorStaff, function () use ($data, $operatorUser, $operatorStaff): array {
            $versionNo = $this->nextVersionNo($data['position_id'], $data['position_name_snapshot']);
            $stmt = $this->pdo->prepare(
                'INSERT INTO recruitment_rule_versions '
                . '(position_id, position_name_snapshot, version_no, status, job_description, hard_conditions_json, experience_rules_json, keyword_rules_json, grade_rules_json, prompt_version, created_by) '
                . "VALUES (?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $data['position_id'],
                $data['position_name_snapshot'],
                $versionNo,
                $data['job_description'],
                $data['hard_conditions_json'],
                $data['experience_rules_json'],
                $data['keyword_rules_json'],
                $data['grade_rules_json'],
                $data['prompt_version'],
                $this->staffId($operatorStaff),
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $after = $this->getById($id);
            $this->audit($operatorUser, $operatorStaff, 'rule.create', $id, null, $after);
            return $after;
        });
    }

    public function updateDraft(int $id, array $input, array $operatorUser, array $operatorStaff, string $idempotencyKey): array
    {
        $id = $this->positiveId($id, '规则版本 ID');
        $data = $this->normalizeInput($input);
        return $this->write('rule.update', $idempotencyKey, ['id' => $id] + $data, $operatorUser, $operatorStaff, function () use ($id, $data, $operatorUser, $operatorStaff): array {
            $before = $this->lockedRule($id);
            if ($before['status'] !== 'draft') {
                throw new RecruitmentRuleException('仅规则草稿可编辑', 409);
            }
            $stmt = $this->pdo->prepare(
                'UPDATE recruitment_rule_versions SET position_id = ?, position_name_snapshot = ?, job_description = ?, '
                . 'hard_conditions_json = ?, experience_rules_json = ?, keyword_rules_json = ?, grade_rules_json = ?, prompt_version = ? WHERE id = ?'
            );
            $stmt->execute([
                $data['position_id'],
                $data['position_name_snapshot'],
                $data['job_description'],
                $data['hard_conditions_json'],
                $data['experience_rules_json'],
                $data['keyword_rules_json'],
                $data['grade_rules_json'],
                $data['prompt_version'],
                $id,
            ]);
            $after = $this->getById($id);
            $this->audit($operatorUser, $operatorStaff, 'rule.update', $id, $before, $after);
            return $after;
        });
    }

    public function transition(int $id, string $action, array $input, array $operatorUser, array $operatorStaff, string $idempotencyKey): array
    {
        $id = $this->positiveId($id, '规则版本 ID');
        $action = strtolower(trim($action));
        if (!in_array($action, ['submit', 'publish', 'archive', 'copy'], true)) {
            throw new RecruitmentRuleException('岗位规则动作无效');
        }
        return $this->write('rule.' . $action, $idempotencyKey, ['id' => $id, 'action' => $action, 'input' => $input], $operatorUser, $operatorStaff, function () use ($id, $action, $input, $operatorUser, $operatorStaff): array {
            $before = $this->lockedRule($id);
            if ($action === 'copy') {
                $copy = $this->copyRule($before, $operatorStaff);
                $this->audit($operatorUser, $operatorStaff, 'rule.copy', (int) $copy['id'], $before, $copy);
                return $copy;
            }
            if ($action === 'submit') {
                if ($before['status'] !== 'draft') {
                    throw new RecruitmentRuleException('仅草稿规则可提交审核', 409);
                }
                $this->setStatus($id, 'in_review');
            } elseif ($action === 'publish') {
                if ($before['status'] !== 'in_review') {
                    throw new RecruitmentRuleException('仅审核中规则可发布', 409);
                }
                $this->assertPublishable($before);
                $this->archivePublishedPeers($before);
                $stmt = $this->pdo->prepare("UPDATE recruitment_rule_versions SET status = 'published', published_by = ?, published_at = NOW() WHERE id = ?");
                $stmt->execute([$this->staffId($operatorStaff), $id]);
                $this->activateMixedClassificationForRule($this->getById($id));
            } else {
                if ($before['status'] === 'archived') {
                    throw new RecruitmentRuleException('岗位规则已经归档', 409);
                }
                $stmt = $this->pdo->prepare("UPDATE recruitment_rule_versions SET status = 'archived', archived_at = NOW() WHERE id = ?");
                $stmt->execute([$id]);
            }
            $after = $this->getById($id);
            $this->audit($operatorUser, $operatorStaff, 'rule.' . $action, $id, $before, $after);
            return $after;
        });
    }

    private function copyRule(array $source, array $operatorStaff): array
    {
        $versionNo = $this->nextVersionNo($source['position_id'] !== null ? (int) $source['position_id'] : null, (string) $source['position_name_snapshot']);
        $stmt = $this->pdo->prepare(
            'INSERT INTO recruitment_rule_versions '
            . '(position_id, position_name_snapshot, version_no, status, job_description, hard_conditions_json, experience_rules_json, keyword_rules_json, grade_rules_json, prompt_version, source_rule_version_id, created_by) '
            . "VALUES (?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $source['position_id'],
            $source['position_name_snapshot'],
            $versionNo,
            $source['job_description'],
            $source['hard_conditions_json'],
            $source['experience_rules_json'],
            $source['keyword_rules_json'],
            $source['grade_rules_json'],
            $source['prompt_version'],
            (int) $source['id'],
            $this->staffId($operatorStaff),
        ]);
        return $this->getById((int) $this->pdo->lastInsertId());
    }

    private function write(string $action, string $key, array $request, array $operatorUser, array $operatorStaff, callable $operation): array
    {
        $this->ensureSchema();
        $key = trim($key);
        if ($key === '' || strlen($key) > 128) {
            throw new RecruitmentRuleException('写请求必须提供有效的 Idempotency-Key');
        }
        $hash = hash('sha256', json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        ensureAdminOperationLogsTable($this->pdo);
        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare('INSERT IGNORE INTO recruitment_idempotency_keys (idempotency_key, action, request_hash, operator_staff_id) VALUES (?, ?, ?, ?)');
            $insert->execute([$key, $action, $hash, $this->staffId($operatorStaff)]);
            if ($insert->rowCount() !== 1) {
                $existing = $this->pdo->prepare('SELECT request_hash, response_json FROM recruitment_idempotency_keys WHERE idempotency_key = ? AND action = ? FOR UPDATE');
                $existing->execute([$key, $action]);
                $row = $existing->fetch(PDO::FETCH_ASSOC);
                if (!$row || !hash_equals((string) $row['request_hash'], $hash)) {
                    throw new RecruitmentRuleException('Idempotency-Key 已用于不同请求', 409);
                }
                $response = json_decode((string) ($row['response_json'] ?? ''), true);
                if (!is_array($response)) {
                    throw new RecruitmentRuleException('同一写请求正在处理中', 409);
                }
                $this->pdo->commit();
                return $response + ['idempotent' => true];
            }
            $result = $operation();
            $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $update = $this->pdo->prepare('UPDATE recruitment_idempotency_keys SET response_json = ? WHERE idempotency_key = ? AND action = ?');
            $update->execute([$encoded, $key, $action]);
            $this->pdo->commit();
            return $result + ['idempotent' => false];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($this->isDuplicateKeyError($error)) {
                throw new RecruitmentRuleException('岗位规则版本已存在', 409);
            }
            throw $error;
        }
    }

    private function normalizeInput(array $input): array
    {
        $title = trim((string) ($input['position_name'] ?? $input['job_title'] ?? $input['position_name_snapshot'] ?? ''));
        if ($title === '' || mb_strlen($title, 'UTF-8') > 120) {
            throw new RecruitmentRuleException('岗位名称必须填写且不能超过 120 个字符');
        }
        return [
            'position_id' => $this->nullablePositiveId($input['position_id'] ?? null, '岗位 ID'),
            'position_name_snapshot' => $title,
            'job_description' => $this->limitedText($input['job_description'] ?? '', 30000),
            'hard_conditions_json' => $this->normalizeJson($input['hard_conditions'] ?? $input['hard_conditions_json'] ?? []),
            'experience_rules_json' => $this->normalizeJson($input['experience_rules'] ?? $input['experience_rules_json'] ?? []),
            'keyword_rules_json' => $this->normalizeJson($input['keyword_rules'] ?? $input['keyword_rules_json'] ?? []),
            'grade_rules_json' => $this->normalizeJson($input['grade_rules'] ?? $input['grade_rules_json'] ?? $this->defaultGradeRules()),
            'prompt_version' => $this->limitedText($input['prompt_version'] ?? '', 80),
        ];
    }

    private function assertPublishable(array $rule): void
    {
        $hardConditions = json_decode((string) ($rule['hard_conditions_json'] ?? '[]'), true);
        if (!is_array($hardConditions)) {
            throw new RecruitmentRuleException('硬性条件配置无效');
        }
        $plain = json_encode($hardConditions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach (self::SENSITIVE_TERMS as $term) {
            if (is_string($plain) && mb_strpos($plain, $term, 0, 'UTF-8') !== false) {
                throw new RecruitmentRuleException('硬性条件包含敏感属性：' . $term, 422);
            }
        }
        foreach ($hardConditions as $condition) {
            if (!is_array($condition)) {
                continue;
            }
            $basis = trim((string) ($condition['legal_basis'] ?? ''));
            $necessity = trim((string) ($condition['business_necessity'] ?? ''));
            if ($basis === '' || $necessity === '') {
                throw new RecruitmentRuleException('发布硬性条件必须填写合法依据和岗位必要性', 422);
            }
        }
    }

    private function archivePublishedPeers(array $rule): void
    {
        $stmt = $this->pdo->prepare("UPDATE recruitment_rule_versions SET status = 'archived', archived_at = NOW() WHERE status = 'published' AND id <> ? AND ((position_id IS NULL AND position_name_snapshot = ?) OR position_id = ?)");
        $stmt->execute([(int) $rule['id'], (string) $rule['position_name_snapshot'], $rule['position_id']]);
    }

    private function nextVersionNo(?int $positionId, string $positionName): int
    {
        if ($positionId !== null) {
            $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(version_no), 0) FROM recruitment_rule_versions WHERE position_id = ?');
            $stmt->execute([$positionId]);
        } else {
            $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(version_no), 0) FROM recruitment_rule_versions WHERE position_id IS NULL AND position_name_snapshot = ?');
            $stmt->execute([$positionName]);
        }
        return ((int) $stmt->fetchColumn()) + 1;
    }

    private function lockedRule(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM recruitment_rule_versions WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RecruitmentRuleException('岗位规则版本不存在', 404);
        }
        return $row;
    }

    private function getById(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM recruitment_rule_versions WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RecruitmentRuleException('岗位规则版本不存在', 404);
        }
        return $this->formatRule($row);
    }

    private function formatRule(array $row): array
    {
        foreach (['id', 'position_id', 'version_no', 'source_rule_version_id', 'created_by', 'published_by'] as $field) {
            $row[$field] = isset($row[$field]) ? (int) $row[$field] : null;
        }
        foreach (['hard_conditions_json', 'experience_rules_json', 'keyword_rules_json', 'grade_rules_json'] as $field) {
            $decoded = json_decode((string) ($row[$field] ?? '[]'), true);
            $row[str_replace('_json', '', $field)] = is_array($decoded) ? $decoded : [];
        }
        $row['status_label'] = $this->statusLabel((string) $row['status']);
        return $row;
    }

    private function setStatus(int $id, string $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE recruitment_rule_versions SET status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);
    }

    private function activateMixedClassificationForRule(array $rule): void
    {
        $positionId = isset($rule['position_id']) ? (int) $rule['position_id'] : 0;
        $positionName = (string) ($rule['position_name_snapshot'] ?? '');
        $activate = $this->pdo->prepare(
            "UPDATE recruitment_resume_batch_requirements scope "
            . "JOIN recruitment_resume_batches batch ON batch.id = scope.batch_id "
            . "JOIN recruitment_requirements requirement ON requirement.id = scope.requirement_id "
            . "SET scope.rule_version_id = ?, scope.rule_status_snapshot = 'published', scope.classification_ready = 1 "
            . "WHERE batch.intake_mode = 'mixed_requirements' AND scope.classification_ready = 0 "
            . "AND ((? > 0 AND requirement.position_id = ?) OR requirement.position_name_snapshot = ?)"
        );
        $activate->execute([(int) $rule['id'], $positionId, $positionId, $positionName]);
        if ($activate->rowCount() < 1) {
            return;
        }

        $documents = $this->pdo->prepare(
            "SELECT document.id FROM recruitment_resume_documents document "
            . "JOIN recruitment_resume_batch_requirements scope ON scope.batch_id = document.batch_id "
            . "WHERE scope.rule_version_id = ? AND document.classification_status = 'awaiting_rule'"
        );
        $documents->execute([(int) $rule['id']]);
        $insert = $this->pdo->prepare(
            "INSERT IGNORE INTO recruitment_resume_jobs (document_id, job_type, status, idempotency_hash) VALUES (?, 'extract', 'pending', ?)"
        );
        $documentStatus = $this->pdo->prepare("UPDATE recruitment_resume_documents SET status = 'queued' WHERE id = ? AND status = 'completed'");
        $adapter = new RecruitmentPlatformJobAdapter($this->pdo);
        foreach ($documents->fetchAll(PDO::FETCH_COLUMN) ?: [] as $documentId) {
            $hash = hash('sha256', (int) $documentId . ':mixed-classify-rule:' . (int) $rule['id']);
            $insert->execute([(int) $documentId, $hash]);
            $job = $this->pdo->prepare('SELECT * FROM recruitment_resume_jobs WHERE document_id = ? AND idempotency_hash = ? LIMIT 1');
            $job->execute([(int) $documentId, $hash]);
            $queued = $job->fetch(PDO::FETCH_ASSOC);
            if ($queued) {
                $adapter->enqueue($queued);
                $documentStatus->execute([(int) $documentId]);
            }
        }
        $this->pdo->exec(
            "UPDATE recruitment_resume_batches batch SET classification_status = CASE "
            . "WHEN EXISTS (SELECT 1 FROM recruitment_resume_batch_requirements scope WHERE scope.batch_id = batch.id AND scope.classification_ready = 0) THEN 'awaiting_rules' "
            . "ELSE 'queued' END WHERE batch.intake_mode = 'mixed_requirements'"
        );
    }

    private function ensureSchema(): void
    {
        foreach (['recruitment_rule_versions', 'recruitment_idempotency_keys'] as $table) {
            if (!adminTableExists($this->pdo, $table)) {
                throw new RecruitmentRuleException('招聘数据库迁移尚未执行：' . $table, 500);
            }
        }
    }

    private function audit(array $operatorUser, array $operatorStaff, string $action, int $targetId, ?array $before, array $after): void
    {
        adminRecordOperation($this->pdo, $operatorUser, $operatorStaff, [
            'module' => 'recruitment',
            'action' => $action,
            'target_type' => 'recruitment_rule_version',
            'target_id' => (string) $targetId,
            'before' => $before,
            'after' => $after,
        ]);
    }

    private function normalizeJson($value): string
    {
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return '[]';
            }
            $decoded = json_decode($value, true);
            if (!is_array($decoded)) {
                throw new RecruitmentRuleException('规则 JSON 格式无效');
            }
            $value = $decoded;
        }
        if (!is_array($value)) {
            throw new RecruitmentRuleException('规则配置必须是数组或 JSON');
        }
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function defaultGradeRules(): array
    {
        return [
            'A' => ['min' => 80, 'max' => 100],
            'B' => ['min' => 60, 'max' => 79],
            'C' => ['min' => 0, 'max' => 59],
        ];
    }

    private function normalizeStatus(string $status): string
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new RecruitmentRuleException('岗位规则状态无效');
        }
        return $status;
    }

    private function statusLabel(string $status): string
    {
        return [
            'draft' => '草稿',
            'in_review' => '审核中',
            'published' => '已发布',
            'archived' => '已归档',
        ][$status] ?? $status;
    }

    private function nullablePositiveId($value, string $label): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return $this->positiveId((int) $value, $label);
    }

    private function positiveId(int $value, string $label): int
    {
        if ($value <= 0) {
            throw new RecruitmentRuleException($label . '无效');
        }
        return $value;
    }

    private function limitedText($value, int $maxLength): string
    {
        $value = trim((string) ($value ?? ''));
        if (mb_strlen($value, 'UTF-8') > $maxLength) {
            throw new RecruitmentRuleException('输入内容长度超过限制');
        }
        return $value;
    }

    private function staffId(array $staff): ?int
    {
        $id = (int) ($staff['id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    private function isDuplicateKeyError(Throwable $error): bool
    {
        return $error instanceof PDOException && (($error->errorInfo[1] ?? null) === 1062 || $error->getCode() === '23000');
    }
}
