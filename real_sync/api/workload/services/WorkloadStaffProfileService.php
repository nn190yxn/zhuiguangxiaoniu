<?php
declare(strict_types=1);

require_once __DIR__ . '/WorkloadAnalyticsQueryService.php';
require_once __DIR__ . '/WorkloadMetricSelectionService.php';
require_once __DIR__ . '/WorkloadComparisonService.php';

final class WorkloadStaffProfileService {
    private PDO $pdo;
    private WorkloadAnalyticsQueryService $analytics;
    private WorkloadMetricSelectionService $metricSelection;
    private WorkloadComparisonService $comparisonService;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->analytics = new WorkloadAnalyticsQueryService($pdo);
        $this->metricSelection = new WorkloadMetricSelectionService($pdo);
        $this->comparisonService = new WorkloadComparisonService();
    }

    public function profile(array $input, array $context): array {
        $staffId = $this->positiveInt($input['staff_id'] ?? 0, '员工 ID');
        $granularity = strtolower(trim((string) ($input['granularity'] ?? 'day')));
        if (!in_array($granularity, ['day', 'week', 'month'], true)) {
            throw new WorkloadAnalyticsQueryException('聚合粒度只支持 day、week 或 month');
        }

        $profileInput = $input;
        $profileInput['staff_id'] = $staffId;
        unset($profileInput['staff_ids'], $profileInput['granularity']);
        $factsResult = $this->analytics->facts($profileInput, $context);
        $filters = $factsResult['filters'];
        $permissionScope = $factsResult['permission_scope'];
        $staff = $this->staffIdentity($staffId, $filters, $permissionScope);
        $obligations = $this->obligationRows($staffId, $filters, $permissionScope);
        $reports = $this->reportRows($staffId, $filters, $permissionScope);
        $settlements = $this->settlementRows($staffId, $filters, $permissionScope);
        $facts = $factsResult['rows'];
        $catalog = $this->metricCatalog($facts, $obligations);
        $reportIds = array_values(array_filter(array_map(
            static fn(array $row): int => (int) ($row['report_id'] ?? 0),
            $reports
        )));
        $evidences = $this->evidenceRows($reportIds);
        $auditTasks = $this->auditRows($reportIds);
        $summary = $this->metricSummary($catalog, $facts, $obligations);
        $trend = $this->trend($granularity, $catalog, $facts, $obligations);

        $rankingInput = $input;
        unset($rankingInput['staff_id'], $rankingInput['staff_ids'], $rankingInput['granularity']);
        $rankingResult = $this->metricSelection->metricSelection($rankingInput, $context);
        $rankings = array_values(array_filter(
            $rankingResult['staff_rankings'] ?? [],
            static fn(array $row): bool => (int) ($row['staff_id'] ?? 0) === $staffId
        ));
        $topQuartile = $this->staffTopQuartileReferences($rankingResult['staff_rankings'] ?? []);
        foreach ($rankings as &$ranking) {
            $key = $this->roleMetricKey($ranking);
            $ranking['top_quartile_effective_reference'] = $topQuartile[$key] ?? 0.0;
        }
        unset($ranking);

        $comparison = $this->comparison($profileInput, $context, $catalog, $facts, $obligations, $filters, $staffId);
        return [
            'metric_version' => $rankingResult['metric_version'] ?? null,
            'metric_version_id' => $rankingResult['metric_version_id'] ?? null,
            'generated_at' => $rankingResult['generated_at'] ?? null,
            'data_cutoff_at' => $rankingResult['data_cutoff_at'] ?? null,
            'filters' => $filters + ['granularity' => $granularity],
            'source_scope' => $rankingResult['source_scope'] ?? [],
            'metric_policy' => $rankingResult['metric_policy'] ?? [],
            'permission_scope' => $permissionScope,
            'staff' => $staff,
            'period' => ['date_from' => $filters['date_from'], 'date_to' => $filters['date_to']],
            'summary' => $summary,
            'daily_records' => $this->dailyRecords($obligations, $reports, $settlements, $catalog, $facts, $evidences, $auditTasks),
            'trend' => $trend,
            'comparison' => $comparison,
            'rankings' => $rankings,
        ];
    }

    private function staffIdentity(int $staffId, array $filters, array $scope): array {
        if (($scope['scope_type'] ?? '') === 'staff' && (int) ($scope['staff_id'] ?? 0) !== $staffId) {
            throw new WorkloadAnalyticsQueryException('无权查看该员工工作量画像', 403);
        }
        $stmt = $this->pdo->prepare(
            'SELECT s.id AS staff_id, s.name AS staff_name, s.employee_no, s.role AS current_role, '
            . 's.store_id AS current_store_id, st.name AS current_store_name, s.status '
            . 'FROM staffs s LEFT JOIN stores st ON st.id = s.store_id WHERE s.id = ?'
        );
        $stmt->execute([$staffId]);
        $staff = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$staff) {
            throw new WorkloadAnalyticsQueryException('员工不存在', 404);
        }
        if (($scope['scope_type'] ?? '') === 'stores') {
            $storeIds = array_map('intval', $scope['store_ids'] ?? []);
            $allowed = in_array((int) ($staff['current_store_id'] ?? 0), $storeIds, true)
                || $this->hasHistoricalStoreAccess($staffId, $filters, $storeIds);
            if (!$allowed) {
                throw new WorkloadAnalyticsQueryException('无权查看该员工工作量画像', 403);
            }
        }
        return [
            'staff_id' => (int) $staff['staff_id'],
            'staff_name' => (string) ($staff['staff_name'] ?? ''),
            'employee_no' => (string) ($staff['employee_no'] ?? ''),
            'current_role_code' => appRoleCode((string) ($staff['current_role'] ?? '')),
            'current_store_id' => (int) ($staff['current_store_id'] ?? 0),
            'current_store_name' => (string) ($staff['current_store_name'] ?? ''),
            'status' => (int) ($staff['status'] ?? 0),
        ];
    }

    private function hasHistoricalStoreAccess(int $staffId, array $filters, array $storeIds): bool {
        if ($storeIds === []) {
            return false;
        }
        $placeholders = implode(',', array_fill(0, count($storeIds), '?'));
        $params = [$staffId, $filters['date_from'], $filters['date_to'], ...$storeIds];
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM workload_submission_obligations WHERE staff_id = ? '
            . 'AND obligation_date BETWEEN ? AND ? AND store_id IN (' . $placeholders . ') LIMIT 1'
        );
        $stmt->execute($params);
        return (bool) $stmt->fetchColumn();
    }

    private function obligationRows(int $staffId, array $filters, array $scope): array {
        $where = ['o.staff_id = ?', 'o.obligation_date BETWEEN ? AND ?'];
        $params = [$staffId, $filters['date_from'], $filters['date_to']];
        $this->appendInCondition($where, $params, 'o.store_id', $filters['store_ids']);
        $this->appendInCondition($where, $params, 'o.role_code', $filters['role_codes']);
        if (($scope['scope_type'] ?? '') === 'stores') {
            $this->appendInCondition($where, $params, 'o.store_id', $scope['store_ids'] ?? []);
        }
        $stmt = $this->pdo->prepare(
            'SELECT o.id AS obligation_id, o.obligation_date AS business_date, o.store_id, '
            . 'st.name AS store_name, o.staff_id, s.name AS staff_name, o.role_code, '
            . 'o.required_status, o.reason_code, o.completion_status, o.deadline_at, o.completed_at, o.report_id '
            . 'FROM workload_submission_obligations o LEFT JOIN stores st ON st.id = o.store_id '
            . 'LEFT JOIN staffs s ON s.id = o.staff_id WHERE ' . implode(' AND ', $where) . ' '
            . 'ORDER BY o.obligation_date, o.store_id, o.role_code'
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function reportRows(int $staffId, array $filters, array $scope): array {
        $where = ['r.staff_id = ?', 'r.report_date BETWEEN ? AND ?'];
        $params = [$staffId, $filters['date_from'], $filters['date_to']];
        foreach ([['r.store_id', 'store_ids'], ['r.role_code', 'role_codes'], ['r.submit_status', 'report_statuses'], ['r.source', 'sources']] as [$field, $filter]) {
            $this->appendInCondition($where, $params, $field, $filters[$filter]);
        }
        if (($scope['scope_type'] ?? '') === 'stores') {
            $this->appendInCondition($where, $params, 'r.store_id', $scope['store_ids'] ?? []);
        }
        $stmt = $this->pdo->prepare(
            'SELECT r.id AS report_id, r.report_date AS business_date, r.store_id, st.name AS store_name, '
            . 'r.staff_id, s.name AS staff_name, r.role_code, r.submit_status AS report_status, '
            . 'r.source, r.remarks, r.submitted_at, r.updated_at '
            . 'FROM workload_daily_reports r LEFT JOIN stores st ON st.id = r.store_id '
            . 'LEFT JOIN staffs s ON s.id = r.staff_id WHERE ' . implode(' AND ', $where) . ' '
            . 'ORDER BY r.report_date, r.store_id, r.role_code'
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function settlementRows(int $staffId, array $filters, array $scope): array {
        $where = ['settlement.staff_id = ?', 'settlement.business_date BETWEEN ? AND ?'];
        $params = [$staffId, $filters['date_from'], $filters['date_to']];
        $this->appendInCondition($where, $params, 'settlement.store_id', $filters['store_ids']);
        $this->appendInCondition($where, $params, 'settlement.role_code', $filters['role_codes']);
        if (($scope['scope_type'] ?? '') === 'stores') {
            $this->appendInCondition($where, $params, 'settlement.store_id', $scope['store_ids'] ?? []);
        }
        $stmt = $this->pdo->prepare(
            'SELECT settlement.id AS settlement_id, settlement.business_date, settlement.store_id, store.name AS store_name, '
            . 'settlement.staff_id, staff.name AS staff_name, settlement.role_code, settlement.target_points, '
            . 'settlement.reported_points, settlement.pending_points, settlement.effective_points, settlement.rejected_points, '
            . 'settlement.gap_points, settlement.settlement_status, settlement.makeup_deadline_at, '
            . 'penalty.id AS penalty_id, penalty.penalty_amount, penalty.status AS penalty_status '
            . 'FROM workload_daily_settlements settlement '
            . 'LEFT JOIN staffs staff ON staff.id = settlement.staff_id '
            . 'LEFT JOIN stores store ON store.id = settlement.store_id '
            . 'LEFT JOIN workload_penalty_records penalty ON penalty.settlement_id = settlement.id '
            . 'WHERE ' . implode(' AND ', $where) . ' ORDER BY settlement.business_date, settlement.store_id, settlement.role_code'
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function metricCatalog(array $facts, array $obligations): array {
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
        $params = array_keys($roles);
        $stmt = $this->pdo->prepare(
            'SELECT metric_code, metric_name, unit, role_code FROM metric_definitions '
            . 'WHERE is_active = 1 AND role_code IN (' . implode(',', array_fill(0, count($params), '?')) . ') '
            . 'ORDER BY role_code, sort_order, metric_code'
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function evidenceRows(array $reportIds): array {
        if ($reportIds === []) {
            return [];
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, report_id, metric_code, file_url, file_name, file_size, mime_type, created_at '
            . 'FROM workload_evidences WHERE deleted_at IS NULL AND report_id IN ('
            . implode(',', array_fill(0, count($reportIds), '?')) . ') ORDER BY created_at, id'
        );
        $stmt->execute($reportIds);
        return workloadNormalizeEvidenceRows($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private function auditRows(array $reportIds): array {
        if ($reportIds === []) {
            return [];
        }
        $stmt = $this->pdo->prepare(
            'SELECT id AS task_id, report_id, metric_code, task_version, previous_task_id, submitted_value, '
            . 'audit_status, audit_comment, auditor_staff_id, audited_at, evidence_count_at_review, created_at '
            . 'FROM workload_audit_tasks WHERE superseded_at IS NULL AND audit_status <> \'superseded\' '
            . 'AND report_id IN (' . implode(',', array_fill(0, count($reportIds), '?')) . ') '
            . 'ORDER BY report_id, metric_code, task_version DESC, id DESC'
        );
        $stmt->execute($reportIds);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function dailyRecords(array $obligations, array $reports, array $settlements, array $catalog, array $facts, array $evidences, array $audits): array {
        $records = [];
        foreach ($obligations as $row) {
            $key = $this->dayKey($row);
            $records[$key] = $row + ['report' => null, 'metrics' => []];
        }
        foreach ($reports as $report) {
            $key = $this->dayKey($report);
            $records[$key] ??= [
                'obligation_id' => 0,
                'business_date' => $report['business_date'],
                'store_id' => $report['store_id'],
                'store_name' => $report['store_name'],
                'staff_id' => $report['staff_id'],
                'staff_name' => $report['staff_name'],
                'role_code' => $report['role_code'],
                'required_status' => 'unknown',
                'reason_code' => '',
                'completion_status' => (string) $report['report_status'],
                'report_id' => $report['report_id'],
                'metrics' => [],
            ];
            $records[$key]['report'] = $report;
        }
        foreach ($settlements as $settlement) {
            $key = $this->dayKey($settlement);
            $records[$key] ??= [
                'obligation_id' => 0,
                'business_date' => $settlement['business_date'],
                'store_id' => $settlement['store_id'],
                'store_name' => $settlement['store_name'],
                'staff_id' => $settlement['staff_id'],
                'staff_name' => $settlement['staff_name'],
                'role_code' => $settlement['role_code'],
                'required_status' => 'unknown',
                'reason_code' => '',
                'completion_status' => 'unknown',
                'report_id' => 0,
                'report' => null,
                'metrics' => [],
            ];
            $records[$key]['daily_settlement'] = $settlement;
        }
        $factsByReportMetric = [];
        foreach ($facts as $fact) {
            $factsByReportMetric[(int) $fact['report_id'] . ':' . (string) $fact['metric_code']] = $fact;
        }
        $evidenceByReportMetric = [];
        foreach ($evidences as $evidence) {
            $evidenceByReportMetric[(int) $evidence['report_id'] . ':' . (string) $evidence['metric_code']][] = $evidence;
        }
        $auditByReportMetric = [];
        foreach ($audits as $audit) {
            $key = (int) $audit['report_id'] . ':' . (string) $audit['metric_code'];
            $auditByReportMetric[$key] ??= $audit;
        }
        foreach ($records as &$record) {
            $record['daily_settlement'] ??= null;
            $reportId = (int) ($record['report']['report_id'] ?? $record['report_id'] ?? 0);
            foreach ($catalog as $metric) {
                if ((string) $metric['role_code'] !== (string) $record['role_code']) {
                    continue;
                }
                $key = $reportId . ':' . (string) $metric['metric_code'];
                $fact = $factsByReportMetric[$key] ?? [];
                $metricRow = [
                    'metric_code' => (string) $metric['metric_code'],
                    'metric_name' => (string) $metric['metric_name'],
                    'unit' => (string) $metric['unit'],
                    'raw_value' => (float) ($fact['raw_value'] ?? 0),
                    'pending_value' => (float) ($fact['pending_value'] ?? 0),
                    'effective_value' => (float) ($fact['effective_value'] ?? 0),
                    'rejected_value' => (float) ($fact['rejected_value'] ?? 0),
                    'audit_status' => (string) ($fact['audit_status'] ?? ''),
                    'evidence_count' => count($evidenceByReportMetric[$key] ?? []),
                    'evidences' => $evidenceByReportMetric[$key] ?? [],
                    'audit_task' => $auditByReportMetric[$key] ?? null,
                ];
                $record['metrics'][] = $metricRow;
            }
        }
        unset($record);
        ksort($records);
        return array_values($records);
    }

    private function metricSummary(array $catalog, array $facts, array $obligations): array {
        $factsByKey = [];
        foreach ($facts as $fact) {
            $factsByKey[$this->roleMetricKey($fact)][] = $fact;
        }
        $requiredByRole = [];
        foreach ($obligations as $obligation) {
            if (($obligation['required_status'] ?? '') === 'required') {
                $role = (string) $obligation['role_code'];
                $requiredByRole[$role] = ($requiredByRole[$role] ?? 0) + 1;
            }
        }
        $rows = [];
        foreach ($catalog as $metric) {
            $role = (string) $metric['role_code'];
            $required = (int) ($requiredByRole[$role] ?? 0);
            $aggregate = $this->analytics->aggregateByMetric($factsByKey[$this->roleMetricKey($metric)] ?? [], $required);
            $row = $aggregate[0] ?? $this->emptyAggregate($metric, $required);
            $row['role_code'] = $role;
            $rows[] = $row;
        }
        return $rows;
    }

    private function trend(string $granularity, array $catalog, array $facts, array $obligations): array {
        $buckets = [];
        foreach ([...$facts, ...$obligations] as $row) {
            $date = (string) ($row['business_date'] ?? '');
            if ($date === '') {
                continue;
            }
            $bucket = $this->periodKey($date, $granularity);
            $buckets[$bucket]['date_from'] = isset($buckets[$bucket]['date_from'])
                ? min($buckets[$bucket]['date_from'], $date) : $date;
            $buckets[$bucket]['date_to'] = isset($buckets[$bucket]['date_to'])
                ? max($buckets[$bucket]['date_to'], $date) : $date;
        }
        foreach ($buckets as $bucket => &$period) {
            $periodFacts = array_values(array_filter($facts, fn(array $row): bool => $this->periodKey((string) $row['business_date'], $granularity) === $bucket));
            $periodObligations = array_values(array_filter($obligations, fn(array $row): bool => $this->periodKey((string) $row['business_date'], $granularity) === $bucket));
            $period['period_key'] = $bucket;
            $period['metrics'] = $this->metricSummary($catalog, $periodFacts, $periodObligations);
        }
        unset($period);
        ksort($buckets);
        return array_values($buckets);
    }

    private function comparison(
        array $profileInput,
        array $context,
        array $catalog,
        array $facts,
        array $obligations,
        array $filters,
        int $staffId
    ): array {
        $businessDays = $this->businessDayCount($filters['date_from'], $filters['date_to']);
        if ($businessDays === 0) {
            return ['business_day_count' => 0, 'previous_period' => null, 'metrics' => []];
        }
        $current = $this->metricSummary(
            $catalog,
            $this->businessDayRows($facts),
            $this->businessDayRows($obligations)
        );
        $periods = [];
        $cursor = (new DateTimeImmutable($filters['date_from']))->modify('-1 day');
        for ($index = 0; $index < 4; $index++) {
            [$from, $to] = $this->previousBusinessPeriod($cursor, $businessDays);
            $periodInput = $profileInput;
            $periodInput['date_from'] = $from;
            $periodInput['date_to'] = $to;
            $factsResult = $this->analytics->facts($periodInput, $context);
            $obligations = $this->obligationRows($staffId, $factsResult['filters'], $factsResult['permission_scope']);
            $periods[] = [
                'date_from' => $from,
                'date_to' => $to,
                'summary' => $this->metricSummary(
                    $catalog,
                    $this->businessDayRows($factsResult['rows']),
                    $this->businessDayRows($obligations)
                ),
            ];
            $cursor = (new DateTimeImmutable($from))->modify('-1 day');
        }
        $priorMaps = array_map(fn(array $period): array => $this->rowsByRoleMetric($period['summary']), $periods);
        $rows = [];
        foreach ($current as $row) {
            $key = $this->roleMetricKey($row);
            $currentValue = (float) ($row['effective_value'] ?? 0);
            $previousValue = (float) ($priorMaps[0][$key]['effective_value'] ?? 0);
            $pastValues = array_map(static fn(array $map): float => (float) ($map[$key]['effective_value'] ?? 0), $priorMaps);
            $rows[] = array_merge([
                'role_code' => (string) $row['role_code'],
                'metric_code' => (string) $row['metric_code'],
            ], $this->comparisonService->compare(
                $currentValue,
                $previousValue,
                (int) ($row['sample_size'] ?? 0),
                (int) ($priorMaps[0][$key]['sample_size'] ?? 0),
                (bool) ($row['low_sample'] ?? true),
                (bool) ($priorMaps[0][$key]['low_sample'] ?? true),
                $pastValues
            ));
        }
        return [
            'business_day_count' => $businessDays,
            'previous_period' => ['date_from' => $periods[0]['date_from'], 'date_to' => $periods[0]['date_to']],
            'past_four_periods' => array_map(static fn(array $period): array => [
                'date_from' => $period['date_from'], 'date_to' => $period['date_to'],
            ], $periods),
            'metrics' => $rows,
        ];
    }

    private function previousBusinessPeriod(DateTimeImmutable $cursor, int $businessDays): array {
        $selected = [];
        while (count($selected) < $businessDays) {
            if ((int) $cursor->format('N') !== 1) {
                $selected[] = $cursor->format('Y-m-d');
            }
            $cursor = $cursor->modify('-1 day');
        }
        sort($selected);
        return [$selected[0], $selected[count($selected) - 1]];
    }

    private function businessDayCount(string $from, string $to): int {
        $count = 0;
        for ($date = new DateTimeImmutable($from), $end = new DateTimeImmutable($to); $date <= $end; $date = $date->modify('+1 day')) {
            if ((int) $date->format('N') !== 1) {
                $count++;
            }
        }
        return $count;
    }

    private function businessDayRows(array $rows): array {
        return array_values(array_filter($rows, static function (array $row): bool {
            $date = (string) ($row['business_date'] ?? '');
            return $date !== '' && (int) (new DateTimeImmutable($date))->format('N') !== 1;
        }));
    }

    private function staffTopQuartileReferences(array $rows): array {
        $groups = [];
        foreach ($rows as $row) {
            $groups[$this->roleMetricKey($row)][] = (float) ($row['effective_value'] ?? 0);
        }
        $references = [];
        foreach ($groups as $key => $values) {
            $references[$key] = $this->comparisonService->topQuartileReference($values);
        }
        return $references;
    }

    private function rowsByRoleMetric(array $rows): array {
        $result = [];
        foreach ($rows as $row) {
            $result[$this->roleMetricKey($row)] = $row;
        }
        return $result;
    }

    private function emptyAggregate(array $metric, int $required): array {
        $ratio = ['numerator' => 0, 'denominator' => 0, 'value' => 0.0];
        $average = static fn(int $denominator): array => ['numerator' => 0.0, 'denominator' => $denominator, 'value' => 0.0];
        return [
            'metric_code' => (string) $metric['metric_code'], 'metric_name' => (string) $metric['metric_name'],
            'unit' => (string) $metric['unit'], 'required_obligation_days' => $required, 'sample_size' => 0,
            'submitted_report_count' => 0, 'submitted_staff_count' => 0, 'positive_staff_count' => 0,
            'submitted_store_count' => 0, 'positive_store_count' => 0, 'positive_raw_report_count' => 0,
            'positive_effective_report_count' => 0, 'zero_raw_report_count' => 0, 'low_sample' => true,
            'sample_thresholds' => ['submitted_reports' => 10, 'submitted_staff' => 3],
            'raw_value' => 0.0, 'pending_value' => 0.0, 'effective_value' => 0.0, 'rejected_value' => 0.0,
            'selection_rate' => $ratio, 'effective_selection_rate' => $ratio, 'zero_rate' => $ratio,
            'staff_coverage' => $ratio, 'store_coverage' => $ratio,
            'all_staff_average' => $average(0), 'participant_staff_average' => $average(0),
            'per_obligation_day_average' => $average($required),
        ];
    }

    private function periodKey(string $date, string $granularity): string {
        $value = new DateTimeImmutable($date);
        if ($granularity === 'week') {
            return $value->format('o-\\WW');
        }
        return $granularity === 'month' ? $value->format('Y-m') : $date;
    }

    private function dayKey(array $row): string {
        return (string) ($row['business_date'] ?? '') . ':' . (int) ($row['store_id'] ?? 0) . ':' . (string) ($row['role_code'] ?? '');
    }

    private function roleMetricKey(array $row): string {
        return (string) ($row['role_code'] ?? '') . ':' . (string) ($row['metric_code'] ?? '');
    }

    private function positiveInt(mixed $value, string $label): int {
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($parsed === false) {
            throw new WorkloadAnalyticsQueryException($label . '必须为正整数');
        }
        return (int) $parsed;
    }

    private function appendInCondition(array &$where, array &$params, string $field, array $values): void {
        if ($values === []) {
            return;
        }
        $where[] = $field . ' IN (' . implode(',', array_fill(0, count($values), '?')) . ')';
        array_push($params, ...$values);
    }
}
