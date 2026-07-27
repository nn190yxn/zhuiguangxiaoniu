<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/common.php';
require_once dirname(__DIR__, 2) . '/workload/services/WorkloadAnalyticsCacheService.php';

final class WorkloadRoleRuleAdminException extends RuntimeException
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

final class WorkloadRoleRuleAdminService
{
    private const STATUSES = ['draft', 'scheduled', 'active', 'inactive'];
    private const AUDIT_MODES = ['none', 'manual', 'evidence'];
    private const DIRECTIONS = ['higher', 'lower', 'neutral'];
    private const VALUE_TYPES = ['number', 'integer', 'decimal', 'percentage', 'currency'];

    private PDO $pdo;
    private WorkloadAnalyticsCacheService $cache;

    public function __construct(PDO $pdo, ?WorkloadAnalyticsCacheService $cache = null)
    {
        $this->pdo = $pdo;
        $this->cache = $cache ?? new WorkloadAnalyticsCacheService();
    }

    public function listStandards(array $filters = []): array
    {
        $where = [];
        $params = [];
        $roleCode = trim(strtolower((string) ($filters['role_code'] ?? '')));
        $status = trim(strtolower((string) ($filters['status'] ?? '')));
        if ($roleCode !== '') {
            $where[] = 'version.role_code = ?';
            $params[] = $this->normalizeRoleCode($roleCode);
        }
        if ($status !== '' && $status !== 'all') {
            if (!in_array($status, self::STATUSES, true)) {
                throw new WorkloadRoleRuleAdminException('岗位标准状态无效');
            }
        }
        $sql = $this->versionSelectSql();
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY position.sort_order, version.role_code, version.effective_from DESC, version.id DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $items = array_map([$this, 'formatVersion'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        if ($status !== '' && $status !== 'all') {
            $items = array_values(array_filter($items, static fn(array $item): bool => $item['status'] === $status));
        }
        return ['list' => $items, 'total' => count($items)];
    }

    public function getStandard(int $versionId): array
    {
        $stmt = $this->pdo->prepare($this->versionSelectSql() . ' WHERE version.id = ? LIMIT 1');
        $stmt->execute([$this->positiveId($versionId, '岗位标准版本 ID')]);
        $version = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$version) {
            throw new WorkloadRoleRuleAdminException('岗位标准版本不存在', 404);
        }
        $result = $this->formatVersion($version);
        $result['items'] = $this->itemsForVersion($versionId);
        $sourceVersionId = (int) ($result['source_rule_version_id'] ?? 0);
        if ($sourceVersionId > 0) {
            $result['difference'] = $this->difference($this->itemsForVersion($sourceVersionId), $result['items']);
        }
        return $result;
    }

    public function createDraft(array $input, array $operatorUser, array $operatorStaff, string $idempotencyKey): array
    {
        $data = $this->normalizeVersionInput($input, false);
        return $this->write('standard.create', $idempotencyKey, $data, $operatorUser, $operatorStaff, function () use ($data, $operatorUser, $operatorStaff): array {
            $this->assertEnabledRole($data['role_code']);
            $versionCode = $data['version_code'] !== '' ? $data['version_code'] : $this->newVersionCode($data['role_code']);
            $stmt = $this->pdo->prepare(
                "INSERT INTO workload_role_rule_versions "
                . "(version_code, role_code, template_id, minimum_positive_metrics, requires_daily_report, effective_from, effective_to, status, description, created_by_staff_id) "
                . "VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?)"
            );
            $stmt->execute([
                $versionCode,
                $data['role_code'],
                $data['template_id'],
                $data['minimum_positive_metrics'],
                $data['requires_daily_report'],
                $data['effective_from'],
                $data['effective_to'],
                $data['description'],
                $this->staffId($operatorStaff),
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $after = $this->getStandard($id);
            $this->audit($operatorUser, $operatorStaff, 'standard.create', $id, null, $after);
            return $after;
        });
    }

    public function updateDraft(int $versionId, array $input, array $operatorUser, array $operatorStaff, string $idempotencyKey): array
    {
        $data = $this->normalizeVersionInput($input, true);
        return $this->write('standard.update', $idempotencyKey, ['id' => $versionId] + $data, $operatorUser, $operatorStaff, function () use ($versionId, $data, $operatorUser, $operatorStaff): array {
            $before = $this->lockedDraft($versionId);
            $this->assertEnabledRole($data['role_code']);
            $stmt = $this->pdo->prepare(
                'UPDATE workload_role_rule_versions SET version_code = ?, role_code = ?, template_id = ?, '
                . 'minimum_positive_metrics = ?, requires_daily_report = ?, effective_from = ?, effective_to = ?, description = ? WHERE id = ?'
            );
            $stmt->execute([
                $data['version_code'] !== '' ? $data['version_code'] : $before['version_code'],
                $data['role_code'],
                $data['template_id'],
                $data['minimum_positive_metrics'],
                $data['requires_daily_report'],
                $data['effective_from'],
                $data['effective_to'],
                $data['description'],
                $versionId,
            ]);
            $after = $this->getStandard($versionId);
            $this->audit($operatorUser, $operatorStaff, 'standard.update', $versionId, $before, $after);
            return $after;
        });
    }

    public function mutateItems(int $versionId, string $action, array $input, array $operatorUser, array $operatorStaff, string $idempotencyKey): array
    {
        $action = strtolower(trim($action));
        if (!in_array($action, ['upsert', 'remove', 'reorder'], true)) {
            throw new WorkloadRoleRuleAdminException('岗位标准项目操作无效');
        }
        return $this->write('standard.item.' . $action, $idempotencyKey, ['id' => $versionId, 'input' => $input], $operatorUser, $operatorStaff, function () use ($versionId, $action, $input, $operatorUser, $operatorStaff): array {
            $before = $this->lockedDraft($versionId);
            $before['items'] = $this->itemsForVersion($versionId);
            if ($action === 'upsert') {
                $this->upsertItem($versionId, $input);
            } elseif ($action === 'remove') {
                $this->removeItem($versionId, $input);
            } else {
                $this->reorderItems($versionId, $input);
            }
            $after = $this->getStandard($versionId);
            $this->audit($operatorUser, $operatorStaff, 'standard.item.' . $action, $versionId, $before, $after);
            return $after;
        });
    }

    public function copyToDraft(int $sourceVersionId, array $input, array $operatorUser, array $operatorStaff, string $idempotencyKey): array
    {
        return $this->write('standard.copy', $idempotencyKey, ['source_id' => $sourceVersionId, 'input' => $input], $operatorUser, $operatorStaff, function () use ($sourceVersionId, $input, $operatorUser, $operatorStaff): array {
            $source = $this->lockedVersion($sourceVersionId);
            $roleCode = $this->normalizeRoleCode((string) ($input['role_code'] ?? $source['role_code']));
            $this->assertEnabledRole($roleCode);
            $effectiveFrom = $this->normalizeDate((string) ($input['effective_from'] ?? date('Y-m-d')));
            $effectiveTo = $this->nullableDate($input['effective_to'] ?? null);
            $this->assertDateRange($effectiveFrom, $effectiveTo);
            $versionCode = trim((string) ($input['version_code'] ?? '')) ?: $this->newVersionCode($roleCode);
            $stmt = $this->pdo->prepare(
                "INSERT INTO workload_role_rule_versions "
                . "(version_code, role_code, template_id, source_rule_version_id, minimum_positive_metrics, requires_daily_report, effective_from, effective_to, status, description, created_by_staff_id) "
                . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?)"
            );
            $stmt->execute([
                $versionCode,
                $roleCode,
                $source['template_id'],
                $sourceVersionId,
                $source['minimum_positive_metrics'],
                $source['requires_daily_report'],
                $effectiveFrom,
                $effectiveTo,
                trim((string) ($input['description'] ?? $source['description'])),
                $this->staffId($operatorStaff),
            ]);
            $newId = (int) $this->pdo->lastInsertId();
            $copy = $this->pdo->prepare(
                'INSERT INTO workload_role_metric_rules (rule_version_id, metric_code, metric_name_snapshot, unit_snapshot, value_type_snapshot, '
                . 'is_required, allow_zero, min_value, max_value, need_evidence, min_evidence_count, max_evidence_count, audit_mode, statistic_direction, target_value, sort_order) '
                . 'SELECT ?, metric_code, metric_name_snapshot, unit_snapshot, value_type_snapshot, is_required, allow_zero, min_value, max_value, '
                . 'need_evidence, min_evidence_count, max_evidence_count, audit_mode, statistic_direction, target_value, sort_order '
                . 'FROM workload_role_metric_rules WHERE rule_version_id = ? ORDER BY sort_order, id'
            );
            $copy->execute([$newId, $sourceVersionId]);
            $after = $this->getStandard($newId);
            $this->audit($operatorUser, $operatorStaff, 'standard.copy', $newId, ['source_version_id' => $sourceVersionId], $after);
            return $after;
        });
    }

    public function deleteDraft(int $versionId, array $operatorUser, array $operatorStaff, string $idempotencyKey): array
    {
        return $this->write('standard.delete', $idempotencyKey, ['id' => $versionId], $operatorUser, $operatorStaff, function () use ($versionId, $operatorUser, $operatorStaff): array {
            $before = $this->lockedDraft($versionId);
            $before['items'] = $this->itemsForVersion($versionId);
            if ($this->reportReferenceCount($versionId) > 0) {
                throw new WorkloadRoleRuleAdminException('岗位标准已被日报引用，应通过截止日期停用', 409);
            }
            $stmt = $this->pdo->prepare('DELETE FROM workload_role_metric_rules WHERE rule_version_id = ?');
            $stmt->execute([$versionId]);
            $stmt = $this->pdo->prepare("DELETE FROM workload_role_rule_versions WHERE id = ? AND status = 'draft'");
            $stmt->execute([$versionId]);
            $result = ['id' => $versionId, 'deleted' => true];
            $this->audit($operatorUser, $operatorStaff, 'standard.delete', $versionId, $before, $result);
            return $result;
        });
    }

    public function publish(int $versionId, array $input, array $operatorUser, array $operatorStaff, string $idempotencyKey): array
    {
        return $this->write('standard.publish', $idempotencyKey, ['id' => $versionId, 'input' => $input], $operatorUser, $operatorStaff, function () use ($versionId, $input, $operatorUser, $operatorStaff): array {
            $before = $this->lockedDraft($versionId);
            $items = $this->itemsForVersion($versionId);
            if ($items === []) {
                throw new WorkloadRoleRuleAdminException('岗位标准至少需要一个工作量项目');
            }
            if ((int) $before['minimum_positive_metrics'] > count($items)) {
                throw new WorkloadRoleRuleAdminException('最低正数项目数不能超过标准项目总数');
            }
            $effectiveFrom = $this->normalizeDate((string) ($input['effective_from'] ?? $before['effective_from']));
            $effectiveTo = $this->nullableDate($input['effective_to'] ?? $before['effective_to']);
            $this->assertDateRange($effectiveFrom, $effectiveTo);
            $this->assertEnabledRole((string) $before['role_code']);
            $this->lockRole((string) $before['role_code']);

            $stmt = $this->pdo->prepare(
                "SELECT id, effective_from, effective_to, status FROM workload_role_rule_versions "
                . "WHERE role_code = ? AND id <> ? AND status IN ('active', 'scheduled') FOR UPDATE"
            );
            $stmt->execute([$before['role_code'], $versionId]);
            $published = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $previousIds = [];
            foreach ($published as $existing) {
                $existingFrom = (string) $existing['effective_from'];
                $existingTo = $existing['effective_to'] !== null ? (string) $existing['effective_to'] : null;
                if ($existingFrom >= $effectiveFrom) {
                    if ($effectiveTo !== null && $effectiveTo < $existingFrom) {
                        continue;
                    }
                    throw new WorkloadRoleRuleAdminException('该岗位已存在同日或更晚生效的标准版本', 409, ['conflicting_version_id' => (int) $existing['id']]);
                }
                if ($existingTo === null || $existingTo >= $effectiveFrom) {
                    $previousTo = (new DateTimeImmutable($effectiveFrom))->modify('-1 day')->format('Y-m-d');
                    $update = $this->pdo->prepare('UPDATE workload_role_rule_versions SET effective_to = ? WHERE id = ?');
                    $update->execute([$previousTo, (int) $existing['id']]);
                    $previousIds[] = (int) $existing['id'];
                }
            }
            $today = date('Y-m-d');
            $status = $effectiveFrom > $today ? 'scheduled' : 'active';
            $templateId = $this->synchronizePublishedItems(
                $versionId,
                (string) $before['role_code'],
                isset($before['template_id']) ? (int) $before['template_id'] : null,
                $items,
                (bool) $before['requires_daily_report']
            );
            $update = $this->pdo->prepare(
                'UPDATE workload_role_rule_versions SET template_id = ?, effective_from = ?, effective_to = ?, status = ?, published_by_staff_id = ?, published_at = NOW() WHERE id = ?'
            );
            $update->execute([$templateId, $effectiveFrom, $effectiveTo, $status, $this->staffId($operatorStaff), $versionId]);
            $cacheScope = [
                'role_code' => (string) $before['role_code'],
                'date_from' => $effectiveFrom,
                'date_to' => $effectiveTo ?? '9999-12-31',
            ];
            $invalidated = $this->cache->invalidate($cacheScope);
            $after = $this->getStandard($versionId);
            $result = $after + [
                'replaced_version_ids' => $previousIds,
                'cache_invalidated' => $invalidated,
                'cache_invalidation_scope' => $cacheScope,
            ];
            $this->audit($operatorUser, $operatorStaff, 'standard.publish', $versionId, $before, $result);
            return $result;
        });
    }

    public function disable(int $versionId, array $input, array $operatorUser, array $operatorStaff, string $idempotencyKey): array
    {
        return $this->write('standard.disable', $idempotencyKey, ['id' => $versionId, 'input' => $input], $operatorUser, $operatorStaff, function () use ($versionId, $input, $operatorUser, $operatorStaff): array {
            $before = $this->lockedVersion($versionId);
            if (!in_array($before['status'], ['active', 'scheduled'], true)) {
                throw new WorkloadRoleRuleAdminException('仅已发布标准可以停用', 409);
            }
            $effectiveTo = $this->normalizeDate((string) ($input['effective_to'] ?? date('Y-m-d')));
            $this->assertDateRange((string) $before['effective_from'], $effectiveTo);
            $currentTo = $before['effective_to'] !== null ? (string) $before['effective_to'] : null;
            if ($currentTo !== null && $effectiveTo > $currentTo) {
                throw new WorkloadRoleRuleAdminException('停用操作仅允许缩短现有有效期', 409);
            }
            $siblings = $this->pdo->prepare(
                "SELECT id, effective_from, effective_to FROM workload_role_rule_versions "
                . "WHERE role_code = ? AND id <> ? AND status IN ('active', 'scheduled') FOR UPDATE"
            );
            $siblings->execute([$before['role_code'], $versionId]);
            foreach ($siblings->fetchAll(PDO::FETCH_ASSOC) ?: [] as $sibling) {
                $siblingTo = $sibling['effective_to'] !== null ? (string) $sibling['effective_to'] : null;
                if ((string) $sibling['effective_from'] <= $effectiveTo && ($siblingTo === null || $siblingTo >= (string) $before['effective_from'])) {
                    throw new WorkloadRoleRuleAdminException('截止日期与其他已发布版本区间重叠', 409, ['conflicting_version_id' => (int) $sibling['id']]);
                }
            }
            $status = $effectiveTo < date('Y-m-d') ? 'inactive' : (string) $before['status'];
            $stmt = $this->pdo->prepare('UPDATE workload_role_rule_versions SET effective_to = ?, status = ? WHERE id = ?');
            $stmt->execute([$effectiveTo, $status, $versionId]);
            $cacheScope = [
                'role_code' => (string) $before['role_code'],
                'date_from' => $effectiveTo,
                'date_to' => '9999-12-31',
            ];
            $invalidated = $this->cache->invalidate($cacheScope);
            $after = $this->getStandard($versionId);
            $result = $after + ['cache_invalidated' => $invalidated, 'cache_invalidation_scope' => $cacheScope];
            $this->audit($operatorUser, $operatorStaff, 'standard.disable', $versionId, $before, $result);
            return $result;
        });
    }

    private function upsertItem(int $versionId, array $input): void
    {
        $item = $this->normalizeItem($input);
        $itemId = (int) ($input['item_id'] ?? $input['id'] ?? 0);
        if ($itemId > 0) {
            $owned = $this->pdo->prepare('SELECT 1 FROM workload_role_metric_rules WHERE id = ? AND rule_version_id = ? LIMIT 1');
            $owned->execute([$itemId, $versionId]);
            if (!$owned->fetchColumn()) {
                throw new WorkloadRoleRuleAdminException('岗位标准项目不存在', 404);
            }
            $duplicate = $this->pdo->prepare('SELECT id FROM workload_role_metric_rules WHERE rule_version_id = ? AND metric_code = ? AND id <> ? LIMIT 1');
            $duplicate->execute([$versionId, $item['metric_code'], $itemId]);
            if ($duplicate->fetchColumn()) {
                throw new WorkloadRoleRuleAdminException('同一岗位标准内项目编码不能重复', 409);
            }
            $stmt = $this->pdo->prepare(
                'UPDATE workload_role_metric_rules SET metric_code = ?, metric_name_snapshot = ?, unit_snapshot = ?, value_type_snapshot = ?, '
                . 'is_required = ?, allow_zero = ?, min_value = ?, max_value = ?, need_evidence = ?, min_evidence_count = ?, '
                . 'max_evidence_count = ?, audit_mode = ?, statistic_direction = ?, target_value = ?, sort_order = ? '
                . 'WHERE id = ? AND rule_version_id = ?'
            );
            $stmt->execute(array_merge(array_values($item), [$itemId, $versionId]));
            return;
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO workload_role_metric_rules (rule_version_id, metric_code, metric_name_snapshot, unit_snapshot, value_type_snapshot, '
            . 'is_required, allow_zero, min_value, max_value, need_evidence, min_evidence_count, max_evidence_count, audit_mode, statistic_direction, target_value, sort_order) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute(array_merge([$versionId], array_values($item)));
    }

    private function removeItem(int $versionId, array $input): void
    {
        $itemId = $this->positiveId((int) ($input['item_id'] ?? $input['id'] ?? 0), '岗位标准项目 ID');
        $stmt = $this->pdo->prepare('DELETE FROM workload_role_metric_rules WHERE id = ? AND rule_version_id = ?');
        $stmt->execute([$itemId, $versionId]);
        if ($stmt->rowCount() === 0) {
            throw new WorkloadRoleRuleAdminException('岗位标准项目不存在', 404);
        }
    }

    private function reorderItems(int $versionId, array $input): void
    {
        $items = $input['items'] ?? [];
        if (!is_array($items) || $items === []) {
            throw new WorkloadRoleRuleAdminException('排序项目不能为空');
        }
        $stmt = $this->pdo->prepare('UPDATE workload_role_metric_rules SET sort_order = ? WHERE id = ? AND rule_version_id = ?');
        foreach ($items as $index => $row) {
            if (!is_array($row)) {
                throw new WorkloadRoleRuleAdminException('排序项目格式无效');
            }
            $itemId = $this->positiveId((int) ($row['item_id'] ?? $row['id'] ?? 0), '岗位标准项目 ID');
            $sortOrder = $this->boundedInt($row['sort_order'] ?? (($index + 1) * 10), 0, 1000000, '排序值');
            $owned = $this->pdo->prepare('SELECT 1 FROM workload_role_metric_rules WHERE id = ? AND rule_version_id = ? LIMIT 1');
            $owned->execute([$itemId, $versionId]);
            if (!$owned->fetchColumn()) {
                throw new WorkloadRoleRuleAdminException('排序项目不存在', 404, ['item_id' => $itemId]);
            }
            $stmt->execute([$sortOrder, $itemId, $versionId]);
        }
    }

    private function normalizeVersionInput(array $input, bool $updating): array
    {
        $roleCode = $this->normalizeRoleCode((string) ($input['role_code'] ?? ''));
        $effectiveFrom = $this->normalizeDate((string) ($input['effective_from'] ?? date('Y-m-d')));
        $effectiveTo = $this->nullableDate($input['effective_to'] ?? null);
        $this->assertDateRange($effectiveFrom, $effectiveTo);
        $versionCode = trim((string) ($input['version_code'] ?? ''));
        if ($versionCode !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $versionCode)) {
            throw new WorkloadRoleRuleAdminException('版本编码格式无效');
        }
        $description = trim((string) ($input['description'] ?? ''));
        if (mb_strlen($description, 'UTF-8') > 500) {
            throw new WorkloadRoleRuleAdminException('版本说明不能超过 500 个字符');
        }
        return [
            'version_code' => $versionCode,
            'role_code' => $roleCode,
            'template_id' => isset($input['template_id']) && $input['template_id'] !== '' ? $this->positiveId((int) $input['template_id'], '模板 ID') : null,
            'minimum_positive_metrics' => $this->boundedInt($input['minimum_positive_metrics'] ?? 0, 0, 1000, '最低正数项目数'),
            'requires_daily_report' => $this->booleanInt($input['requires_daily_report'] ?? true),
            'effective_from' => $effectiveFrom,
            'effective_to' => $effectiveTo,
            'description' => $description,
        ];
    }

    private function normalizeItem(array $input): array
    {
        $code = strtolower(trim((string) ($input['metric_code'] ?? '')));
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $code)) {
            throw new WorkloadRoleRuleAdminException('项目编码格式无效');
        }
        $name = trim((string) ($input['metric_name'] ?? ''));
        $unit = trim((string) ($input['unit'] ?? ''));
        if ($name === '' || mb_strlen($name, 'UTF-8') > 100) {
            throw new WorkloadRoleRuleAdminException('项目名称不能为空且不能超过 100 个字符');
        }
        if (mb_strlen($unit, 'UTF-8') > 32) {
            throw new WorkloadRoleRuleAdminException('项目单位不能超过 32 个字符');
        }
        $valueType = strtolower(trim((string) ($input['value_type'] ?? 'number')));
        $auditMode = strtolower(trim((string) ($input['audit_mode'] ?? 'none')));
        $direction = strtolower(trim((string) ($input['statistic_direction'] ?? 'higher')));
        if (!in_array($valueType, self::VALUE_TYPES, true) || !in_array($auditMode, self::AUDIT_MODES, true) || !in_array($direction, self::DIRECTIONS, true)) {
            throw new WorkloadRoleRuleAdminException('项目值类型、审核方式或统计方向无效');
        }
        $min = $this->nullableNumber($input['min_value'] ?? null, '最小值');
        $max = $this->nullableNumber($input['max_value'] ?? null, '最大值');
        if ($min !== null && $max !== null && $min > $max) {
            throw new WorkloadRoleRuleAdminException('项目最小值不能大于最大值');
        }
        $needEvidence = $this->booleanInt($input['need_evidence'] ?? false);
        $minEvidence = $this->boundedInt($input['min_evidence_count'] ?? 0, 0, 10, '最少凭证数');
        $maxEvidence = $this->boundedInt($input['max_evidence_count'] ?? 10, 1, 10, '最多凭证数');
        if ($minEvidence > $maxEvidence || (!$needEvidence && $minEvidence > 0)) {
            throw new WorkloadRoleRuleAdminException('项目凭证数量规则无效');
        }
        return [
            'metric_code' => $code,
            'metric_name_snapshot' => $name,
            'unit_snapshot' => $unit,
            'value_type_snapshot' => $valueType,
            'is_required' => $this->booleanInt($input['is_required'] ?? false),
            'allow_zero' => $this->booleanInt($input['allow_zero'] ?? true),
            'min_value' => $min,
            'max_value' => $max,
            'need_evidence' => $needEvidence,
            'min_evidence_count' => $minEvidence,
            'max_evidence_count' => $maxEvidence,
            'audit_mode' => $auditMode,
            'statistic_direction' => $direction,
            'target_value' => $this->nullableNumber($input['target_value'] ?? null, '目标值'),
            'sort_order' => $this->boundedInt($input['sort_order'] ?? 0, 0, 1000000, '排序值'),
        ];
    }

    private function write(string $action, string $key, array $request, array $operatorUser, array $operatorStaff, callable $operation): array
    {
        $key = trim($key);
        if ($key === '' || strlen($key) > 128) {
            throw new WorkloadRoleRuleAdminException('写请求必须提供有效的 Idempotency-Key');
        }
        $hash = hash('sha256', json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        ensureAdminOperationLogsTable($this->pdo);
        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare(
                'INSERT IGNORE INTO workload_standard_idempotency_keys (idempotency_key, action, request_hash, operator_staff_id) VALUES (?, ?, ?, ?)'
            );
            $insert->execute([$key, $action, $hash, $this->staffId($operatorStaff)]);
            $ownsKey = $insert->rowCount() === 1;
            if (!$ownsKey) {
                $existing = $this->pdo->prepare('SELECT request_hash, response_json FROM workload_standard_idempotency_keys WHERE idempotency_key = ? AND action = ? FOR UPDATE');
                $existing->execute([$key, $action]);
                $row = $existing->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    throw new WorkloadRoleRuleAdminException('幂等请求状态不可用', 409);
                }
                if (!hash_equals((string) $row['request_hash'], $hash)) {
                    throw new WorkloadRoleRuleAdminException('Idempotency-Key 已用于不同请求', 409);
                }
                $response = json_decode((string) ($row['response_json'] ?? ''), true);
                if (!is_array($response)) {
                    throw new WorkloadRoleRuleAdminException('同一写请求正在处理中', 409);
                }
                $this->pdo->commit();
                return $response + ['idempotent' => true];
            }
            $result = $operation();
            $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $update = $this->pdo->prepare('UPDATE workload_standard_idempotency_keys SET response_json = ? WHERE idempotency_key = ? AND action = ?');
            $update->execute([$encoded, $key, $action]);
            $this->pdo->commit();
            return $result + ['idempotent' => false];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($this->isDuplicateKeyError($error)) {
                throw new WorkloadRoleRuleAdminException('版本编码或项目编码已存在', 409);
            }
            throw $error;
        }
    }

    private function lockedDraft(int $versionId): array
    {
        $version = $this->lockedVersion($versionId);
        if ($version['status'] !== 'draft') {
            throw new WorkloadRoleRuleAdminException('已发布岗位标准保持只读，请复制为新草稿后修改', 409);
        }
        return $version;
    }

    private function lockedVersion(int $versionId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM workload_role_rule_versions WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$this->positiveId($versionId, '岗位标准版本 ID')]);
        $version = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$version) {
            throw new WorkloadRoleRuleAdminException('岗位标准版本不存在', 404);
        }
        return $version;
    }

    private function itemsForVersion(int $versionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, metric_code, metric_name_snapshot AS metric_name, unit_snapshot AS unit, value_type_snapshot AS value_type, '
            . 'is_required, allow_zero, min_value, max_value, need_evidence, min_evidence_count, max_evidence_count, '
            . 'audit_mode, statistic_direction, target_value, sort_order FROM workload_role_metric_rules '
            . 'WHERE rule_version_id = ? ORDER BY sort_order, id'
        );
        $stmt->execute([$versionId]);
        return array_map(static function (array $row): array {
            foreach (['id', 'is_required', 'allow_zero', 'need_evidence', 'min_evidence_count', 'max_evidence_count', 'sort_order'] as $field) {
                $row[$field] = (int) $row[$field];
            }
            foreach (['min_value', 'max_value', 'target_value'] as $field) {
                $row[$field] = $row[$field] !== null ? (float) $row[$field] : null;
            }
            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private function versionSelectSql(): string
    {
        return 'SELECT version.*, position.position_name, position.status AS position_status, '
            . '(SELECT COUNT(*) FROM workload_role_metric_rules item WHERE item.rule_version_id = version.id) AS item_count, '
            . '(SELECT COUNT(*) FROM workload_daily_reports report WHERE report.rule_version_id = version.id) AS report_reference_count '
            . 'FROM workload_role_rule_versions version '
            . 'LEFT JOIN organization_positions position ON position.position_code = version.role_code';
    }

    private function formatVersion(array $row): array
    {
        foreach (['id', 'minimum_positive_metrics', 'requires_daily_report', 'item_count', 'report_reference_count'] as $field) {
            $row[$field] = (int) ($row[$field] ?? 0);
        }
        foreach (['template_id', 'source_rule_version_id', 'created_by_staff_id', 'published_by_staff_id'] as $field) {
            $row[$field] = isset($row[$field]) ? (int) $row[$field] : null;
        }
        $row['requires_daily_report'] = $row['requires_daily_report'] === 1;
        $row['position_enabled'] = (int) ($row['position_status'] ?? 0) === 1;
        $row['stored_status'] = (string) ($row['status'] ?? 'draft');
        $row['status'] = $this->effectiveStatus($row);
        unset($row['position_status']);
        return $row;
    }

    private function assertEnabledRole(string $roleCode): void
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM organization_positions WHERE position_code = ? AND status = 1 LIMIT 1');
        $stmt->execute([$roleCode]);
        if (!$stmt->fetchColumn()) {
            throw new WorkloadRoleRuleAdminException('岗位不存在或未启用');
        }
    }

    private function lockRole(string $roleCode): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM organization_positions WHERE position_code = ? AND status = 1 LIMIT 1 FOR UPDATE');
        $stmt->execute([$roleCode]);
        if (!$stmt->fetchColumn()) {
            throw new WorkloadRoleRuleAdminException('岗位不存在或未启用');
        }
    }

    private function synchronizePublishedItems(int $versionId, string $roleCode, ?int $sourceTemplateId, array $items, bool $requiresDailyReport): ?int
    {
        if (!$requiresDailyReport && $sourceTemplateId === null) {
            return null;
        }
        if ($sourceTemplateId !== null && $sourceTemplateId > 0) {
            $template = $this->pdo->prepare('SELECT id FROM workload_templates WHERE id = ? AND role_code = ? AND is_active = 1 LIMIT 1 FOR UPDATE');
            $template->execute([$sourceTemplateId, $roleCode]);
            if (!$template->fetchColumn()) {
                throw new WorkloadRoleRuleAdminException('日报模板不存在、未启用或岗位不匹配');
            }
        }

        $templates = $this->pdo->prepare('SELECT version_no FROM workload_templates WHERE role_code = ? FOR UPDATE');
        $templates->execute([$roleCode]);
        $versionNo = 1;
        foreach ($templates->fetchAll(PDO::FETCH_COLUMN) ?: [] as $existingVersionNo) {
            $versionNo = max($versionNo, (int) $existingVersionNo + 1);
        }
        $templateCode = substr($roleCode, 0, 30) . '-standard-' . $versionId;
        $createTemplate = $this->pdo->prepare(
            'INSERT INTO workload_templates (template_code, template_name, role_code, is_active, version_no) VALUES (?, ?, ?, 1, ?)'
        );
        $createTemplate->execute([$templateCode, $roleCode . ' 岗位标准 ' . $versionId, $roleCode, $versionNo]);
        $templateId = (int) $this->pdo->lastInsertId();

        $metricIds = [];
        foreach ($items as $item) {
            $existing = $this->pdo->prepare('SELECT id, role_code FROM metric_definitions WHERE metric_code = ? LIMIT 1 FOR UPDATE');
            $existing->execute([$item['metric_code']]);
            $metric = $existing->fetch(PDO::FETCH_ASSOC);
            if ($metric && (string) $metric['role_code'] !== $roleCode) {
                throw new WorkloadRoleRuleAdminException('项目编码已被其他岗位使用：' . $item['metric_code'], 409);
            }
            if ($metric) {
                $metricId = (int) $metric['id'];
                $update = $this->pdo->prepare(
                    "UPDATE metric_definitions SET metric_name = ?, metric_group = 'daily_input', metric_category = 'custom', unit = ?, "
                    . 'value_type = ?, is_required = ?, is_system_calculated = 0, is_active = 1, min_value = ?, max_value = ?, sort_order = ? WHERE id = ?'
                );
                $update->execute([
                    $item['metric_name'], $item['unit'], $item['value_type'], $item['is_required'],
                    $item['min_value'], $item['max_value'], $item['sort_order'], $metricId,
                ]);
            } else {
                $create = $this->pdo->prepare(
                    "INSERT INTO metric_definitions (metric_code, metric_name, role_code, metric_group, metric_category, unit, value_type, "
                    . "is_required, is_system_calculated, is_active, default_value, min_value, max_value, sort_order, description) "
                    . "VALUES (?, ?, ?, 'daily_input', 'custom', ?, ?, ?, 0, 1, 0, ?, ?, ?, '')"
                );
                $create->execute([
                    $item['metric_code'], $item['metric_name'], $roleCode, $item['unit'], $item['value_type'],
                    $item['is_required'], $item['min_value'], $item['max_value'], $item['sort_order'],
                ]);
                $metricId = (int) $this->pdo->lastInsertId();
            }
            $metricIds[] = $metricId;
        }

        $hide = $this->pdo->prepare('UPDATE workload_template_items SET is_visible = 0 WHERE template_id = ?');
        $hide->execute([$templateId]);
        $link = $this->pdo->prepare(
            'INSERT INTO workload_template_items (template_id, metric_id, is_visible, is_editable, sort_order) VALUES (?, ?, 1, 1, ?) '
            . 'ON DUPLICATE KEY UPDATE is_visible = 1, is_editable = 1, sort_order = VALUES(sort_order)'
        );
        foreach ($items as $index => $item) {
            $link->execute([$templateId, $metricIds[$index], $item['sort_order']]);
        }
        return $templateId;
    }

    private function difference(array $sourceItems, array $draftItems): array
    {
        $source = [];
        $draft = [];
        foreach ($sourceItems as $item) $source[$item['metric_code']] = $item;
        foreach ($draftItems as $item) $draft[$item['metric_code']] = $item;
        $added = array_values(array_diff(array_keys($draft), array_keys($source)));
        $removed = array_values(array_diff(array_keys($source), array_keys($draft)));
        $modified = [];
        foreach (array_intersect(array_keys($source), array_keys($draft)) as $code) {
            $left = $source[$code];
            $right = $draft[$code];
            unset($left['id'], $right['id']);
            if ($left !== $right) $modified[] = $code;
        }
        return ['added' => $added, 'modified' => $modified, 'removed' => $removed];
    }

    private function effectiveStatus(array $row): string
    {
        $stored = (string) ($row['status'] ?? 'draft');
        if ($stored === 'draft') return 'draft';
        $today = date('Y-m-d');
        if ((string) ($row['effective_from'] ?? '') > $today) return 'scheduled';
        $effectiveTo = $row['effective_to'] ?? null;
        return $effectiveTo !== null && (string) $effectiveTo < $today ? 'inactive' : 'active';
    }

    private function reportReferenceCount(int $versionId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM workload_daily_reports WHERE rule_version_id = ?');
        $stmt->execute([$versionId]);
        return (int) $stmt->fetchColumn();
    }

    private function audit(array $user, array $staff, string $action, int $id, mixed $before, mixed $after): void
    {
        adminRecordOperation($this->pdo, $user, $staff ?: null, [
            'module' => 'workload_standard',
            'action' => $action,
            'target_type' => 'workload_role_rule_version',
            'target_id' => (string) $id,
            'before' => $before,
            'after' => $after,
        ]);
    }

    private function normalizeRoleCode(string $roleCode): string
    {
        $roleCode = strtolower(trim($roleCode));
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $roleCode)) {
            throw new WorkloadRoleRuleAdminException('岗位编码格式无效');
        }
        return $roleCode;
    }

    private function normalizeDate(string $date): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            throw new WorkloadRoleRuleAdminException('日期格式必须为 YYYY-MM-DD');
        }
        return $date;
    }

    private function nullableDate(mixed $date): ?string
    {
        return $date === null || trim((string) $date) === '' ? null : $this->normalizeDate(trim((string) $date));
    }

    private function assertDateRange(string $from, ?string $to): void
    {
        if ($to !== null && $to < $from) {
            throw new WorkloadRoleRuleAdminException('截止日期不能早于生效日期');
        }
    }

    private function positiveId(int $id, string $label): int
    {
        if ($id <= 0) {
            throw new WorkloadRoleRuleAdminException($label . ' 无效');
        }
        return $id;
    }

    private function boundedInt(mixed $value, int $min, int $max, string $label): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new WorkloadRoleRuleAdminException($label . ' 必须为整数');
        }
        $value = (int) $value;
        if ($value < $min || $value > $max) {
            throw new WorkloadRoleRuleAdminException(sprintf('%s必须在 %d 至 %d 之间', $label, $min, $max));
        }
        return $value;
    }

    private function nullableNumber(mixed $value, string $label): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value) || !is_finite((float) $value)) {
            throw new WorkloadRoleRuleAdminException($label . ' 必须为有限数字');
        }
        return (float) $value;
    }

    private function booleanInt(mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (in_array($value, [1, '1', 'true'], true)) {
            return 1;
        }
        if (in_array($value, [0, '0', 'false'], true)) {
            return 0;
        }
        throw new WorkloadRoleRuleAdminException('布尔字段值无效');
    }

    private function staffId(array $staff): ?int
    {
        $id = (int) ($staff['id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    private function newVersionCode(string $roleCode): string
    {
        return sprintf('%s-%s-%s', substr($roleCode, 0, 36), gmdate('YmdHis'), bin2hex(random_bytes(3)));
    }

    private function isDuplicateKeyError(Throwable $error): bool
    {
        return $error instanceof PDOException && ((string) $error->getCode() === '23000' || str_contains($error->getMessage(), 'Duplicate entry'));
    }
}
