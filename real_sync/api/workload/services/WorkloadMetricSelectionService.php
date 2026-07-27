<?php
declare(strict_types=1);

require_once __DIR__ . '/WorkloadAnalyticsQueryService.php';
require_once __DIR__ . '/WorkloadComparisonService.php';

final class WorkloadMetricSelectionService {
    private PDO $pdo;
    private WorkloadAnalyticsQueryService $analytics;
    private WorkloadMetricVersionService $metricVersion;
    private WorkloadComparisonService $comparison;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->analytics = new WorkloadAnalyticsQueryService($pdo);
        $this->metricVersion = new WorkloadMetricVersionService($pdo);
        $this->comparison = new WorkloadComparisonService();
    }

    public function metricSelection(array $input, array $context): array {
        $factsResult = $this->analytics->facts($input, $context);
        $filters = $factsResult['filters'];
        $permissionScope = $factsResult['permission_scope'];
        $facts = $factsResult['rows'];
        $obligations = $this->obligationRows($filters, $permissionScope);
        $dimensions = $this->dimensions($facts, $obligations);
        $catalog = $this->metricCatalog($filters, $dimensions['roles']);

        $projectSummaries = $this->projectSummaries($catalog, $facts, $dimensions);
        $storeRankings = $this->storeRankings($catalog, $facts, $dimensions);
        $staffRankings = $this->staffRankings($catalog, $facts, $dimensions);
        $metadata = $this->metricVersion->responseMetadata($filters, $filters['sources']);

        return array_merge($metadata, [
            'data_cutoff_at' => $metadata['generated_at'],
            'permission_scope' => $permissionScope,
            'project_summaries' => $projectSummaries,
            'store_rankings' => $storeRankings,
            'staff_rankings' => $staffRankings,
        ]);
    }

    private function obligationRows(array $filters, array $permissionScope): array {
        $where = ["o.required_status = 'required'", 'o.obligation_date BETWEEN ? AND ?'];
        $params = [$filters['date_from'], $filters['date_to']];
        $this->appendInCondition($where, $params, 'o.store_id', $filters['store_ids']);
        $this->appendInCondition($where, $params, 'o.role_code', $filters['role_codes']);
        $this->appendInCondition($where, $params, 'o.staff_id', $filters['staff_ids']);

        if (($permissionScope['scope_type'] ?? '') === 'stores') {
            $this->appendInCondition($where, $params, 'o.store_id', $permissionScope['store_ids'] ?? []);
        } elseif (($permissionScope['scope_type'] ?? '') === 'staff') {
            $where[] = 'o.staff_id = ?';
            $params[] = (int) ($permissionScope['staff_id'] ?? 0);
        } elseif (($permissionScope['scope_type'] ?? '') !== 'all') {
            throw new WorkloadAnalyticsQueryException('工作量查询权限范围无效', 403);
        }

        $stmt = $this->pdo->prepare(
            'SELECT o.obligation_date AS business_date, o.store_id, st.name AS store_name, '
            . 'o.staff_id, s.name AS staff_name, o.role_code '
            . 'FROM workload_submission_obligations o '
            . 'LEFT JOIN stores st ON st.id = o.store_id LEFT JOIN staffs s ON s.id = o.staff_id '
            . 'WHERE ' . implode(' AND ', $where) . ' '
            . 'ORDER BY o.obligation_date, o.store_id, o.staff_id, o.role_code'
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['store_id'] = (int) ($row['store_id'] ?? 0);
            $row['staff_id'] = (int) ($row['staff_id'] ?? 0);
        }
        unset($row);
        return $rows;
    }

    private function dimensions(array $facts, array $obligations): array {
        $result = [
            'roles' => [],
            'role_required' => [],
            'stores' => [],
            'store_role_required' => [],
            'staff' => [],
            'staff_store_role_required' => [],
        ];
        foreach ($obligations as $row) {
            $this->addDimensionRow($result, $row, true);
        }
        foreach ($facts as $row) {
            $this->addDimensionRow($result, $row, false);
        }
        $result['roles'] = array_values(array_keys($result['roles']));
        sort($result['roles']);
        return $result;
    }

    private function addDimensionRow(array &$dimensions, array $row, bool $required): void {
        $roleCode = trim((string) ($row['role_code'] ?? ''));
        $storeId = (int) ($row['store_id'] ?? 0);
        $staffId = (int) ($row['staff_id'] ?? 0);
        if ($roleCode === '' || $storeId <= 0 || $staffId <= 0) {
            return;
        }
        $dimensions['roles'][$roleCode] = true;
        $dimensions['stores'][$storeId] = (string) ($row['store_name'] ?? '');
        $staffKey = $staffId . ':' . $storeId . ':' . $roleCode;
        $dimensions['staff'][$staffKey] = [
            'staff_id' => $staffId,
            'staff_name' => (string) ($row['staff_name'] ?? ''),
            'store_id' => $storeId,
            'store_name' => (string) ($row['store_name'] ?? ''),
            'role_code' => $roleCode,
        ];
        if (!$required) {
            return;
        }
        $storeRoleKey = $storeId . ':' . $roleCode;
        $dimensions['role_required'][$roleCode] = ($dimensions['role_required'][$roleCode] ?? 0) + 1;
        $dimensions['store_role_required'][$storeRoleKey] =
            ($dimensions['store_role_required'][$storeRoleKey] ?? 0) + 1;
        $dimensions['staff_store_role_required'][$staffKey] =
            ($dimensions['staff_store_role_required'][$staffKey] ?? 0) + 1;
    }

    private function metricCatalog(array $filters, array $roles): array {
        if ($roles === []) {
            return [];
        }
        $where = ['is_active = 1'];
        $params = [];
        $this->appendInCondition($where, $params, 'role_code', $roles);
        $this->appendInCondition($where, $params, 'metric_code', $filters['metric_codes']);
        $stmt = $this->pdo->prepare(
            'SELECT metric_code, metric_name, unit, role_code FROM metric_definitions WHERE '
            . implode(' AND ', $where) . ' ORDER BY role_code, sort_order, metric_code'
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function projectSummaries(array $catalog, array $facts, array $dimensions): array {
        $factsByRoleMetric = [];
        foreach ($facts as $fact) {
            $factsByRoleMetric[$this->roleMetricKey($fact)][] = $fact;
        }
        $rows = [];
        foreach ($catalog as $metric) {
            $roleCode = (string) $metric['role_code'];
            $row = $this->aggregate(
                $metric,
                $factsByRoleMetric[$this->roleMetricKey($metric)] ?? [],
                (int) ($dimensions['role_required'][$roleCode] ?? 0)
            );
            $row['role_code'] = $roleCode;
            $rows[] = $row;
        }
        $this->assignDenseRanks($rows, ['role_code'], 'store_coverage.value', 'store_coverage_rank');
        $this->assignDenseRanks($rows, ['role_code'], 'staff_coverage.value', 'staff_coverage_rank');
        $this->assignDenseRanks($rows, ['role_code'], 'effective_value', 'effective_value_rank');
        $this->assignDenseRanks($rows, ['role_code'], 'raw_value', 'raw_value_rank');
        return $rows;
    }

    private function storeRankings(array $catalog, array $facts, array $dimensions): array {
        $factsByStoreRoleMetric = [];
        foreach ($facts as $fact) {
            $key = (int) $fact['store_id'] . ':' . $this->roleMetricKey($fact);
            $factsByStoreRoleMetric[$key][] = $fact;
        }
        $rows = [];
        foreach ($dimensions['store_role_required'] as $storeRoleKey => $requiredCount) {
            [$storeId, $roleCode] = explode(':', $storeRoleKey, 2);
            foreach ($catalog as $metric) {
                if ((string) $metric['role_code'] !== $roleCode) {
                    continue;
                }
                $factKey = $storeId . ':' . $this->roleMetricKey($metric);
                $row = $this->aggregate($metric, $factsByStoreRoleMetric[$factKey] ?? [], (int) $requiredCount);
                $row['store_id'] = (int) $storeId;
                $row['store_name'] = (string) ($dimensions['stores'][(int) $storeId] ?? '');
                $row['role_code'] = $roleCode;
                $rows[] = $row;
            }
        }
        $groupFields = ['role_code', 'metric_code'];
        $this->assignDenseRanks($rows, $groupFields, 'effective_value', 'effective_value_rank');
        $this->assignDenseRanks($rows, $groupFields, 'raw_value', 'raw_value_rank');
        $this->assignDenseRanks($rows, $groupFields, 'staff_coverage.value', 'staff_coverage_rank');
        $this->addStoreBenchmarks($rows);
        $this->sortRows($rows, ['role_code', 'metric_code', 'store_id']);
        return $rows;
    }

    private function staffRankings(array $catalog, array $facts, array $dimensions): array {
        $factsByStaffMetric = [];
        foreach ($facts as $fact) {
            $staffKey = (int) $fact['staff_id'] . ':' . (int) $fact['store_id'] . ':' . (string) $fact['role_code'];
            $factsByStaffMetric[$staffKey . ':' . (string) $fact['metric_code']][] = $fact;
        }
        $rows = [];
        foreach ($dimensions['staff'] as $staffKey => $staff) {
            foreach ($catalog as $metric) {
                if ((string) $metric['role_code'] !== (string) $staff['role_code']) {
                    continue;
                }
                $requiredCount = (int) ($dimensions['staff_store_role_required'][$staffKey] ?? 0);
                $row = $this->aggregate(
                    $metric,
                    $factsByStaffMetric[$staffKey . ':' . (string) $metric['metric_code']] ?? [],
                    $requiredCount
                );
                $row = array_merge($row, $staff);
                $rows[] = $row;
            }
        }
        $storeFields = ['store_id', 'role_code', 'metric_code'];
        $allStoreFields = ['role_code', 'metric_code'];
        $this->assignDenseRanks($rows, $storeFields, 'effective_value', 'store_effective_value_rank');
        $this->assignDenseRanks($rows, $storeFields, 'raw_value', 'store_raw_value_rank');
        $this->assignDenseRanks($rows, $allStoreFields, 'effective_value', 'all_store_role_effective_value_rank');
        $this->assignDenseRanks($rows, $allStoreFields, 'raw_value', 'all_store_role_raw_value_rank');
        $this->addStaffBenchmarks($rows);
        $this->sortRows($rows, ['role_code', 'metric_code', 'store_id', 'staff_id']);
        return $rows;
    }

    private function aggregate(array $metric, array $facts, int $requiredCount): array {
        $aggregate = $this->analytics->aggregateByMetric($facts, $requiredCount);
        $row = $aggregate[0] ?? $this->emptyAggregate($metric, $requiredCount);
        $row['has_pending_review'] = false;
        foreach ($facts as $fact) {
            if (($fact['audit_status'] ?? '') === 'pending') {
                $row['has_pending_review'] = true;
                break;
            }
        }
        return $row;
    }

    private function emptyAggregate(array $metric, int $requiredCount): array {
        $ratio = $this->ratio(0, 0);
        return [
            'metric_code' => (string) $metric['metric_code'],
            'metric_name' => (string) $metric['metric_name'],
            'unit' => (string) $metric['unit'],
            'required_obligation_days' => $requiredCount,
            'sample_size' => 0,
            'submitted_report_count' => 0,
            'submitted_staff_count' => 0,
            'positive_staff_count' => 0,
            'submitted_store_count' => 0,
            'positive_store_count' => 0,
            'positive_raw_report_count' => 0,
            'positive_effective_report_count' => 0,
            'zero_raw_report_count' => 0,
            'low_sample' => true,
            'sample_thresholds' => ['submitted_reports' => 10, 'submitted_staff' => 3],
            'raw_value' => 0.0,
            'pending_value' => 0.0,
            'effective_value' => 0.0,
            'rejected_value' => 0.0,
            'selection_rate' => $ratio,
            'effective_selection_rate' => $ratio,
            'zero_rate' => $ratio,
            'staff_coverage' => $ratio,
            'store_coverage' => $ratio,
            'all_staff_average' => $this->average(0.0, 0),
            'participant_staff_average' => $this->average(0.0, 0),
            'per_obligation_day_average' => $this->average(0.0, $requiredCount),
        ];
    }

    private function addStoreBenchmarks(array &$rows): void {
        $groups = $this->rowGroups($rows, ['role_code', 'metric_code']);
        foreach ($groups as $indexes) {
            $effectiveValues = array_map(static fn(int $index): float => (float) $rows[$index]['effective_value'], $indexes);
            $rawValues = array_map(static fn(int $index): float => (float) $rows[$index]['raw_value'], $indexes);
            $effectiveBenchmarks = $this->comparison->benchmarks($effectiveValues);
            foreach ($indexes as $index) {
                $rows[$index]['all_store_effective_average'] = $effectiveBenchmarks['average'];
                $rows[$index]['all_store_raw_average'] = $this->valueAverage($rawValues);
                $rows[$index]['top_quartile_effective_reference'] = $effectiveBenchmarks['top_quartile_reference'];
            }
        }
    }

    private function addStaffBenchmarks(array &$rows): void {
        $storeGroups = $this->rowGroups($rows, ['store_id', 'role_code', 'metric_code']);
        foreach ($storeGroups as $indexes) {
            $values = array_map(static fn(int $index): float => (float) $rows[$index]['effective_value'], $indexes);
            foreach ($indexes as $index) {
                $rows[$index]['store_role_effective_average'] = $this->valueAverage($values);
            }
        }
        $roleGroups = $this->rowGroups($rows, ['role_code', 'metric_code']);
        foreach ($roleGroups as $indexes) {
            $values = array_map(static fn(int $index): float => (float) $rows[$index]['effective_value'], $indexes);
            foreach ($indexes as $index) {
                $rows[$index]['all_store_role_effective_average'] = $this->valueAverage($values);
            }
        }
    }

    private function assignDenseRanks(array &$rows, array $groupFields, string $valuePath, string $rankField): void {
        foreach ($this->rowGroups($rows, $groupFields) as $indexes) {
            usort($indexes, function (int $left, int $right) use ($rows, $valuePath): int {
                $comparison = $this->pathValue($rows[$right], $valuePath) <=> $this->pathValue($rows[$left], $valuePath);
                return $comparison !== 0 ? $comparison : $left <=> $right;
            });
            $rank = 0;
            $previous = null;
            foreach ($indexes as $position => $index) {
                $value = $this->pathValue($rows[$index], $valuePath);
                if ($previous === null || $value !== $previous) {
                    $rank = $position + 1;
                    $previous = $value;
                }
                $rows[$index][$rankField] = $rank;
            }
        }
    }

    private function rowGroups(array $rows, array $fields): array {
        $groups = [];
        foreach ($rows as $index => $row) {
            $parts = array_map(static fn(string $field): string => (string) ($row[$field] ?? ''), $fields);
            $groups[implode(':', $parts)][] = $index;
        }
        return $groups;
    }

    private function pathValue(array $row, string $path): float {
        $value = $row;
        foreach (explode('.', $path) as $part) {
            $value = is_array($value) ? ($value[$part] ?? 0) : 0;
        }
        return round((float) $value, 4);
    }

    private function valueAverage(array $values): array {
        return $this->comparison->average($values);
    }

    private function roleMetricKey(array $row): string {
        return (string) ($row['role_code'] ?? '') . ':' . (string) ($row['metric_code'] ?? '');
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

    private function sortRows(array &$rows, array $fields): void {
        usort($rows, static function (array $left, array $right) use ($fields): int {
            foreach ($fields as $field) {
                $comparison = ($left[$field] ?? null) <=> ($right[$field] ?? null);
                if ($comparison !== 0) {
                    return $comparison;
                }
            }
            return 0;
        });
    }

    private function appendInCondition(array &$where, array &$params, string $field, array $values): void {
        if ($values === []) {
            return;
        }
        $where[] = $field . ' IN (' . implode(',', array_fill(0, count($values), '?')) . ')';
        array_push($params, ...$values);
    }
}
