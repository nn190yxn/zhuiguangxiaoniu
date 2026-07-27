<?php
declare(strict_types=1);

require_once __DIR__ . '/WorkloadAlertService.php';
require_once __DIR__ . '/WorkloadBusinessPeriodService.php';

final class WorkloadRecommendationService {
    private const LOW_SELECTION_THRESHOLD = 30.0;
    private const TREND_DROP_THRESHOLD = 20.0;
    private const MINIMUM_REPORT_SAMPLE = 10;
    private const MINIMUM_STAFF_SAMPLE = 3;
    private const MANAGED_RULES = [
        'store_completion_yellow',
        'store_completion_red',
        'metric_low_selection_recommendation',
        'metric_downward_trend_recommendation',
    ];

    private PDO $pdo;
    private WorkloadAlertService $alerts;
    private WorkloadBusinessPeriodService $periods;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->alerts = new WorkloadAlertService($pdo);
        $this->periods = new WorkloadBusinessPeriodService();
    }

    public function evaluate(string $anchorDate): array {
        $period = $this->periods->resolve(['period_type' => 'business_week', 'anchor_date' => $anchorDate]);
        $current = $period['comparison_current_period'];
        $previous = $period['comparison_previous_period'];
        if ($current['date_from'] === null || $previous['date_from'] === null) {
            return ['candidate_count' => 0, 'event_count' => 0, 'closed_count' => 0];
        }

        $rules = $this->alerts->rules();
        $candidates = array_merge(
            $this->completionCandidates($anchorDate, $current, $rules),
            $this->metricCandidates($anchorDate, $current, $previous)
        );
        $result = $this->alerts->syncCandidates($anchorDate, $candidates, self::MANAGED_RULES);
        return [
            'period' => $period,
            'candidate_count' => count($candidates),
            'event_count' => count($result['events']),
            'closed_count' => $result['closed_count'],
        ];
    }

    private function completionCandidates(string $businessDate, array $period, array $rules): array {
        $stmt = $this->pdo->prepare(
            "SELECT store_id, COUNT(*) AS required_count, COUNT(DISTINCT staff_id) AS staff_count, "
            . "SUM(CASE WHEN completion_status IN ('submitted', 'corrected') THEN 1 ELSE 0 END) AS submitted_count "
            . "FROM workload_submission_obligations WHERE required_status = 'required' "
            . 'AND obligation_date BETWEEN ? AND ? GROUP BY store_id'
        );
        $stmt->execute([$period['date_from'], $period['date_to']]);
        $periodKey = $period['date_from'] . '..' . $period['date_to'];
        $candidates = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $required = (int) $row['required_count'];
            $staffCount = (int) $row['staff_count'];
            $submitted = (int) $row['submitted_count'];
            $rate = $required > 0 ? round($submitted * 100 / $required, 4) : 0.0;
            foreach (['store_completion_yellow', 'store_completion_red'] as $code) {
                $rule = $rules[$code] ?? null;
                if (!$rule
                    || $required < (int) $rule['minimum_report_sample']
                    || $staffCount < (int) $rule['minimum_staff_sample']) {
                    continue;
                }
                $operator = (string) $rule['comparison_operator'];
                $threshold = (float) $rule['threshold_value'];
                if (!$this->matches($rate, $operator, $threshold)) {
                    continue;
                }
                $candidates[] = $this->candidate([
                    'rule_code' => $code,
                    'business_date' => $businessDate,
                    'period_type' => 'business_week',
                    'period_key' => $periodKey,
                    'store_id' => (int) $row['store_id'],
                    'target_role_code' => (string) $rule['target_role_code'],
                    'severity' => (string) $rule['severity'],
                    'numerator' => $submitted,
                    'denominator' => $required,
                    'current_value' => $rate,
                    'threshold_value' => $threshold,
                    'evidence' => [
                        'date_from' => $period['date_from'],
                        'date_to' => $period['date_to'],
                        'submitted_count' => $submitted,
                        'required_count' => $required,
                        'staff_count' => $staffCount,
                        'action' => '核对草稿和缺交名单并安排当日跟进',
                    ],
                ]);
            }
        }
        return $candidates;
    }

    private function metricCandidates(string $businessDate, array $current, array $previous): array {
        $currentRows = $this->metricSelectionRows($current['date_from'], $current['date_to']);
        $previousRows = $this->metricSelectionRows($previous['date_from'], $previous['date_to']);
        $previousByKey = [];
        foreach ($previousRows as $row) {
            $previousByKey[$this->metricKey($row)] = $row;
        }
        $periodKey = $current['date_from'] . '..' . $current['date_to'];
        $candidates = [];
        foreach ($currentRows as $row) {
            $reportCount = (int) $row['submitted_report_count'];
            $staffCount = (int) $row['submitted_staff_count'];
            if ($reportCount < self::MINIMUM_REPORT_SAMPLE || $staffCount < self::MINIMUM_STAFF_SAMPLE) {
                continue;
            }
            $positiveCount = (int) $row['positive_report_count'];
            $rate = round($positiveCount * 100 / $reportCount, 4);
            $evidence = [
                'date_from' => $current['date_from'],
                'date_to' => $current['date_to'],
                'submitted_report_count' => $reportCount,
                'submitted_staff_count' => $staffCount,
                'positive_report_count' => $positiveCount,
            ];
            if ($rate <= self::LOW_SELECTION_THRESHOLD) {
                $candidates[] = $this->candidate([
                    'rule_code' => 'metric_low_selection_recommendation',
                    'business_date' => $businessDate,
                    'period_type' => 'business_week',
                    'period_key' => $periodKey,
                    'store_id' => (int) $row['store_id'],
                    'role_code' => (string) $row['role_code'],
                    'metric_code' => (string) $row['metric_code'],
                    'target_role_code' => 'manager',
                    'severity' => 'info',
                    'numerator' => $positiveCount,
                    'denominator' => $reportCount,
                    'current_value' => $rate,
                    'threshold_value' => self::LOW_SELECTION_THRESHOLD,
                    'evidence' => $evidence + ['action' => '确认项目适用性并辅导员工在真实发生时填写'],
                ]);
            }

            $previousRow = $previousByKey[$this->metricKey($row)] ?? null;
            if (!$previousRow
                || (int) $previousRow['submitted_report_count'] < self::MINIMUM_REPORT_SAMPLE
                || (int) $previousRow['submitted_staff_count'] < self::MINIMUM_STAFF_SAMPLE) {
                continue;
            }
            $previousRate = round(
                (int) $previousRow['positive_report_count'] * 100 / (int) $previousRow['submitted_report_count'],
                4
            );
            $change = round($rate - $previousRate, 4);
            if ($change > -self::TREND_DROP_THRESHOLD) {
                continue;
            }
            $candidates[] = $this->candidate([
                'rule_code' => 'metric_downward_trend_recommendation',
                'business_date' => $businessDate,
                'period_type' => 'business_week',
                'period_key' => $periodKey,
                'store_id' => (int) $row['store_id'],
                'role_code' => (string) $row['role_code'],
                'metric_code' => (string) $row['metric_code'],
                'target_role_code' => 'manager',
                'severity' => 'warning',
                'numerator' => $positiveCount,
                'denominator' => $reportCount,
                'current_value' => $rate,
                'threshold_value' => -self::TREND_DROP_THRESHOLD,
                'evidence' => $evidence + [
                    'previous_date_from' => $previous['date_from'],
                    'previous_date_to' => $previous['date_to'],
                    'previous_value' => $previousRate,
                    'change_value' => $change,
                    'previous_sample_size' => (int) $previousRow['submitted_report_count'],
                    'action' => '复核环比下降原因并确认业务动作',
                ],
            ]);
        }
        return $candidates;
    }

    private function metricSelectionRows(string $dateFrom, string $dateTo): array {
        $stmt = $this->pdo->prepare(
            'SELECT report.store_id, report.role_code, metric.metric_code, '
            . 'COUNT(DISTINCT report.id) AS submitted_report_count, '
            . 'COUNT(DISTINCT report.staff_id) AS submitted_staff_count, '
            . 'COUNT(DISTINCT CASE WHEN value.numeric_value > 0 THEN report.id END) AS positive_report_count '
            . 'FROM workload_daily_reports report '
            . 'INNER JOIN workload_daily_report_values value ON value.report_id = report.id '
            . 'INNER JOIN metric_definitions metric ON metric.id = value.metric_id '
            . 'LEFT JOIN workload_source_policies source_policy ON source_policy.source_code = report.source '
            . "WHERE report.submit_status = 'submitted' AND report.report_date BETWEEN ? AND ? "
            . "AND COALESCE(source_policy.included_by_default, report.source IN ('h5', 'mini_program')) = 1 "
            . 'GROUP BY report.store_id, report.role_code, metric.metric_code'
        );
        $stmt->execute([$dateFrom, $dateTo]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function candidate(array $candidate): array {
        return array_merge([
            'store_id' => 0,
            'staff_id' => 0,
            'role_code' => '',
            'metric_code' => '',
            'numerator' => 0,
            'denominator' => 0,
            'current_value' => 0,
            'threshold_value' => 0,
            'evidence' => [],
        ], $candidate);
    }

    private function metricKey(array $row): string {
        return (int) $row['store_id'] . ':' . (string) $row['role_code'] . ':' . (string) $row['metric_code'];
    }

    private function matches(float $value, string $operator, float $threshold): bool {
        return match ($operator) {
            '<' => $value < $threshold,
            '<=' => $value <= $threshold,
            '>' => $value > $threshold,
            '>=' => $value >= $threshold,
            '=' => abs($value - $threshold) < 0.0001,
            default => throw new RuntimeException('建议规则比较运算符无效'),
        };
    }
}
