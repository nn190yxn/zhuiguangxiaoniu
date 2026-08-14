<?php
declare(strict_types=1);

require_once __DIR__ . '/WorkloadSourcePolicyService.php';
require_once __DIR__ . '/WorkloadEffectiveValueService.php';
require_once __DIR__ . '/WorkloadMetricVersionService.php';
require_once __DIR__ . '/WorkloadPermissionScopeService.php';
require_once __DIR__ . '/WorkloadAnalyticsCacheService.php';

final class WorkloadAnalyticsQueryException extends RuntimeException {
    private int $statusCode;

    public function __construct(string $message, int $statusCode = 400) {
        parent::__construct($message);
        $this->statusCode = $statusCode;
    }

    public function statusCode(): int {
        return $this->statusCode;
    }
}

final class WorkloadAnalyticsQueryService {
    private const MINIMUM_SUBMITTED_REPORTS = 10;
    private const MINIMUM_SUBMITTED_STAFF = 3;
    private const REPORT_STATUSES = ['draft', 'submitted'];
    private const AUDIT_STATUSES = ['not_required', 'missing', 'pending', 'approved', 'rejected', 'needs_resubmit'];

    private PDO $pdo;
    private WorkloadSourcePolicyService $sourcePolicy;
    private WorkloadMetricVersionService $metricVersion;
    private WorkloadPermissionScopeService $permissionScopeService;
    private WorkloadAnalyticsCacheService $cache;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->sourcePolicy = new WorkloadSourcePolicyService($this->pdo);
        $this->metricVersion = new WorkloadMetricVersionService($this->pdo);
        $this->permissionScopeService = new WorkloadPermissionScopeService($this->pdo);
        $this->cache = new WorkloadAnalyticsCacheService();
    }

    public function normalizeFilters(array $input): array {
        $singleDate = trim((string) ($input['date'] ?? ''));
        $dateFromInput = trim((string) ($input['date_from'] ?? ''));
        $dateToInput = trim((string) ($input['date_to'] ?? ''));
        $dateFrom = $this->normalizeDate($dateFromInput ?: ($singleDate ?: date('Y-m-d')), '开始日期');
        $dateTo = $this->normalizeDate($dateToInput ?: ($singleDate ?: $dateFrom), '结束日期');
        if ($dateFrom > $dateTo) {
            throw new WorkloadAnalyticsQueryException('开始日期不能晚于结束日期');
        }
        $days = (new DateTimeImmutable($dateFrom))->diff(new DateTimeImmutable($dateTo))->days + 1;
        if ($days > 366) {
            throw new WorkloadAnalyticsQueryException('日期范围不能超过 366 天');
        }

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'store_ids' => $this->normalizeIntegerList($input['store_ids'] ?? $input['store_id'] ?? [], '门店'),
            'role_codes' => $this->normalizeCodeList(
                $input['role_codes'] ?? $input['role_code'] ?? $input['role'] ?? [],
                '岗位编码',
                32
            ),
            'staff_ids' => $this->normalizeIntegerList($input['staff_ids'] ?? $input['staff_id'] ?? [], '员工'),
            'metric_codes' => $this->normalizeCodeList(
                $input['metric_codes'] ?? $input['metric_code'] ?? $input['project_code'] ?? $input['project'] ?? [],
                '指标编码',
                64
            ),
            'report_statuses' => $this->normalizeEnumList(
                $input['report_statuses'] ?? $input['report_status'] ?? $input['submit_status'] ?? [],
                self::REPORT_STATUSES,
                '日报状态'
            ),
            'audit_statuses' => $this->normalizeEnumList(
                $input['audit_statuses'] ?? $input['audit_status'] ?? [],
                self::AUDIT_STATUSES,
                '审核状态'
            ),
            'sources' => $this->normalizeSources($input['sources'] ?? $input['source'] ?? []),
        ];
    }

    public function permissionScope(array $context): array {
        try {
            return $this->permissionScopeService->resolve($context);
        } catch (WorkloadPermissionScopeException $error) {
            throw new WorkloadAnalyticsQueryException($error->getMessage(), $error->statusCode());
        }
    }

    public function buildFactQuery(array $filters, array $permissionScope): array {
        $valueExpressions = WorkloadEffectiveValueService::sqlExpressions();
        $auditStatusExpression = "CASE "
            . "WHEN COALESCE(version_rules.audit_mode, rules.audit_mode, 'none') <> 'full' THEN 'not_required' "
            . "WHEN t.id IS NULL THEN 'missing' ELSE t.audit_status END";
        $where = ['r.report_date BETWEEN ? AND ?', 'm.is_active = 1'];
        $params = [$filters['date_from'], $filters['date_to']];

        $this->appendInCondition($where, $params, 'r.store_id', $filters['store_ids']);
        $this->appendInCondition($where, $params, 'r.role_code', $filters['role_codes']);
        $this->appendInCondition($where, $params, 'r.staff_id', $filters['staff_ids']);
        $this->appendInCondition($where, $params, 'm.metric_code', $filters['metric_codes']);
        $this->appendInCondition($where, $params, 'r.submit_status', $filters['report_statuses']);
        $this->appendInCondition($where, $params, $auditStatusExpression, $filters['audit_statuses']);
        $this->appendInCondition($where, $params, 'r.source', $filters['sources']);

        if (($permissionScope['scope_type'] ?? '') === 'stores') {
            $this->appendInCondition($where, $params, 'r.store_id', $permissionScope['store_ids'] ?? []);
        } elseif (($permissionScope['scope_type'] ?? '') === 'staff') {
            $where[] = 'r.staff_id = ?';
            $params[] = (int) ($permissionScope['staff_id'] ?? 0);
        } elseif (($permissionScope['scope_type'] ?? '') !== 'all') {
            throw new WorkloadAnalyticsQueryException('工作量查询权限范围无效', 403);
        }

        $sql = "SELECT r.id AS report_id, r.report_date AS business_date, r.store_id, "
            . "st.name AS store_name, r.staff_id, s.name AS staff_name, r.role_code, "
            . "m.metric_code, m.metric_name, m.unit, r.submit_status AS report_status, "
            . "$auditStatusExpression AS audit_status, r.source, "
             . "COALESCE(evidence_summary.evidence_count, 0) AS evidence_count, "
             . "{$valueExpressions['raw_value']}, {$valueExpressions['pending_value']}, "
            . "{$valueExpressions['effective_value']}, {$valueExpressions['rejected_value']}, "
            . "settlement.id AS settlement_id, settlement.target_points AS daily_target_points, "
            . "settlement.reported_points AS daily_reported_points, settlement.pending_points AS daily_pending_points, "
            . "settlement.effective_points AS daily_effective_points, settlement.rejected_points AS daily_rejected_points, "
            . "settlement.gap_points AS daily_gap_points, settlement.settlement_status, settlement.makeup_deadline_at, "
            . "penalty.id AS penalty_id, penalty.penalty_amount, penalty.status AS penalty_status "
            . "FROM workload_daily_reports r "
            . "JOIN workload_daily_report_values v ON v.report_id = r.id "
            . "JOIN metric_definitions m ON m.id = v.metric_id "
            . "LEFT JOIN staffs s ON s.id = r.staff_id "
             . "LEFT JOIN stores st ON st.id = r.store_id "
            . "LEFT JOIN workload_daily_settlements settlement ON settlement.business_date = r.report_date "
            . "AND settlement.store_id = r.store_id AND settlement.staff_id = r.staff_id "
            . "AND settlement.role_code = r.role_code "
            . "LEFT JOIN workload_penalty_records penalty ON penalty.settlement_id = settlement.id "
            . "LEFT JOIN workload_role_metric_rules version_rules ON version_rules.rule_version_id = r.rule_version_id "
            . "AND version_rules.metric_code = m.metric_code "
            . "LEFT JOIN workload_metric_rules rules ON rules.role_code = r.role_code "
            . "AND rules.metric_code = m.metric_code AND rules.enabled = 1 "
            . "LEFT JOIN workload_audit_tasks t ON t.id = (SELECT current_task.id FROM workload_audit_tasks current_task "
            . "WHERE current_task.report_id = r.id AND current_task.metric_code = m.metric_code "
            . "AND current_task.superseded_at IS NULL AND current_task.audit_status <> 'superseded' "
            . "ORDER BY current_task.task_version DESC, current_task.id DESC LIMIT 1) "
            . "LEFT JOIN (SELECT evidence.report_id, evidence.metric_code, COUNT(*) AS evidence_count "
            . "FROM workload_evidences evidence WHERE evidence.deleted_at IS NULL "
            . "GROUP BY evidence.report_id, evidence.metric_code) evidence_summary "
            . "ON evidence_summary.report_id = r.id AND evidence_summary.metric_code = m.metric_code "
            . 'WHERE ' . implode(' AND ', $where) . ' '
            . 'ORDER BY r.report_date, r.store_id, r.staff_id, r.role_code, m.metric_code';

        return [$sql, $params];
    }

    public function facts(array $input, array $context): array {
        $filters = $this->normalizeFilters($input);
        $permissionScope = $this->permissionScope($context);
        $metricVersion = $this->metricVersion->current()['version_code'];
        $cacheKey = $this->cache->key('facts', $filters, $permissionScope, $metricVersion);
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
        [$sql, $params] = $this->buildFactQuery($filters, $permissionScope);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            foreach (['report_id', 'store_id', 'staff_id', 'evidence_count', 'settlement_id', 'penalty_id'] as $field) {
                $row[$field] = (int) ($row[$field] ?? 0);
            }
            foreach (['raw_value', 'pending_value', 'effective_value', 'rejected_value', 'daily_target_points',
                'daily_reported_points', 'daily_pending_points', 'daily_effective_points', 'daily_rejected_points',
                'daily_gap_points', 'penalty_amount'] as $field) {
                $row[$field] = round((float) ($row[$field] ?? 0), 2);
            }
        }
        unset($row);
        $result = ['filters' => $filters, 'permission_scope' => $permissionScope, 'rows' => $rows];
        $this->cache->put(
            $cacheKey,
            $result,
            $this->cache->dependencies($filters, $permissionScope, $metricVersion)
        );
        return $result;
    }

    public function countFacts(array $filters, array $permissionScope): int {
        [$sql, $params] = $this->buildFactQuery($filters, $permissionScope);
        $sql = preg_replace('/\s+ORDER BY\s+r\.report_date[\s\S]*$/', '', $sql) ?: $sql;
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM (' . $sql . ') workload_fact_count');
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function iterateFacts(array $filters, array $permissionScope): iterable {
        [$sql, $params] = $this->buildFactQuery($filters, $permissionScope);
        $restoreBuffered = null;
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' && defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
            $restoreBuffered = (bool) $this->pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
            $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        }
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                yield $this->normalizeFactRow($row);
            }
            $stmt->closeCursor();
        } finally {
            if ($restoreBuffered !== null) {
                $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $restoreBuffered);
            }
        }
    }

    private function normalizeFactRow(array $row): array {
        foreach (['report_id', 'store_id', 'staff_id', 'evidence_count', 'settlement_id', 'penalty_id'] as $field) {
            $row[$field] = (int) ($row[$field] ?? 0);
        }
        foreach (['raw_value', 'pending_value', 'effective_value', 'rejected_value', 'daily_target_points',
            'daily_reported_points', 'daily_pending_points', 'daily_effective_points', 'daily_rejected_points',
            'daily_gap_points', 'penalty_amount'] as $field) {
            $row[$field] = round((float) ($row[$field] ?? 0), 2);
        }
        return $row;
    }

    public function aggregateByMetric(array $rows, int $requiredObligationDays = 0): array {
        if ($requiredObligationDays < 0) {
            throw new WorkloadAnalyticsQueryException('应交人日不能为负数');
        }
        $groups = [];
        foreach ($rows as $row) {
            if (($row['report_status'] ?? '') !== 'submitted') {
                continue;
            }
            $metricCode = trim((string) ($row['metric_code'] ?? ''));
            $reportId = (int) ($row['report_id'] ?? 0);
            if ($metricCode === '' || $reportId <= 0) {
                continue;
            }
            if (!isset($groups[$metricCode])) {
                $groups[$metricCode] = [
                    'metric_code' => $metricCode,
                    'metric_name' => (string) ($row['metric_name'] ?? ''),
                    'unit' => (string) ($row['unit'] ?? ''),
                    'required_obligation_days' => $requiredObligationDays,
                    'submitted_report_count' => 0,
                    'positive_raw_report_count' => 0,
                    'positive_effective_report_count' => 0,
                    'zero_raw_report_count' => 0,
                    'raw_value' => 0.0,
                    'pending_value' => 0.0,
                    'effective_value' => 0.0,
                    'rejected_value' => 0.0,
                    '_report_ids' => [],
                    '_submitted_staff_ids' => [],
                    '_positive_staff_ids' => [],
                    '_submitted_store_ids' => [],
                    '_positive_store_ids' => [],
                ];
            }
            if (isset($groups[$metricCode]['_report_ids'][$reportId])) {
                continue;
            }
            $groups[$metricCode]['_report_ids'][$reportId] = true;
            $groups[$metricCode]['submitted_report_count']++;

            $staffId = (int) ($row['staff_id'] ?? 0);
            $storeId = (int) ($row['store_id'] ?? 0);
            if ($staffId > 0) {
                $groups[$metricCode]['_submitted_staff_ids'][$staffId] = true;
            }
            if ($storeId > 0) {
                $groups[$metricCode]['_submitted_store_ids'][$storeId] = true;
            }

            $rawValue = round((float) ($row['raw_value'] ?? 0), 2);
            $effectiveValue = round((float) ($row['effective_value'] ?? 0), 2);
            if ($rawValue > 0) {
                $groups[$metricCode]['positive_raw_report_count']++;
                if ($staffId > 0) {
                    $groups[$metricCode]['_positive_staff_ids'][$staffId] = true;
                }
                if ($storeId > 0) {
                    $groups[$metricCode]['_positive_store_ids'][$storeId] = true;
                }
            } elseif ($rawValue === 0.0) {
                $groups[$metricCode]['zero_raw_report_count']++;
            }
            if ($effectiveValue > 0) {
                $groups[$metricCode]['positive_effective_report_count']++;
            }
            foreach (['raw_value', 'pending_value', 'effective_value', 'rejected_value'] as $field) {
                $groups[$metricCode][$field] += round((float) ($row[$field] ?? 0), 2);
            }
        }

        $result = [];
        foreach ($groups as $group) {
            $submittedStaffCount = count($group['_submitted_staff_ids']);
            $positiveStaffCount = count($group['_positive_staff_ids']);
            $submittedStoreCount = count($group['_submitted_store_ids']);
            $positiveStoreCount = count($group['_positive_store_ids']);
            $submittedReportCount = $group['submitted_report_count'];
            foreach (['raw_value', 'pending_value', 'effective_value', 'rejected_value'] as $field) {
                $group[$field] = round($group[$field], 2);
            }
            $group['sample_size'] = $submittedReportCount;
            $group['submitted_staff_count'] = $submittedStaffCount;
            $group['positive_staff_count'] = $positiveStaffCount;
            $group['submitted_store_count'] = $submittedStoreCount;
            $group['positive_store_count'] = $positiveStoreCount;
            $group['low_sample'] = $submittedReportCount < self::MINIMUM_SUBMITTED_REPORTS
                || $submittedStaffCount < self::MINIMUM_SUBMITTED_STAFF;
            $group['sample_thresholds'] = [
                'submitted_reports' => self::MINIMUM_SUBMITTED_REPORTS,
                'submitted_staff' => self::MINIMUM_SUBMITTED_STAFF,
            ];
            $group['selection_rate'] = $this->ratio($group['positive_raw_report_count'], $submittedReportCount);
            $group['effective_selection_rate'] = $this->ratio(
                $group['positive_effective_report_count'],
                $submittedReportCount
            );
            $group['zero_rate'] = $this->ratio($group['zero_raw_report_count'], $submittedReportCount);
            $group['staff_coverage'] = $this->ratio($positiveStaffCount, $submittedStaffCount);
            $group['store_coverage'] = $this->ratio($positiveStoreCount, $submittedStoreCount);
            $group['all_staff_average'] = $this->average($group['effective_value'], $submittedStaffCount);
            $group['participant_staff_average'] = $this->average($group['effective_value'], $positiveStaffCount);
            $group['per_obligation_day_average'] = $this->average(
                $group['effective_value'],
                $requiredObligationDays
            );
            unset(
                $group['_report_ids'],
                $group['_submitted_staff_ids'],
                $group['_positive_staff_ids'],
                $group['_submitted_store_ids'],
                $group['_positive_store_ids']
            );
            $result[] = $group;
        }
        usort($result, static fn(array $left, array $right): int => $left['metric_code'] <=> $right['metric_code']);
        return $result;
    }

    public function statistics(array $input, array $context, int $requiredObligationDays = 0): array {
        $facts = $this->facts($input, $context);
        $metadata = $this->metricVersion->responseMetadata($facts['filters'], $facts['filters']['sources']);
        return array_merge($metadata, [
            'data_cutoff_at' => $metadata['generated_at'],
            'permission_scope' => $facts['permission_scope'],
            'metrics' => $this->aggregateByMetric($facts['rows'], $requiredObligationDays),
        ]);
    }

    private function ratio(int $numerator, int $denominator): array {
        return [
            'numerator' => $numerator,
            'denominator' => $denominator,
            'value' => $denominator > 0 ? round($numerator / $denominator, 4) : 0.0,
        ];
    }

    private function average(float $numerator, int $denominator): array {
        return [
            'numerator' => round($numerator, 2),
            'denominator' => $denominator,
            'value' => $denominator > 0 ? round($numerator / $denominator, 2) : 0.0,
        ];
    }

    private function appendInCondition(array &$where, array &$params, string $expression, array $values): void {
        if ($values === []) {
            return;
        }
        $where[] = $expression . ' IN (' . implode(',', array_fill(0, count($values), '?')) . ')';
        array_push($params, ...$values);
    }

    private function normalizeDate(string $value, string $label): string {
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new WorkloadAnalyticsQueryException($label . '格式无效');
        }
        return $value;
    }

    private function normalizeIntegerList(mixed $value, string $label): array {
        $values = [];
        foreach ($this->listValues($value) as $item) {
            $integer = filter_var($item, FILTER_VALIDATE_INT);
            if ($integer === false || (int) $integer <= 0) {
                throw new WorkloadAnalyticsQueryException($label . ' ID 格式无效');
            }
            $values[] = (int) $integer;
        }
        return $this->uniqueBoundedList($values, $label);
    }

    private function normalizeCodeList(mixed $value, string $label, int $maxLength): array {
        $values = [];
        foreach ($this->listValues($value) as $item) {
            $code = strtolower(trim((string) $item));
            $maxTailLength = $maxLength - 1;
            if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,' . $maxTailLength . '}$/', $code)) {
                throw new WorkloadAnalyticsQueryException($label . '格式无效');
            }
            $values[] = $code;
        }
        return $this->uniqueBoundedList($values, $label);
    }

    private function normalizeEnumList(mixed $value, array $allowed, string $label): array {
        $values = [];
        foreach ($this->listValues($value) as $item) {
            $normalized = strtolower(trim((string) $item));
            if (!in_array($normalized, $allowed, true)) {
                throw new WorkloadAnalyticsQueryException($label . '无效');
            }
            $values[] = $normalized;
        }
        return $this->uniqueBoundedList($values, $label);
    }

    private function normalizeSources(mixed $value): array {
        $requested = $this->listValues($value);
        if ($requested === []) {
            $sources = $this->sourcePolicy->defaultIncludedSources();
            if ($sources === []) {
                throw new WorkloadAnalyticsQueryException('默认经营日报来源尚未配置', 500);
            }
            return $sources;
        }
        $sources = [];
        foreach ($requested as $source) {
            $policy = $this->sourcePolicy->policy((string) $source);
            $sources[] = $policy['source_code'];
        }
        return $this->uniqueBoundedList($sources, '日报来源');
    }

    private function listValues(mixed $value): array {
        if ($value === null || $value === '') {
            return [];
        }
        $values = is_array($value) ? $value : explode(',', (string) $value);
        return array_values(array_filter($values, static fn(mixed $item): bool => trim((string) $item) !== ''));
    }

    private function uniqueBoundedList(array $values, string $label): array {
        $values = array_values(array_unique($values, SORT_REGULAR));
        if (count($values) > 200) {
            throw new WorkloadAnalyticsQueryException($label . '筛选项不能超过 200 个');
        }
        sort($values);
        return $values;
    }
}
