<?php

declare(strict_types=1);

require_once __DIR__ . '/WorkloadRoleRuleAdminService.php';

final class WorkloadStandardImportService
{
    private PDO $pdo;
    private WorkloadRoleRuleAdminService $standards;

    public function __construct(PDO $pdo, ?WorkloadRoleRuleAdminService $standards = null)
    {
        $this->pdo = $pdo;
        $this->standards = $standards ?? new WorkloadRoleRuleAdminService($pdo);
    }

    public function preflight(array $records, array $metadata, array $operatorUser, array $operatorStaff, string $idempotencyKey): array
    {
        $idempotencyKey = trim($idempotencyKey);
        $fileSha256 = strtolower(trim((string) ($metadata['file_sha256'] ?? '')));
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128) {
            throw new WorkloadRoleRuleAdminException('写请求必须提供有效的 Idempotency-Key');
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $fileSha256)) {
            throw new WorkloadRoleRuleAdminException('导入文件摘要无效');
        }
        if ($records === [] || count($records) > 10000) {
            throw new WorkloadRoleRuleAdminException('导入数据必须包含 1 至 10000 行');
        }
        $existing = $this->findByRequest($fileSha256, $idempotencyKey);
        if ($existing !== null) return $this->getBatch($existing) + ['idempotent' => true];

        ensureAdminOperationLogsTable($this->pdo);
        $this->pdo->beginTransaction();
        try {
            $batchKey = $this->uuid();
            $emptySummary = json_encode(['roles' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $insert = $this->pdo->prepare(
                "INSERT INTO workload_standard_import_batches "
                . "(batch_key, file_name, file_sha256, idempotency_key, status, summary_json, created_by_staff_id) "
                . "VALUES (?, ?, ?, ?, 'preflight_running', ?, ?)"
            );
            $insert->execute([
                $batchKey,
                mb_substr(basename((string) ($metadata['file_name'] ?? 'standard-import')), 0, 255, 'UTF-8'),
                $fileSha256,
                $idempotencyKey,
                $emptySummary,
                $this->staffId($operatorStaff),
            ]);
            $batchId = (int) $this->pdo->lastInsertId();
            $enabledRoles = $this->enabledRoles();
            $seenCodes = [];
            $roleRows = [];
            $persistRows = [];

            foreach ($records as $index => $raw) {
                $raw = is_array($raw) ? $raw : [];
                $rowNumber = (int) ($raw['_row_number'] ?? ($index + 2));
                $roleCode = strtolower(trim((string) ($raw['role_code'] ?? '')));
                $metricCode = strtolower(trim((string) ($raw['metric_code'] ?? '')));
                $errors = [];
                $item = null;
                if (!isset($enabledRoles[$roleCode])) $errors[] = '岗位不存在或未启用';
                try {
                    $item = $this->standards->normalizeImportedItem($this->normalizeAliases($raw));
                } catch (WorkloadRoleRuleAdminException $error) {
                    $errors[] = $error->getMessage();
                }
                if ($roleCode !== '' && $metricCode !== '') {
                    $key = $roleCode . ':' . $metricCode;
                    if (isset($seenCodes[$key])) $errors[] = '同一岗位的项目编码在导入文件中重复';
                    $seenCodes[$key] = true;
                }
                if ($errors === [] && $item !== null) $roleRows[$roleCode][] = ['row_number' => $rowNumber, 'item' => $item];
                $persistRows[] = [
                    'row_number' => $rowNumber,
                    'role_code' => $roleCode,
                    'metric_code' => $metricCode,
                    'item' => $item ?? $this->safeSummary($raw),
                    'errors' => $errors,
                ];
            }

            $summaries = [];
            $roleErrors = [];
            foreach ($persistRows as $row) {
                if ($row['errors'] !== []) $roleErrors[$row['role_code']] = true;
            }
            $roleCodes = array_values(array_unique(array_merge(array_keys($roleRows), array_keys($roleErrors))));
            sort($roleCodes);
            foreach ($roleCodes as $roleCode) {
                $current = isset($enabledRoles[$roleCode]) ? $this->currentStandard($roleCode) : ['id' => null, 'items' => []];
                $existingItems = [];
                foreach ($current['items'] as $item) $existingItems[$item['metric_code']] = $item;
                $importedItems = [];
                foreach ($roleRows[$roleCode] ?? [] as $row) $importedItems[$row['item']['metric_code']] = $row['item'];
                $details = ['added' => [], 'modified' => [], 'disabled' => [], 'unchanged' => []];
                foreach ($importedItems as $code => $item) {
                    $action = !isset($existingItems[$code]) ? 'added' : ($this->sameItem($existingItems[$code], $item) ? 'unchanged' : 'modified');
                    $details[$action][] = $code;
                }
                $details['disabled'] = array_values(array_diff(array_keys($existingItems), array_keys($importedItems)));
                $errorCount = count(array_filter($persistRows, static fn(array $row): bool => $row['role_code'] === $roleCode && $row['errors'] !== []));
                $summaries[$roleCode] = [
                    'role_code' => $roleCode,
                    'position_name' => $enabledRoles[$roleCode] ?? null,
                    'source_rule_version_id' => $current['id'],
                    'valid_rows' => count($roleRows[$roleCode] ?? []),
                    'error_rows' => $errorCount,
                    'can_confirm' => $errorCount === 0 && $importedItems !== [],
                    'counts' => array_map('count', $details),
                    'details' => $details,
                    'target_rule_version_id' => null,
                ];
            }

            $rowInsert = $this->pdo->prepare(
                'INSERT INTO workload_standard_import_rows '
                . '(batch_id, row_number, role_code, metric_code, field_summary_json, validation_status, difference_action, error_json) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $validRows = 0;
            $errorRows = 0;
            foreach ($persistRows as $row) {
                $action = 'error';
                if ($row['errors'] === []) {
                    $code = $row['metric_code'];
                    foreach (['added', 'modified', 'unchanged'] as $candidate) {
                        if (in_array($code, $summaries[$row['role_code']]['details'][$candidate] ?? [], true)) $action = $candidate;
                    }
                    $validRows++;
                } else {
                    $errorRows++;
                }
                $rowInsert->execute([
                    $batchId, $row['row_number'], $row['role_code'], $row['metric_code'],
                    json_encode($row['item'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    $row['errors'] === [] ? 'valid' : 'error', $action,
                    $row['errors'] === [] ? null : json_encode($row['errors'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]);
            }
            $summary = ['roles' => array_values($summaries), 'totals' => $this->summaryTotals($summaries)];
            $status = $errorRows > 0 ? 'preflight_has_errors' : 'preflight_ready';
            $update = $this->pdo->prepare(
                'UPDATE workload_standard_import_batches SET status = ?, role_count = ?, total_rows = ?, valid_rows = ?, error_rows = ?, summary_json = ? WHERE id = ?'
            );
            $update->execute([
                $status, count($summaries), count($persistRows), $validRows, $errorRows,
                json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $batchId,
            ]);
            adminRecordOperation($this->pdo, $operatorUser, $operatorStaff ?: null, [
                'module' => 'workload_standard',
                'action' => 'standard.import.preflight',
                'target_type' => 'workload_standard_import_batch',
                'target_id' => (string) $batchId,
                'before' => null,
                'after' => ['status' => $status, 'file_sha256' => $fileSha256, 'summary' => $summary],
            ]);
            $this->pdo->commit();
            return $this->getBatch($batchId) + ['idempotent' => false];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if ($this->isDuplicateKeyError($error)) {
                $existing = $this->findByRequest($fileSha256, $idempotencyKey);
                if ($existing !== null) return $this->getBatch($existing) + ['idempotent' => true];
            }
            throw $error;
        }
    }

    public function confirm(int $batchId, array $input, array $operatorUser, array $operatorStaff, string $idempotencyKey): array
    {
        $idempotencyKey = trim($idempotencyKey);
        if ($batchId <= 0) throw new WorkloadRoleRuleAdminException('岗位标准导入批次 ID 无效');
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128) {
            throw new WorkloadRoleRuleAdminException('写请求必须提供有效的 Idempotency-Key');
        }
        $batch = $this->getBatch($batchId);
        if ($batch['status'] === 'published' || (in_array($batch['status'], ['drafts_created', 'partially_published'], true) && empty($input['publish']))) {
            return $batch + ['idempotent' => true];
        }
        $summaryByRole = [];
        foreach ($batch['summary']['roles'] ?? [] as $role) $summaryByRole[$role['role_code']] = $role;
        $blockedRoles = [];
        foreach ($summaryByRole as $roleCode => $role) if (empty($role['can_confirm'])) $blockedRoles[$roleCode] = true;
        $standards = [];
        $minimumByRole = is_array($input['minimum_positive_metrics'] ?? null) ? $input['minimum_positive_metrics'] : [];
        $dailyReportByRole = is_array($input['requires_daily_report'] ?? null) ? $input['requires_daily_report'] : [];
        foreach ($batch['rows'] as $row) {
            if ($row['validation_status'] !== 'valid' || isset($blockedRoles[$row['role_code']])) continue;
            $roleCode = $row['role_code'];
            if (!isset($standards[$roleCode])) {
                $standards[$roleCode] = [
                    'role_code' => $roleCode,
                    'effective_from' => (string) ($input['effective_from'] ?? date('Y-m-d')),
                    'minimum_positive_metrics' => (int) ($minimumByRole[$roleCode] ?? 0),
                    'requires_daily_report' => $dailyReportByRole[$roleCode] ?? true,
                    'description' => '批量导入：' . $batch['file_name'],
                    'source_rule_version_id' => $summaryByRole[$roleCode]['source_rule_version_id'] ?? null,
                    'items' => [],
                ];
            }
            $standards[$roleCode]['items'][] = $row['field_summary'];
        }
        if ($standards === []) throw new WorkloadRoleRuleAdminException('导入批次没有无错误且可确认的岗位', 409);
        $versionByRole = [];
        $created = ['versions' => [], 'idempotent' => true];
        if (in_array($batch['status'], ['drafts_created', 'partially_published'], true)) {
            foreach ($summaryByRole as $roleCode => $role) {
                $versionId = (int) ($role['target_rule_version_id'] ?? 0);
                if ($versionId > 0) {
                    $versionByRole[$roleCode] = $versionId;
                    $created['versions'][] = $this->standards->getStandard($versionId);
                }
            }
        } else {
            $confirmKey = 'standard-import-confirm-' . hash('sha256', $batchId . ':' . $idempotencyKey);
            $created = $this->standards->createImportedDrafts(array_values($standards), $operatorUser, $operatorStaff, $confirmKey);
            foreach ($created['versions'] as $version) $versionByRole[$version['role_code']] = (int) $version['id'];
            $this->storeTargets($batchId, $versionByRole, $operatorStaff);
        }

        $published = [];
        $publishErrors = [];
        if (!empty($input['publish'])) {
            foreach ($versionByRole as $roleCode => $versionId) {
                try {
                    $current = $this->standards->getStandard($versionId);
                    if ($current['stored_status'] !== 'draft') {
                        $published[] = $current;
                        continue;
                    }
                    $published[] = $this->standards->publish(
                        $versionId,
                        ['effective_from' => $standards[$roleCode]['effective_from']],
                        $operatorUser,
                        $operatorStaff,
                        'standard-import-publish-' . hash('sha256', $batchId . ':' . $roleCode . ':' . trim($idempotencyKey))
                    );
                } catch (Throwable $error) {
                    $publishErrors[] = ['role_code' => $roleCode, 'version_id' => $versionId, 'message' => $error->getMessage()];
                }
            }
            $this->setBatchStatus($batchId, $publishErrors === [] ? 'published' : 'partially_published');
        }
        $result = $this->getBatch($batchId);
        $result['created_versions'] = $created['versions'];
        $result['published_versions'] = $published;
        $result['publish_errors'] = $publishErrors;
        $result['idempotent'] = !empty($created['idempotent']);
        return $result;
    }

    public function getBatch(int $batchId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM workload_standard_import_batches WHERE id = ? LIMIT 1');
        $stmt->execute([$batchId]);
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$batch) throw new WorkloadRoleRuleAdminException('岗位标准导入批次不存在', 404);
        $rows = $this->pdo->prepare('SELECT * FROM workload_standard_import_rows WHERE batch_id = ? ORDER BY row_number, id');
        $rows->execute([$batchId]);
        $batch['id'] = (int) $batch['id'];
        foreach (['role_count', 'total_rows', 'valid_rows', 'error_rows'] as $field) $batch[$field] = (int) $batch[$field];
        $batch['summary'] = json_decode((string) $batch['summary_json'], true) ?: ['roles' => []];
        unset($batch['summary_json'], $batch['idempotency_key']);
        $batch['rows'] = array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['batch_id'] = (int) $row['batch_id'];
            $row['row_number'] = (int) $row['row_number'];
            $row['target_rule_version_id'] = $row['target_rule_version_id'] !== null ? (int) $row['target_rule_version_id'] : null;
            $row['field_summary'] = json_decode((string) $row['field_summary_json'], true) ?: [];
            $row['errors'] = json_decode((string) ($row['error_json'] ?? ''), true) ?: [];
            unset($row['field_summary_json'], $row['error_json']);
            return $row;
        }, $rows->fetchAll(PDO::FETCH_ASSOC) ?: []);
        return $batch;
    }

    public function listBatches(array $filters = []): array
    {
        $where = [];
        $params = [];
        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && $status !== 'all') {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        $sql = 'SELECT id, batch_key, file_name, file_sha256, status, role_count, total_rows, valid_rows, error_rows, created_at, updated_at '
            . 'FROM workload_standard_import_batches';
        if ($where !== []) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY id DESC LIMIT 100';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $list = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($list as &$row) foreach (['id', 'role_count', 'total_rows', 'valid_rows', 'error_rows'] as $field) $row[$field] = (int) $row[$field];
        return ['list' => $list, 'total' => count($list)];
    }

    private function normalizeAliases(array $row): array
    {
        foreach (['is_required', 'allow_zero', 'need_evidence'] as $field) {
            $value = trim((string) ($row[$field] ?? ''));
            $row[$field] = match ($value) {
                '是', '需要', '必填', '允许' => 1,
                '否', '不需要', '选填', '禁止' => 0,
                default => $value,
            };
        }
        $row['audit_mode'] = ['无' => 'none', '人工' => 'manual', '凭证' => 'evidence'][$row['audit_mode'] ?? ''] ?? ($row['audit_mode'] ?? 'none');
        $row['statistic_direction'] = [
            '正向' => 'higher', '越高越好' => 'higher', '反向' => 'lower', '越低越好' => 'lower', '中性' => 'neutral',
        ][$row['statistic_direction'] ?? ''] ?? ($row['statistic_direction'] ?? 'higher');
        $valueType = $row['value_type'] ?? '';
        $row['value_type'] = [
            '数值' => 'number', '整数' => 'integer', '小数' => 'decimal', '百分比' => 'percentage', '金额' => 'currency',
        ][$valueType] ?? ($valueType !== '' ? $valueType : 'number');
        return $row;
    }

    private function currentStandard(string $roleCode): array
    {
        $today = date('Y-m-d');
        $stmt = $this->pdo->prepare(
            "SELECT id FROM workload_role_rule_versions WHERE role_code = ? AND status IN ('active', 'scheduled') "
            . 'AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?) ORDER BY effective_from DESC, id DESC LIMIT 1'
        );
        $stmt->execute([$roleCode, $today, $today]);
        $id = (int) ($stmt->fetchColumn() ?: 0);
        return $id > 0 ? ['id' => $id, 'items' => $this->standards->getStandard($id)['items']] : ['id' => null, 'items' => []];
    }

    private function sameItem(array $left, array $right): bool
    {
        foreach (['id'] as $field) unset($left[$field], $right[$field]);
        foreach (['is_required', 'allow_zero', 'need_evidence', 'min_evidence_count', 'max_evidence_count', 'sort_order'] as $field) {
            $left[$field] = (int) ($left[$field] ?? 0);
            $right[$field] = (int) ($right[$field] ?? 0);
        }
        foreach (['min_value', 'max_value', 'target_value'] as $field) {
            $left[$field] = $left[$field] === null ? null : (float) $left[$field];
            $right[$field] = $right[$field] === null ? null : (float) $right[$field];
        }
        ksort($left);
        ksort($right);
        return $left === $right;
    }

    private function enabledRoles(): array
    {
        $rows = $this->pdo->query('SELECT position_code, position_name FROM organization_positions WHERE status = 1')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $result = [];
        foreach ($rows as $row) $result[strtolower((string) $row['position_code'])] = (string) $row['position_name'];
        return $result;
    }

    private function storeTargets(int $batchId, array $versionByRole, array $operatorStaff): void
    {
        $this->pdo->beginTransaction();
        try {
            $updateRows = $this->pdo->prepare('UPDATE workload_standard_import_rows SET target_rule_version_id = ? WHERE batch_id = ? AND role_code = ? AND validation_status = \'valid\'');
            foreach ($versionByRole as $roleCode => $versionId) $updateRows->execute([$versionId, $batchId, $roleCode]);
            $stmt = $this->pdo->prepare('SELECT summary_json FROM workload_standard_import_batches WHERE id = ? FOR UPDATE');
            $stmt->execute([$batchId]);
            $summary = json_decode((string) $stmt->fetchColumn(), true) ?: ['roles' => []];
            foreach ($summary['roles'] as &$role) $role['target_rule_version_id'] = $versionByRole[$role['role_code']] ?? null;
            $update = $this->pdo->prepare("UPDATE workload_standard_import_batches SET status = 'drafts_created', summary_json = ?, confirmed_by_staff_id = ?, confirmed_at = NOW() WHERE id = ?");
            $update->execute([json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $this->staffId($operatorStaff), $batchId]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    private function setBatchStatus(int $batchId, string $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE workload_standard_import_batches SET status = ? WHERE id = ?');
        $stmt->execute([$status, $batchId]);
    }

    private function findByRequest(string $fileSha256, string $idempotencyKey): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM workload_standard_import_batches WHERE file_sha256 = ? AND idempotency_key = ? LIMIT 1');
        $stmt->execute([$fileSha256, $idempotencyKey]);
        $id = (int) ($stmt->fetchColumn() ?: 0);
        return $id > 0 ? $id : null;
    }

    private function summaryTotals(array $summaries): array
    {
        $totals = ['added' => 0, 'modified' => 0, 'disabled' => 0, 'unchanged' => 0, 'error' => 0];
        foreach ($summaries as $summary) {
            foreach (['added', 'modified', 'disabled', 'unchanged'] as $action) $totals[$action] += (int) ($summary['counts'][$action] ?? 0);
            $totals['error'] += (int) ($summary['error_rows'] ?? 0);
        }
        return $totals;
    }

    private function safeSummary(array $raw): array
    {
        $summary = [];
        foreach (['metric_code', 'metric_name', 'unit', 'value_type', 'is_required', 'allow_zero', 'min_value', 'max_value', 'target_value', 'need_evidence', 'min_evidence_count', 'max_evidence_count', 'audit_mode', 'statistic_direction', 'sort_order'] as $field) {
            $summary[$field] = $raw[$field] ?? null;
        }
        return $summary;
    }

    private function staffId(array $staff): ?int
    {
        $id = (int) ($staff['id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private function isDuplicateKeyError(Throwable $error): bool
    {
        return $error instanceof PDOException && ((string) $error->getCode() === '23000' || str_contains($error->getMessage(), 'Duplicate entry'));
    }
}
