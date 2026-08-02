<?php

declare(strict_types=1);

final class RecruitmentRequirementException extends RuntimeException
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

final class RecruitmentRequirementService
{
    private const STATUSES = ['draft', 'approval_pending', 'returned', 'approved', 'closed'];

    private PDO $pdo;
    private RecruitmentPermissionService $permissionService;

    public function __construct(PDO $pdo, RecruitmentPermissionService $permissionService)
    {
        $this->pdo = $pdo;
        $this->permissionService = $permissionService;
    }

    public function listRequirements(array $scope, array $filters = []): array
    {
        $this->ensureSchema();
        [$scopeWhere, $scopeParams] = $this->permissionService->requirementWhereClause($scope, 'requirement');
        $where = [$scopeWhere];
        $params = $scopeParams;

        $status = trim(strtolower((string) ($filters['status'] ?? '')));
        if ($status !== '' && $status !== 'all') {
            $where[] = 'requirement.status = ?';
            $params[] = $this->normalizeStatus($status);
        }
        $storeId = (int) ($filters['store_id'] ?? 0);
        if ($storeId > 0) {
            $where[] = 'requirement.store_id = ?';
            $params[] = $storeId;
        }
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            if (mb_strlen($keyword, 'UTF-8') > 100) {
                throw new RecruitmentRequirementException('搜索关键词不能超过 100 个字符');
            }
            $where[] = '(requirement.requirement_no LIKE ? OR requirement.position_name_snapshot LIKE ? OR requirement.job_description LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($params, $like, $like, $like);
        }

        $sql = $this->selectSql() . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY requirement.updated_at DESC, requirement.id DESC LIMIT 200';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $items = array_map([$this, 'formatRequirement'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

        return [
            'list' => $items,
            'total' => count($items),
            'scope' => $scope,
        ];
    }

    public function getRequirement(int $id, array $scope): array
    {
        $this->ensureSchema();
        $id = $this->positiveId($id, '招聘需求 ID');
        if (!$this->permissionService->canAccessRequirement($scope, $id)) {
            throw new RecruitmentRequirementException('你没有权限访问该招聘需求', 403);
        }
        $stmt = $this->pdo->prepare($this->selectSql() . ' WHERE requirement.id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RecruitmentRequirementException('招聘需求不存在', 404);
        }
        return $this->formatRequirement($row);
    }

    public function createDraft(array $input, array $operatorUser, array $operatorStaff, array $scope, string $idempotencyKey): array
    {
        $data = $this->normalizeInput($input, false);
        $this->assertWritableStore($data['store_id'], $scope);
        return $this->write('requirement.create', $idempotencyKey, $data, $operatorUser, $operatorStaff, function () use ($data, $operatorUser, $operatorStaff): array {
            $stmt = $this->pdo->prepare(
                'INSERT INTO recruitment_requirements '
                . '(requirement_no, store_id, position_id, position_name_snapshot, job_description, headcount, target_onboard_date, additional_requirements, created_by, updated_by) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $staffId = $this->staffId($operatorStaff);
            $stmt->execute([
                $this->newRequirementNo(),
                $data['store_id'],
                $data['position_id'],
                $data['position_name_snapshot'],
                $data['job_description'],
                $data['headcount'],
                $data['target_onboard_date'],
                $data['additional_requirements'],
                $staffId,
                $staffId,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $after = $this->getById($id);
            $this->audit($operatorUser, $operatorStaff, 'requirement.create', $id, null, $after);
            return $after;
        });
    }

    public function updateDraft(int $id, array $input, array $operatorUser, array $operatorStaff, array $scope, string $idempotencyKey): array
    {
        $id = $this->positiveId($id, '招聘需求 ID');
        if (!$this->permissionService->canAccessRequirement($scope, $id)) {
            throw new RecruitmentRequirementException('你没有权限修改该招聘需求', 403);
        }
        $data = $this->normalizeInput($input, true);
        $this->assertWritableStore($data['store_id'], $scope, $id);
        return $this->write('requirement.update', $idempotencyKey, ['id' => $id] + $data, $operatorUser, $operatorStaff, function () use ($id, $data, $operatorUser, $operatorStaff): array {
            $before = $this->lockedRequirement($id);
            if (!in_array($before['status'], ['draft', 'returned'], true)) {
                throw new RecruitmentRequirementException('仅草稿或退回状态可编辑', 409);
            }
            $stmt = $this->pdo->prepare(
                'UPDATE recruitment_requirements SET store_id = ?, position_id = ?, position_name_snapshot = ?, job_description = ?, '
                . 'headcount = ?, target_onboard_date = ?, additional_requirements = ?, updated_by = ? WHERE id = ?'
            );
            $stmt->execute([
                $data['store_id'],
                $data['position_id'],
                $data['position_name_snapshot'],
                $data['job_description'],
                $data['headcount'],
                $data['target_onboard_date'],
                $data['additional_requirements'],
                $this->staffId($operatorStaff),
                $id,
            ]);
            $after = $this->getById($id);
            $this->audit($operatorUser, $operatorStaff, 'requirement.update', $id, $before, $after);
            return $after;
        });
    }

    public function transition(int $id, string $action, array $input, array $operatorUser, array $operatorStaff, array $scope, string $idempotencyKey): array
    {
        $id = $this->positiveId($id, '招聘需求 ID');
        if (!$this->permissionService->canAccessRequirement($scope, $id)) {
            throw new RecruitmentRequirementException('你没有权限操作该招聘需求', 403);
        }
        $action = strtolower(trim($action));
        if (!in_array($action, ['submit', 'approve', 'return', 'close', 'reopen'], true)) {
            throw new RecruitmentRequirementException('招聘需求动作无效');
        }
        return $this->write('requirement.' . $action, $idempotencyKey, ['id' => $id, 'action' => $action, 'input' => $input], $operatorUser, $operatorStaff, function () use ($id, $action, $input, $operatorUser, $operatorStaff): array {
            $before = $this->lockedRequirement($id);
            $staffId = $this->staffId($operatorStaff);
            $comment = $this->limitedText($input['comment'] ?? $input['approval_comment'] ?? $input['reason'] ?? '', 1000);
            if ($action === 'submit') {
                if (!in_array($before['status'], ['draft', 'returned'], true)) {
                    throw new RecruitmentRequirementException('仅草稿或退回状态可提交审批', 409);
                }
                $this->updateStatus($id, 'approval_pending', [
                    'submitted_by' => $staffId,
                    'submitted_at' => date('Y-m-d H:i:s'),
                    'status_reason' => $comment,
                ]);
            } elseif ($action === 'approve') {
                if ($before['status'] !== 'approval_pending') {
                    throw new RecruitmentRequirementException('仅待审批需求可批准', 409);
                }
                $this->updateStatus($id, 'approved', [
                    'approved_by' => $staffId,
                    'approved_at' => date('Y-m-d H:i:s'),
                    'approval_comment' => $comment,
                    'status_reason' => $comment,
                ]);
            } elseif ($action === 'return') {
                if ($before['status'] !== 'approval_pending') {
                    throw new RecruitmentRequirementException('仅待审批需求可退回', 409);
                }
                if ($comment === '') {
                    throw new RecruitmentRequirementException('退回需求必须填写原因');
                }
                $this->updateStatus($id, 'returned', [
                    'approval_comment' => $comment,
                    'status_reason' => $comment,
                ]);
            } elseif ($action === 'close') {
                if ($before['status'] === 'closed') {
                    throw new RecruitmentRequirementException('招聘需求已经关闭', 409);
                }
                $this->updateStatus($id, 'closed', [
                    'closed_by' => $staffId,
                    'closed_at' => date('Y-m-d H:i:s'),
                    'status_reason' => $comment,
                ]);
            } else {
                if ($before['status'] !== 'closed') {
                    throw new RecruitmentRequirementException('仅关闭状态可重新开启', 409);
                }
                $this->updateStatus($id, 'draft', [
                    'closed_by' => null,
                    'closed_at' => null,
                    'status_reason' => $comment,
                ]);
            }
            $after = $this->getById($id);
            $this->audit($operatorUser, $operatorStaff, 'requirement.' . $action, $id, $before, $after);
            return $after;
        });
    }

    private function write(string $action, string $key, array $request, array $operatorUser, array $operatorStaff, callable $operation): array
    {
        $this->ensureSchema();
        $key = trim($key);
        if ($key === '' || strlen($key) > 128) {
            throw new RecruitmentRequirementException('写请求必须提供有效的 Idempotency-Key');
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
                    throw new RecruitmentRequirementException('Idempotency-Key 已用于不同请求', 409);
                }
                $response = json_decode((string) ($row['response_json'] ?? ''), true);
                if (!is_array($response)) {
                    throw new RecruitmentRequirementException('同一写请求正在处理中', 409);
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
                throw new RecruitmentRequirementException('招聘需求编号或幂等请求已存在', 409);
            }
            throw $error;
        }
    }

    private function updateStatus(int $id, string $status, array $fields): void
    {
        $assignments = ['status = ?', 'updated_at = NOW()'];
        $params = [$status];
        foreach ($fields as $field => $value) {
            $assignments[] = $field . ' = ?';
            $params[] = $value;
        }
        $params[] = $id;
        $stmt = $this->pdo->prepare('UPDATE recruitment_requirements SET ' . implode(', ', $assignments) . ' WHERE id = ?');
        $stmt->execute($params);
    }

    private function lockedRequirement(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM recruitment_requirements WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RecruitmentRequirementException('招聘需求不存在', 404);
        }
        return $row;
    }

    private function getById(int $id): array
    {
        $stmt = $this->pdo->prepare($this->selectSql() . ' WHERE requirement.id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RecruitmentRequirementException('招聘需求不存在', 404);
        }
        return $this->formatRequirement($row);
    }

    private function selectSql(): string
    {
        return 'SELECT requirement.*, store.name AS store_name, position.position_name AS position_name '
            . 'FROM recruitment_requirements requirement '
            . 'LEFT JOIN stores store ON store.id = requirement.store_id '
            . 'LEFT JOIN organization_positions position ON position.id = requirement.position_id';
    }

    private function formatRequirement(array $row): array
    {
        foreach (['id', 'store_id', 'position_id', 'headcount', 'submitted_by', 'approved_by', 'closed_by', 'created_by', 'updated_by'] as $field) {
            $row[$field] = isset($row[$field]) ? (int) $row[$field] : null;
        }
        $row['status_label'] = $this->statusLabel((string) $row['status']);
        $row['job_title'] = (string) ($row['position_name_snapshot'] ?? $row['position_name'] ?? '');
        return $row;
    }

    private function normalizeInput(array $input, bool $isUpdate): array
    {
        $title = trim((string) ($input['job_title'] ?? $input['position_name_snapshot'] ?? $input['position_name'] ?? ''));
        if ($title === '' || mb_strlen($title, 'UTF-8') > 120) {
            throw new RecruitmentRequirementException('岗位名称必须填写且不能超过 120 个字符');
        }
        $headcount = (int) ($input['headcount'] ?? 1);
        if ($headcount < 1 || $headcount > 999) {
            throw new RecruitmentRequirementException('招聘人数必须在 1 到 999 之间');
        }
        return [
            'store_id' => $this->nullablePositiveId($input['store_id'] ?? null, '门店 ID'),
            'position_id' => $this->nullablePositiveId($input['position_id'] ?? null, '岗位 ID'),
            'position_name_snapshot' => $title,
            'job_description' => $this->limitedText($input['job_description'] ?? '', 20000),
            'headcount' => $headcount,
            'target_onboard_date' => $this->nullableDate($input['target_onboard_date'] ?? null),
            'additional_requirements' => $this->limitedText($input['additional_requirements'] ?? $input['extra_requirements'] ?? '', 10000),
        ];
    }

    private function assertWritableStore(?int $storeId, array $scope, ?int $requirementId = null): void
    {
        if (!empty($scope['can_view_all'])) {
            return;
        }
        if ($requirementId !== null && in_array($requirementId, array_map('intval', $scope['requirement_ids'] ?? []), true)) {
            return;
        }
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $scope['store_ids'] ?? []))));
        if ($storeId !== null && in_array($storeId, $storeIds, true)) {
            return;
        }
        throw new RecruitmentRequirementException('你没有权限为该门店维护招聘需求', 403);
    }

    private function ensureSchema(): void
    {
        foreach (['recruitment_requirements', 'recruitment_idempotency_keys'] as $table) {
            if (!adminTableExists($this->pdo, $table)) {
                throw new RecruitmentRequirementException('招聘数据库迁移尚未执行：' . $table, 500);
            }
        }
    }

    private function audit(array $operatorUser, array $operatorStaff, string $action, int $targetId, ?array $before, array $after): void
    {
        adminRecordOperation($this->pdo, $operatorUser, $operatorStaff, [
            'module' => 'recruitment',
            'action' => $action,
            'target_type' => 'recruitment_requirement',
            'target_id' => (string) $targetId,
            'before' => $before,
            'after' => $after,
        ]);
    }

    private function newRequirementNo(): string
    {
        return 'REQ' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    }

    private function normalizeStatus(string $status): string
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new RecruitmentRequirementException('招聘需求状态无效');
        }
        return $status;
    }

    private function statusLabel(string $status): string
    {
        return [
            'draft' => '草稿',
            'approval_pending' => '待审批',
            'returned' => '已退回',
            'approved' => '已批准',
            'closed' => '已关闭',
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
            throw new RecruitmentRequirementException($label . '无效');
        }
        return $value;
    }

    private function nullableDate($value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new RecruitmentRequirementException('目标到岗日格式无效');
        }
        return $value;
    }

    private function limitedText($value, int $maxLength): string
    {
        $value = trim((string) ($value ?? ''));
        if (mb_strlen($value, 'UTF-8') > $maxLength) {
            throw new RecruitmentRequirementException('输入内容长度超过限制');
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
