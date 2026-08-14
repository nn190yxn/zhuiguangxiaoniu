<?php
declare(strict_types=1);

require_once __DIR__ . '/WorkloadEffectiveValueService.php';

final class WorkloadConversionResultService {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function refreshReport(int $reportId): array {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('日报换算必须在同一事务内刷新');
        }
        $reportStmt = $this->pdo->prepare(
            'SELECT id, report_date, rule_version_id FROM workload_daily_reports WHERE id = ? FOR UPDATE'
        );
        $reportStmt->execute([$reportId]);
        $report = $reportStmt->fetch(PDO::FETCH_ASSOC);
        if (!$report || (int) ($report['rule_version_id'] ?? 0) <= 0) return [];

        $rulesStmt = $this->pdo->prepare(
            'SELECT conversion_rule.id, conversion_rule.rule_code, conversion_rule.metric_codes_json, '
            . 'conversion_rule.conversion_mode, conversion_rule.threshold_value, conversion_rule.points_per_match, '
            . 'conversion_rule.daily_cap_points, conversion_rule.tiers_json, conversion_rule.requires_all_metrics, '
            . 'role_rule.audit_mode, role_rule.metric_name_snapshot, role_rule.unit_snapshot '
            . 'FROM workload_conversion_rule_versions version '
            . 'INNER JOIN workload_conversion_rules conversion_rule ON conversion_rule.rule_version_id = version.id '
            . "LEFT JOIN workload_role_metric_rules role_rule ON role_rule.rule_version_id = version.source_role_rule_version_id "
            . "AND role_rule.metric_code = JSON_UNQUOTE(JSON_EXTRACT(conversion_rule.metric_codes_json, '$[0]')) "
            . 'WHERE version.source_role_rule_version_id = ? AND version.status IN (\'active\', \'scheduled\') '
            . 'ORDER BY conversion_rule.id FOR UPDATE'
        );
        $rulesStmt->execute([(int) $report['rule_version_id']]);
        $rules = $rulesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $upsert = $this->pdo->prepare(
            'INSERT INTO workload_report_conversion_results '
            . '(report_id, conversion_rule_id, rule_snapshot_json, raw_value, reported_points, pending_points, effective_points, rejected_points, completion_state, explanation) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE rule_snapshot_json = VALUES(rule_snapshot_json), raw_value = VALUES(raw_value), '
            . 'reported_points = VALUES(reported_points), pending_points = VALUES(pending_points), effective_points = VALUES(effective_points), '
            . 'rejected_points = VALUES(rejected_points), completion_state = VALUES(completion_state), '
            . 'explanation = VALUES(explanation), updated_at = CURRENT_TIMESTAMP'
        );
        $results = [];
        foreach ($rules as $rule) {
            $metricCodes = json_decode((string) $rule['metric_codes_json'], true) ?: [];
            $metricCode = (string) ($metricCodes[0] ?? '');
            if ($metricCode === '') continue;
            $metricValues = $this->metricValues($reportId, $metricCodes);
            $value = round(array_sum($metricValues), 2);
            $conversionMode = (string) $rule['conversion_mode'];
            $points = $this->convertedPoints(
                $metricValues,
                $conversionMode,
                $this->nullableFloat($rule['points_per_match'] ?? null),
                $this->nullableFloat($rule['threshold_value'] ?? null),
                $this->nullableFloat($rule['daily_cap_points'] ?? null),
                json_decode((string) ($rule['tiers_json'] ?? ''), true) ?: [],
                (bool) ($rule['requires_all_metrics'] ?? false)
            );
            $effective = WorkloadEffectiveValueService::calculate(
                $points,
                (string) ($rule['audit_mode'] ?? 'none'),
                $this->auditStatus($reportId, $metricCode),
                true
            );
            $snapshot = [
                'rule_code' => (string) $rule['rule_code'],
                'metric_codes' => $metricCodes,
                'conversion_mode' => $conversionMode,
                'threshold_value' => (float) $rule['threshold_value'],
                'points_per_match' => $this->nullableFloat($rule['points_per_match'] ?? null),
                'daily_cap_points' => $this->nullableFloat($rule['daily_cap_points'] ?? null),
                'tiers' => json_decode((string) ($rule['tiers_json'] ?? ''), true) ?: [],
                'metric_name' => (string) ($rule['metric_name_snapshot'] ?? $metricCode),
                'unit' => (string) ($rule['unit_snapshot'] ?? ''),
                'audit_mode' => (string) ($rule['audit_mode'] ?? 'none'),
            ];
            $completionState = $points >= 1.0 ? ($effective['pending_value'] > 0 ? 'pending_review' : 'met') : 'not_met';
            if ($effective['rejected_value'] > 0) $completionState = 'rejected';
            $upsert->execute([
                $reportId,
                (int) $rule['id'],
                json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                $value,
                $points,
                $effective['pending_value'],
                $effective['effective_value'],
                $effective['rejected_value'],
                $completionState,
                $this->explanation($snapshot, $points),
            ]);
            $results[] = ['conversion_rule_id' => (int) $rule['id'], 'completion_state' => $completionState];
        }
        return $results;
    }

    private function metricValues(int $reportId, array $metricCodes): array {
        $values = [];
        foreach ($metricCodes as $metricCode) {
            $code = trim((string) $metricCode);
            if ($code === '') continue;
            $values[$code] = $this->metricValue($reportId, $code);
        }
        return $values;
    }

    private function metricValue(int $reportId, string $metricCode): float {
        $stmt = $this->pdo->prepare(
            'SELECT value.numeric_value FROM workload_daily_report_values value '
            . 'INNER JOIN metric_definitions metric ON metric.id = value.metric_id '
            . 'WHERE value.report_id = ? AND metric.metric_code = ? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$reportId, $metricCode]);
        return round((float) ($stmt->fetchColumn() ?: 0), 2);
    }

    private function convertedPoints(
        array $metricValues,
        string $conversionMode,
        ?float $pointsPerMatch,
        ?float $thresholdValue,
        ?float $dailyCapPoints,
        array $tiers,
        bool $requiresAllMetrics
    ): float {
        $values = array_values($metricValues);
        $hasMetric = $requiresAllMetrics
            ? count(array_filter($values, static fn(float $value): bool => $value > 0)) === count($values)
            : count(array_filter($values, static fn(float $value): bool => $value > 0)) > 0;
        if (!$hasMetric || $values === []) return 0.0;

        $rawValue = array_sum($values);
        $points = 0.0;
        if ($conversionMode === 'threshold' && $thresholdValue !== null && $pointsPerMatch !== null) {
            $points = $rawValue >= $thresholdValue ? $pointsPerMatch : 0.0;
        } elseif ($conversionMode === 'step' && $thresholdValue !== null && $thresholdValue > 0 && $pointsPerMatch !== null) {
            $points = floor($rawValue / $thresholdValue) * $pointsPerMatch;
        } elseif ($conversionMode === 'tier') {
            foreach ($values as $value) $points += $this->tierPoints($value, $tiers);
        } elseif ($conversionMode === 'composite' && $thresholdValue !== null && $thresholdValue > 0 && $pointsPerMatch !== null) {
            $componentPoints = array_map(static fn(float $value): float => floor($value / $thresholdValue) * $pointsPerMatch, $values);
            $allComponentsMatch = count(array_filter($componentPoints, static fn(float $value): bool => $value > 0)) === count($componentPoints);
            $points = (!$requiresAllMetrics || $allComponentsMatch) ? array_sum($componentPoints) : 0.0;
        }
        if ($dailyCapPoints !== null) $points = min($points, $dailyCapPoints);
        return round($points, 2);
    }

    private function tierPoints(float $value, array $tiers): float {
        $matches = [];
        foreach ($tiers as $tier) {
            if (!is_array($tier)) continue;
            $min = $this->nullableFloat($tier['min'] ?? $tier['minimum'] ?? null) ?? 0.0;
            $max = $this->nullableFloat($tier['max'] ?? $tier['maximum'] ?? null);
            if ($value >= $min && ($max === null || $value <= $max)) {
                $matches[] = [
                    'points' => $this->nullableFloat($tier['points'] ?? null) ?? 0.0,
                    'priority' => (int) ($tier['priority'] ?? 0),
                    'min' => $min,
                ];
            }
        }
        usort($matches, static fn(array $left, array $right): int => [$right['priority'], $right['min']] <=> [$left['priority'], $left['min']]);
        return $matches[0]['points'] ?? 0.0;
    }

    private function nullableFloat($value): ?float {
        return $value === null || $value === '' ? null : (is_numeric($value) ? (float) $value : null);
    }

    private function explanation(array $snapshot, float $points): string {
        if (($snapshot['conversion_mode'] ?? '') === 'tier' && ($snapshot['rule_code'] ?? '') === 'sales-deal-amount-tier') {
            return sprintf('%s大于 0 元计 1 点，满 4000 元计 2 点，本次计 %.2f 点', $snapshot['metric_name'], $points);
        }
        return sprintf('%s 达到 %.2f 计 1 点', $snapshot['metric_name'], (float) $snapshot['threshold_value']);
    }

    private function auditStatus(int $reportId, string $metricCode): ?string {
        $stmt = $this->pdo->prepare(
            "SELECT audit_status FROM workload_audit_tasks WHERE report_id = ? AND metric_code = ? "
            . "AND superseded_at IS NULL AND audit_status <> 'superseded' ORDER BY task_version DESC, id DESC LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$reportId, $metricCode]);
        $status = $stmt->fetchColumn();
        return $status === false ? null : (string) $status;
    }
}
