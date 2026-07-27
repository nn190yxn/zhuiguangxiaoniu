<?php
declare(strict_types=1);

require_once __DIR__ . '/WorkloadAnalyticsQueryService.php';

final class WorkloadStoreAnalyticsService {
    private const COMPLETION_STATUSES = ['missing', 'draft', 'submitted', 'locked_missing', 'corrected'];

    private PDO $pdo;
    private WorkloadAnalyticsQueryService $analytics;
    private WorkloadMetricVersionService $metricVersion;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->analytics = new WorkloadAnalyticsQueryService($pdo);
        $this->metricVersion = new WorkloadMetricVersionService($pdo);
    }

    public function storeCompletion(array $input, array $context): array {
        $facts = $this->analytics->facts($input, $context);
        $filters = $facts['filters'];
        $permissionScope = $facts['permission_scope'];
        $obligations = $this->obligationRows($filters, $permissionScope);
        $storeSummaries = $this->aggregateStoreCompletion($obligations);
        $metadata = $this->metricVersion->responseMetadata($filters, $filters['sources']);

        return array_merge($metadata, [
            'data_cutoff_at' => $metadata['generated_at'],
            'permission_scope' => $permissionScope,
            'period' => $this->periodMetadata($filters['date_from'], $filters['date_to']),
            'store_summaries' => $storeSummaries,
            'daily_trend' => $this->aggregateDailyTrend($obligations),
            'status_details' => $obligations,
            'store_metric_matrix' => $this->storeMetricMatrix(
                $facts['rows'],
                $obligations,
                $storeSummaries,
                $filters
            ),
        ]);
    }

    public function aggregateStoreCompletion(array $rows): array {
        $groups = [];
        foreach ($rows as $row) {
            $storeId = (int) ($row['store_id'] ?? 0);
            if ($storeId <= 0) {
                continue;
            }
            if (!isset($groups[$storeId])) {
                $groups[$storeId] = $this->emptyCompletionGroup(
                    $storeId,
                    (string) ($row['store_name'] ?? '')
                );
            }
            $this->addObligationToCompletionGroup($groups[$storeId], $row);
        }
        $result = array_values(array_map(fn(array $group): array => $this->finishCompletionGroup($group), $groups));
        usort($result, static fn(array $left, array $right): int => $left['store_id'] <=> $right['store_id']);
        return $result;
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

        $sql = 'SELECT o.id AS obligation_id, o.obligation_date AS business_date, o.store_id, '
            . 'st.name AS store_name, o.staff_id, s.name AS staff_name, o.role_code, o.reason_code, '
            . 'o.report_id, o.completion_status AS stored_completion_status, o.deadline_at, o.completed_at, '
            . 'r.source AS report_source FROM workload_submission_obligations o '
            . 'LEFT JOIN workload_daily_reports r ON r.id = o.report_id '
            . 'LEFT JOIN stores st ON st.id = o.store_id LEFT JOIN staffs s ON s.id = o.staff_id '
            . 'WHERE ' . implode(' AND ', $where) . ' '
            . 'ORDER BY o.obligation_date, o.store_id, o.staff_id, o.role_code';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            foreach (['obligation_id', 'store_id', 'staff_id', 'report_id'] as $field) {
                $row[$field] = isset($row[$field]) ? (int) $row[$field] : null;
            }
            $storedStatus = (string) ($row['stored_completion_status'] ?? '');
            if (!in_array($storedStatus, self::COMPLETION_STATUSES, true)) {
                throw new WorkloadAnalyticsQueryException('日报义务完成状态无效', 500);
            }
            $reportSource = trim((string) ($row['report_source'] ?? ''));
            $sourceInScope = $reportSource === '' || in_array($reportSource, $filters['sources'], true);
            $row['source_in_scope'] = $sourceInScope;
            $row['completion_status'] = $sourceInScope ? $storedStatus : 'missing';
            $row['drilldown_token'] = $this->drilldownToken($row);
        }
        unset($row);
        return $rows;
    }

    private function aggregateDailyTrend(array $rows): array {
        $groups = [];
        foreach ($rows as $row) {
            $key = (string) $row['business_date'] . ':' . (int) $row['store_id'];
            if (!isset($groups[$key])) {
                $groups[$key] = $this->emptyCompletionGroup(
                    (int) $row['store_id'],
                    (string) ($row['store_name'] ?? '')
                );
                $groups[$key]['business_date'] = (string) $row['business_date'];
            }
            $this->addObligationToCompletionGroup($groups[$key], $row);
        }
        $result = array_values(array_map(fn(array $group): array => $this->finishCompletionGroup($group), $groups));
        usort($result, static function (array $left, array $right): int {
            return [$left['business_date'], $left['store_id']] <=> [$right['business_date'], $right['store_id']];
        });
        return $result;
    }

    private function storeMetricMatrix(
        array $facts,
        array $obligations,
        array $storeSummaries,
        array $filters
    ): array {
        $summaryByStore = [];
        foreach ($storeSummaries as $summary) {
            $summaryByStore[(int) $summary['store_id']] = $summary;
        }
        $obligationsByStoreRole = [];
        $requiredByStaffRole = [];
        $completionByStoreRole = [];
        foreach ($obligations as $obligation) {
            $storeId = (int) $obligation['store_id'];
            $roleCode = (string) $obligation['role_code'];
            $staffId = (int) $obligation['staff_id'];
            $obligationsByStoreRole[$storeId][$roleCode][$staffId] = [
                'staff_id' => $staffId,
                'staff_name' => (string) ($obligation['staff_name'] ?? ''),
            ];
            $requiredByStaffRole[$storeId][$roleCode][$staffId] =
                ($requiredByStaffRole[$storeId][$roleCode][$staffId] ?? 0) + 1;
            if (!isset($completionByStoreRole[$storeId][$roleCode])) {
                $completionByStoreRole[$storeId][$roleCode] = $this->emptyCompletionGroup(
                    $storeId,
                    (string) ($obligation['store_name'] ?? '')
                );
            }
            $this->addObligationToCompletionGroup($completionByStoreRole[$storeId][$roleCode], $obligation);
        }
        $factGroups = [];
        foreach ($facts as $fact) {
            if (($fact['report_status'] ?? '') !== 'submitted') {
                continue;
            }
            $storeId = (int) ($fact['store_id'] ?? 0);
            $metricCode = (string) ($fact['metric_code'] ?? '');
            $factGroups[$storeId][$metricCode][] = $fact;
        }
        $metrics = $this->metricCatalog($filters, $obligations);
        $matrix = [];
        foreach ($summaryByStore as $storeId => $summary) {
            foreach ($metrics as $metric) {
                $roleCode = (string) $metric['role_code'];
                $staffRoster = $obligationsByStoreRole[$storeId][$roleCode] ?? [];
                if ($staffRoster === []) {
                    continue;
                }
                $metricCode = (string) $metric['metric_code'];
                $metricFacts = $factGroups[$storeId][$metricCode] ?? [];
                $roleCompletion = $this->finishCompletionGroup($completionByStoreRole[$storeId][$roleCode]);
                $requiredCount = (int) $roleCompletion['required_count'];
                $aggregate = $this->analytics->aggregateByMetric($metricFacts, $requiredCount);
                $row = $aggregate[0] ?? $this->emptyMetricAggregate($metric, $requiredCount);
                $submittedCoverage = $row['staff_coverage'];
                $submittedStaffAverage = $row['all_staff_average'];
                $row['store_id'] = $storeId;
                $row['store_name'] = (string) $summary['store_name'];
                $row['role_code'] = $roleCode;
                $row['completion_rate'] = $roleCompletion['completion_rate'];
                $row['submitted_staff_coverage'] = $submittedCoverage;
                $row['submitted_staff_average'] = $submittedStaffAverage;
                $row['staff_coverage'] = $this->ratio(
                    (int) $row['positive_staff_count'],
                    count($staffRoster)
                );
                $row['all_staff_average'] = $this->average(
                    (float) $row['effective_value'],
                    count($staffRoster)
                );
                $row['staff_rows'] = $this->staffMetricRows(
                    $metric,
                    $metricFacts,
                    $staffRoster,
                    $requiredByStaffRole[$storeId][$roleCode] ?? []
                );
                $matrix[] = $row;
            }
        }
        $this->assignRanks($matrix, 'effective_value', 'effective_value_rank');
        $this->assignRanks($matrix, 'raw_value', 'raw_value_rank');
        usort($matrix, static fn(array $left, array $right): int => [
            $left['store_id'],
            $left['role_code'],
            $left['metric_code'],
        ] <=> [
            $right['store_id'],
            $right['role_code'],
            $right['metric_code'],
        ]);
        return $matrix;
    }

    private function staffMetricRows(array $metric, array $facts, array $staffRoster, array $requiredCounts): array {
        $factsByStaff = [];
        foreach ($facts as $fact) {
            $factsByStaff[(int) $fact['staff_id']][] = $fact;
        }
        $rows = [];
        foreach ($staffRoster as $staffId => $staff) {
            $aggregate = $this->analytics->aggregateByMetric(
                $factsByStaff[$staffId] ?? [],
                (int) ($requiredCounts[$staffId] ?? 0)
            );
            $row = $aggregate[0] ?? $this->emptyMetricAggregate($metric, (int) ($requiredCounts[$staffId] ?? 0));
            $row['staff_id'] = (int) $staffId;
            $row['staff_name'] = (string) $staff['staff_name'];
            $rows[] = $row;
        }
        usort($rows, static fn(array $left, array $right): int => $left['staff_id'] <=> $right['staff_id']);
        return $rows;
    }

    private function metricCatalog(array $filters, array $obligations): array {
        $roles = $filters['role_codes'];
        if ($roles === []) {
            $roles = array_values(array_unique(array_map(
                static fn(array $row): string => (string) $row['role_code'],
                $obligations
            )));
        }
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

    private function emptyCompletionGroup(int $storeId, string $storeName): array {
        return [
            'store_id' => $storeId,
            'store_name' => $storeName,
            'required_count' => 0,
            'completed_count' => 0,
            'excluded_source_count' => 0,
            'business_dates' => [],
            'status_counts' => array_fill_keys(self::COMPLETION_STATUSES, 0),
        ];
    }

    private function addObligationToCompletionGroup(array &$group, array $row): void {
        $status = (string) ($row['completion_status'] ?? '');
        if (!array_key_exists($status, $group['status_counts'])) {
            throw new WorkloadAnalyticsQueryException('日报义务完成状态无效', 500);
        }
        $group['required_count']++;
        $group['status_counts'][$status]++;
        if (in_array($status, ['submitted', 'corrected'], true)) {
            $group['completed_count']++;
        }
        if (empty($row['source_in_scope'])) {
            $group['excluded_source_count']++;
        }
        $group['business_dates'][(string) $row['business_date']] = true;
    }

    private function finishCompletionGroup(array $group): array {
        $group['business_day_count'] = count($group['business_dates']);
        $group['completion_rate'] = $this->ratio($group['completed_count'], $group['required_count']);
        unset($group['business_dates']);
        return $group;
    }

    private function emptyMetricAggregate(array $metric, int $requiredCount): array {
        $zeroRatio = $this->ratio(0, 0);
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
            'selection_rate' => $zeroRatio,
            'effective_selection_rate' => $zeroRatio,
            'zero_rate' => $zeroRatio,
            'staff_coverage' => $zeroRatio,
            'store_coverage' => $zeroRatio,
            'all_staff_average' => $this->average(0.0, 0),
            'participant_staff_average' => $this->average(0.0, 0),
            'per_obligation_day_average' => $this->average(0.0, $requiredCount),
        ];
    }

    private function assignRanks(array &$rows, string $valueField, string $rankField): void {
        $indexesByMetric = [];
        foreach ($rows as $index => $row) {
            $indexesByMetric[(string) $row['metric_code']][] = $index;
        }
        foreach ($indexesByMetric as $indexes) {
            usort($indexes, static function (int $left, int $right) use ($rows, $valueField): int {
                return [$rows[$right][$valueField], -$rows[$right]['store_id']]
                    <=> [$rows[$left][$valueField], -$rows[$left]['store_id']];
            });
            $rank = 0;
            $previous = null;
            foreach ($indexes as $position => $index) {
                $value = (float) $rows[$index][$valueField];
                if ($previous === null || $value !== $previous) {
                    $rank = $position + 1;
                    $previous = $value;
                }
                $rows[$index][$rankField] = $rank;
            }
        }
    }

    private function periodMetadata(string $dateFrom, string $dateTo): array {
        $cursor = new DateTimeImmutable($dateFrom);
        $end = new DateTimeImmutable($dateTo);
        $calendar = [];
        $businessDayCount = 0;
        while ($cursor <= $end) {
            $isRestDay = $cursor->format('N') === '1';
            $calendar[] = [
                'business_date' => $cursor->format('Y-m-d'),
                'day_type' => $isRestDay ? 'weekly_rest_day' : 'business_day',
            ];
            if (!$isRestDay) {
                $businessDayCount++;
            }
            $cursor = $cursor->modify('+1 day');
        }
        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'calendar_day_count' => count($calendar),
            'business_day_count' => $businessDayCount,
            'calendar' => $calendar,
        ];
    }

    private function drilldownToken(array $row): string {
        $payload = json_encode([
            'date' => (string) $row['business_date'],
            'store_id' => (int) $row['store_id'],
            'staff_id' => (int) $row['staff_id'],
            'role_code' => (string) $row['role_code'],
            'completion_status' => (string) $row['completion_status'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
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

    private function appendInCondition(array &$where, array &$params, string $field, array $values): void {
        if ($values === []) {
            return;
        }
        $where[] = $field . ' IN (' . implode(',', array_fill(0, count($values), '?')) . ')';
        array_push($params, ...$values);
    }
}
