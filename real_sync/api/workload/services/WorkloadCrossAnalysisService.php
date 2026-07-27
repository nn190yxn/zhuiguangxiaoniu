<?php
declare(strict_types=1);

require_once __DIR__ . '/WorkloadAnalyticsQueryService.php';
require_once __DIR__ . '/WorkloadBusinessPeriodService.php';

final class WorkloadCrossAnalysisService {
    private const DIMENSIONS = ['store', 'metric', 'staff', 'time'];
    private const TIME_GRANULARITIES = ['day', 'business_week', 'month', 'quarter'];

    private PDO $pdo;
    private WorkloadAnalyticsQueryService $analytics;
    private WorkloadBusinessPeriodService $periods;
    private WorkloadMetricVersionService $metricVersion;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->analytics = new WorkloadAnalyticsQueryService($pdo);
        $this->periods = new WorkloadBusinessPeriodService();
        $this->metricVersion = new WorkloadMetricVersionService($pdo);
    }

    public function analyze(array $input, array $context): array {
        $primary = $this->dimension($input['primary_dimension'] ?? 'store', '主维度');
        $secondary = $this->dimension($input['secondary_dimension'] ?? 'metric', '次维度');
        if ($primary === $secondary) {
            throw new WorkloadAnalyticsQueryException('主维度和次维度不能相同');
        }
        $timeGranularity = strtolower(trim((string) ($input['time_granularity'] ?? 'day')));
        if (!in_array($timeGranularity, self::TIME_GRANULARITIES, true)) {
            throw new WorkloadAnalyticsQueryException('时间粒度无效');
        }

        $queryInput = $input;
        $period = null;
        if (trim((string) ($input['period_type'] ?? '')) !== '') {
            $period = $this->periods->resolve($input);
            $queryInput['date_from'] = $period['current_period']['date_from'];
            $queryInput['date_to'] = $period['current_period']['date_to'];
        }
        $factsResult = $this->analytics->facts($queryInput, $context);
        $filters = $factsResult['filters'];
        $period ??= $this->periods->resolve([
            'period_type' => 'custom',
            'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'],
        ]);
        $obligations = $this->obligationRows($filters, $factsResult['permission_scope']);
        $usesMetric = $primary === 'metric' || $secondary === 'metric';
        $catalog = $usesMetric ? $this->metricCatalog($filters, $factsResult['rows'], $obligations) : [];
        $groups = [];

        foreach ($obligations as $obligation) {
            $seedRows = $usesMetric ? $this->metricSeeds($obligation, $catalog) : [$obligation];
            foreach ($seedRows as $row) {
                $key = $this->groupKey($row, $primary, $secondary, $timeGranularity);
                $groups[$key] ??= $this->emptyCell($row, $primary, $secondary, $timeGranularity);
                $this->addObligation($groups[$key], $obligation);
            }
        }
        foreach ($factsResult['rows'] as $fact) {
            $key = $this->groupKey($fact, $primary, $secondary, $timeGranularity);
            $groups[$key] ??= $this->emptyCell($fact, $primary, $secondary, $timeGranularity);
            $this->addFact($groups[$key], $fact);
        }

        $rows = array_map(fn(array $group): array => $this->finalizeCell($group, $filters), array_values($groups));
        $this->assignDenseRanks($rows);
        usort($rows, static fn(array $left, array $right): int => [
            (string) $left['primary']['value'], (string) $left['secondary']['value'],
        ] <=> [
            (string) $right['primary']['value'], (string) $right['secondary']['value'],
        ]);

        $summary = $this->emptyState();
        foreach ($obligations as $obligation) {
            $this->addObligation($summary, $obligation);
        }
        foreach ($factsResult['rows'] as $fact) {
            $this->addFact($summary, $fact);
        }
        $metadata = $this->metricVersion->responseMetadata($filters, $filters['sources']);

        return array_merge($metadata, [
            'data_cutoff_at' => $metadata['generated_at'],
            'filters' => $filters,
            'permission_scope' => $factsResult['permission_scope'],
            'dimensions' => [
                'primary' => $primary,
                'secondary' => $secondary,
                'time_granularity' => $timeGranularity,
            ],
            'period' => $period,
            'summary' => $this->finalizeState($summary),
            'matrix' => $rows,
        ]);
    }

    private function obligationRows(array $filters, array $scope): array {
        $where = ["o.required_status = 'required'", 'o.obligation_date BETWEEN ? AND ?'];
        $params = [$filters['date_from'], $filters['date_to']];
        $this->appendInCondition($where, $params, 'o.store_id', $filters['store_ids']);
        $this->appendInCondition($where, $params, 'o.role_code', $filters['role_codes']);
        $this->appendInCondition($where, $params, 'o.staff_id', $filters['staff_ids']);
        if (($scope['scope_type'] ?? '') === 'stores') {
            $this->appendInCondition($where, $params, 'o.store_id', $scope['store_ids'] ?? []);
        } elseif (($scope['scope_type'] ?? '') === 'staff') {
            $where[] = 'o.staff_id = ?';
            $params[] = (int) ($scope['staff_id'] ?? 0);
        } elseif (($scope['scope_type'] ?? '') !== 'all') {
            throw new WorkloadAnalyticsQueryException('工作量查询权限范围无效', 403);
        }
        $stmt = $this->pdo->prepare(
            'SELECT o.id AS obligation_id, o.obligation_date AS business_date, o.store_id, '
            . 'st.name AS store_name, o.staff_id, s.name AS staff_name, o.role_code, o.completion_status '
            . 'FROM workload_submission_obligations o LEFT JOIN stores st ON st.id = o.store_id '
            . 'LEFT JOIN staffs s ON s.id = o.staff_id WHERE ' . implode(' AND ', $where) . ' '
            . 'ORDER BY o.obligation_date, o.store_id, o.staff_id, o.role_code'
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function metricCatalog(array $filters, array $facts, array $obligations): array {
        $roles = [];
        foreach ([...$facts, ...$obligations] as $row) {
            $role = trim((string) ($row['role_code'] ?? ''));
            if ($role !== '') {
                $roles[$role] = true;
            }
        }
        if ($roles === []) {
            return [];
        }
        $where = ['is_active = 1'];
        $params = [];
        $this->appendInCondition($where, $params, 'role_code', array_keys($roles));
        $this->appendInCondition($where, $params, 'metric_code', $filters['metric_codes']);
        $stmt = $this->pdo->prepare(
            'SELECT metric_code, metric_name, unit, role_code FROM metric_definitions '
            . 'WHERE ' . implode(' AND ', $where) . ' ORDER BY role_code, sort_order, metric_code'
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function metricSeeds(array $obligation, array $catalog): array {
        $rows = [];
        foreach ($catalog as $metric) {
            if ((string) $metric['role_code'] === (string) ($obligation['role_code'] ?? '')) {
                $rows[] = $obligation + $metric;
            }
        }
        return $rows;
    }

    private function emptyCell(array $row, string $primary, string $secondary, string $granularity): array {
        return $this->emptyState() + [
            'primary' => $this->dimensionValue($row, $primary, $granularity),
            'secondary' => $this->dimensionValue($row, $secondary, $granularity),
        ];
    }

    private function emptyState(): array {
        return [
            'raw_value' => 0.0,
            'pending_value' => 0.0,
            'effective_value' => 0.0,
            'rejected_value' => 0.0,
            '_fact_keys' => [],
            '_report_ids' => [],
            '_positive_report_ids' => [],
            '_submitted_staff_ids' => [],
            '_positive_staff_ids' => [],
            '_obligation_ids' => [],
            '_completed_obligation_ids' => [],
            '_obligated_staff_ids' => [],
        ];
    }

    private function addFact(array &$state, array $row): void {
        $factKey = (int) ($row['report_id'] ?? 0) . ':' . (string) ($row['metric_code'] ?? '');
        if (isset($state['_fact_keys'][$factKey])) {
            return;
        }
        $state['_fact_keys'][$factKey] = true;
        $reportId = (int) ($row['report_id'] ?? 0);
        $staffId = (int) ($row['staff_id'] ?? 0);
        if ($reportId > 0) {
            $state['_report_ids'][$reportId] = true;
        }
        if ($staffId > 0) {
            $state['_submitted_staff_ids'][$staffId] = true;
        }
        if ((float) ($row['raw_value'] ?? 0) > 0) {
            $state['_positive_report_ids'][$reportId] = true;
            $state['_positive_staff_ids'][$staffId] = true;
        }
        foreach (['raw_value', 'pending_value', 'effective_value', 'rejected_value'] as $field) {
            $state[$field] += (float) ($row[$field] ?? 0);
        }
    }

    private function addObligation(array &$state, array $row): void {
        $obligationId = (int) ($row['obligation_id'] ?? 0);
        if ($obligationId <= 0 || isset($state['_obligation_ids'][$obligationId])) {
            return;
        }
        $state['_obligation_ids'][$obligationId] = true;
        $staffId = (int) ($row['staff_id'] ?? 0);
        if ($staffId > 0) {
            $state['_obligated_staff_ids'][$staffId] = true;
        }
        if (in_array((string) ($row['completion_status'] ?? ''), ['submitted', 'corrected'], true)) {
            $state['_completed_obligation_ids'][$obligationId] = true;
        }
    }

    private function finalizeCell(array $state, array $filters): array {
        $primary = $state['primary'];
        $secondary = $state['secondary'];
        unset($state['primary'], $state['secondary']);
        return [
            'primary' => $primary,
            'secondary' => $secondary,
            ...$this->finalizeState($state),
            'drilldown' => [
                'endpoint' => '/api/workload/analytics/metric-detail.php',
                'params' => $this->drilldownParams($filters, [$primary, $secondary]),
            ],
        ];
    }

    private function finalizeState(array $state): array {
        $reportCount = count($state['_report_ids']);
        $submittedStaffCount = count($state['_submitted_staff_ids']);
        $positiveStaffCount = count($state['_positive_staff_ids']);
        $requiredCount = count($state['_obligation_ids']);
        $completedCount = count($state['_completed_obligation_ids']);
        $obligatedStaffCount = count($state['_obligated_staff_ids']);
        $result = [];
        foreach (['raw_value', 'pending_value', 'effective_value', 'rejected_value'] as $field) {
            $result[$field] = round((float) $state[$field], 2);
        }
        return $result + [
            'required_obligation_days' => $requiredCount,
            'completed_obligation_days' => $completedCount,
            'submitted_report_count' => $reportCount,
            'sample_size' => $reportCount,
            'submitted_staff_count' => $submittedStaffCount,
            'obligated_staff_count' => $obligatedStaffCount,
            'low_sample' => $reportCount < 10 || $submittedStaffCount < 3,
            'completion_rate' => $this->ratio($completedCount, $requiredCount),
            'coverage_rate' => $this->ratio($positiveStaffCount, $obligatedStaffCount),
            'selection_rate' => $this->ratio(count($state['_positive_report_ids']), $reportCount),
            'per_obligation_day_average' => $this->average($result['effective_value'], $requiredCount),
        ];
    }

    private function dimensionValue(array $row, string $dimension, string $granularity): array {
        if ($dimension === 'store') {
            return ['dimension' => 'store', 'value' => (int) ($row['store_id'] ?? 0), 'label' => (string) ($row['store_name'] ?? '')];
        }
        if ($dimension === 'metric') {
            return ['dimension' => 'metric', 'value' => (string) ($row['metric_code'] ?? ''), 'label' => (string) ($row['metric_name'] ?? '')];
        }
        if ($dimension === 'staff') {
            return ['dimension' => 'staff', 'value' => (int) ($row['staff_id'] ?? 0), 'label' => (string) ($row['staff_name'] ?? '')];
        }
        return $this->timeDimension((string) ($row['business_date'] ?? ''), $granularity);
    }

    private function timeDimension(string $date, string $granularity): array {
        $value = new DateTimeImmutable($date);
        if ($granularity === 'day') {
            $from = $value;
            $to = $value;
            $key = $date;
        } elseif ($granularity === 'business_week') {
            $offset = (int) $value->format('N') === 1 ? 6 : (int) $value->format('N') - 2;
            $from = $value->modify('-' . $offset . ' days');
            $to = $from->modify('+5 days');
            $key = $from->format('Y-m-d');
        } elseif ($granularity === 'month') {
            $from = $value->modify('first day of this month');
            $to = $value->modify('last day of this month');
            $key = $value->format('Y-m');
        } else {
            $month = ((int) floor(((int) $value->format('n') - 1) / 3) * 3) + 1;
            $from = $value->setDate((int) $value->format('Y'), $month, 1);
            $to = $from->modify('+3 months')->modify('-1 day');
            $key = $value->format('Y') . '-Q' . ((int) floor(((int) $value->format('n') - 1) / 3) + 1);
        }
        return [
            'dimension' => 'time',
            'value' => $key,
            'label' => $key,
            'date_from' => $from->format('Y-m-d'),
            'date_to' => $to->format('Y-m-d'),
        ];
    }

    private function groupKey(array $row, string $primary, string $secondary, string $granularity): string {
        $left = $this->dimensionValue($row, $primary, $granularity);
        $right = $this->dimensionValue($row, $secondary, $granularity);
        return $primary . ':' . $left['value'] . '|' . $secondary . ':' . $right['value'];
    }

    private function drilldownParams(array $filters, array $dimensions): array {
        $params = [
            'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'],
            'store_ids' => $filters['store_ids'],
            'role_codes' => $filters['role_codes'],
            'staff_ids' => $filters['staff_ids'],
            'metric_codes' => $filters['metric_codes'],
            'report_statuses' => $filters['report_statuses'],
            'audit_statuses' => $filters['audit_statuses'],
            'sources' => $filters['sources'],
        ];
        foreach ($dimensions as $dimension) {
            if ($dimension['dimension'] === 'store') {
                $params['store_ids'] = [(int) $dimension['value']];
            } elseif ($dimension['dimension'] === 'staff') {
                $params['staff_ids'] = [(int) $dimension['value']];
            } elseif ($dimension['dimension'] === 'metric') {
                $params['metric_codes'] = [(string) $dimension['value']];
            } elseif ($dimension['dimension'] === 'time') {
                $params['date_from'] = $dimension['date_from'];
                $params['date_to'] = $dimension['date_to'];
            }
        }
        return $params;
    }

    private function assignDenseRanks(array &$rows): void {
        $indexes = array_keys($rows);
        usort($indexes, static fn(int $left, int $right): int =>
            $rows[$right]['effective_value'] <=> $rows[$left]['effective_value'] ?: $left <=> $right
        );
        $rank = 0;
        $previous = null;
        foreach ($indexes as $position => $index) {
            $value = (float) $rows[$index]['effective_value'];
            if ($previous === null || $value !== $previous) {
                $rank = $position + 1;
                $previous = $value;
            }
            $rows[$index]['effective_value_rank'] = $rank;
        }
    }

    private function dimension(mixed $value, string $label): string {
        $dimension = strtolower(trim((string) $value));
        $dimension = $dimension === 'project' ? 'metric' : $dimension;
        if (!in_array($dimension, self::DIMENSIONS, true)) {
            throw new WorkloadAnalyticsQueryException($label . '无效');
        }
        return $dimension;
    }

    private function ratio(int $numerator, int $denominator): array {
        return ['numerator' => $numerator, 'denominator' => $denominator, 'value' => $denominator > 0 ? round($numerator / $denominator, 4) : 0.0];
    }

    private function average(float $numerator, int $denominator): array {
        return ['numerator' => round($numerator, 2), 'denominator' => $denominator, 'value' => $denominator > 0 ? round($numerator / $denominator, 2) : 0.0];
    }

    private function appendInCondition(array &$where, array &$params, string $field, array $values): void {
        if ($values !== []) {
            $where[] = $field . ' IN (' . implode(',', array_fill(0, count($values), '?')) . ')';
            array_push($params, ...$values);
        }
    }
}
