<?php
declare(strict_types=1);

require_once __DIR__ . '/WorkloadConversionRuleService.php';

final class WorkloadReportConversionResultService {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function recalculate(int $reportId): array {
        if (!$this->pdo->inTransaction()) {
            throw new WorkloadConversionRuleException('换算结果必须在日报事务中更新');
        }
        if ($reportId <= 0) {
            throw new WorkloadConversionRuleException('日报 ID 无效');
        }

        $report = $this->loadReport($reportId);
        if ((string) $report['submit_status'] !== 'submitted') {
            return [];
        }

        $existing = $this->existingSnapshots($reportId);
        if ($existing === []) {
            $rules = $this->initialRules((string) $report['role_code'], (string) $report['report_date']);
            if ($rules === []) {
                return [];
            }
            $existing = array_map(static fn(array $rule): array => [
                'conversion_rule_id' => (int) $rule['id'],
                'rule' => $rule,
            ], $rules);
        }

        $values = $this->valuesForReport($reportId);
        $evidenceCounts = $this->evidenceCountsForReport($reportId);
        $upsert = $this->pdo->prepare(
            'INSERT INTO workload_report_conversion_results '
            . '(report_id, conversion_rule_id, rule_snapshot_json, raw_value, pending_points, effective_points, rejected_points, completion_state, explanation) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE rule_snapshot_json = VALUES(rule_snapshot_json), raw_value = VALUES(raw_value), '
            . 'pending_points = VALUES(pending_points), effective_points = VALUES(effective_points), '
            . 'rejected_points = VALUES(rejected_points), completion_state = VALUES(completion_state), '
            . 'explanation = VALUES(explanation), updated_at = NOW()'
        );

        $results = [];
        foreach ($existing as $entry) {
            $rule = $entry['rule'];
            $evaluation = WorkloadConversionRuleService::evaluate(
                $rule,
                $values,
                $evidenceCounts,
                $this->auditStatusForRule($reportId, $rule['metric_codes'])
            );
            $snapshot = json_encode($evaluation['rule_snapshot'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $upsert->execute([
                $reportId,
                (int) $entry['conversion_rule_id'],
                $snapshot,
                $evaluation['raw_value'],
                $evaluation['pending_points'],
                $evaluation['effective_points'],
                $evaluation['rejected_points'],
                $evaluation['completion_state'],
                $evaluation['explanation'],
            ]);
            $results[] = $evaluation;
        }
        return $results;
    }

    private function loadReport(int $reportId): array {
        $stmt = $this->pdo->prepare(
            'SELECT id, report_date, role_code, submit_status FROM workload_daily_reports WHERE id = ? FOR UPDATE'
        );
        $stmt->execute([$reportId]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$report) {
            throw new WorkloadConversionRuleException('日报不存在');
        }
        return $report;
    }

    private function existingSnapshots(int $reportId): array {
        $stmt = $this->pdo->prepare(
            'SELECT conversion_rule_id, rule_snapshot_json FROM workload_report_conversion_results '
            . 'WHERE report_id = ? ORDER BY conversion_rule_id FOR UPDATE'
        );
        $stmt->execute([$reportId]);
        $snapshots = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $snapshots[] = [
                'conversion_rule_id' => (int) $row['conversion_rule_id'],
                'rule' => WorkloadConversionRuleService::normalizeRule((string) $row['rule_snapshot_json']),
            ];
        }
        return $snapshots;
    }

    private function initialRules(string $roleCode, string $businessDate): array {
        $available = $this->pdo->prepare(
            "SELECT 1 FROM workload_conversion_rule_versions WHERE role_code = ? AND status IN ('active', 'scheduled') "
            . 'AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?) LIMIT 1'
        );
        $available->execute([$roleCode, $businessDate, $businessDate]);
        if (!$available->fetchColumn()) {
            return [];
        }
        return (new WorkloadConversionRuleService($this->pdo))->activeForDate($roleCode, $businessDate)['rules'];
    }

    private function valuesForReport(int $reportId): array {
        $stmt = $this->pdo->prepare(
            'SELECT metric.metric_code, value.numeric_value FROM workload_daily_report_values value '
            . 'JOIN metric_definitions metric ON metric.id = value.metric_id WHERE value.report_id = ?'
        );
        $stmt->execute([$reportId]);
        $values = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $values[(string) $row['metric_code']][] = (float) $row['numeric_value'];
        }
        return $values;
    }

    private function evidenceCountsForReport(int $reportId): array {
        $stmt = $this->pdo->prepare(
            'SELECT metric_code, COUNT(*) AS evidence_count FROM workload_evidences '
            . 'WHERE report_id = ? AND deleted_at IS NULL GROUP BY metric_code'
        );
        $stmt->execute([$reportId]);
        $counts = ['image' => 0, 'screenshot' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $count = (int) $row['evidence_count'];
            $counts[(string) $row['metric_code']] = $count;
            $counts['image'] += $count;
            $counts['screenshot'] += $count;
        }
        $confirmationStmt = $this->pdo->prepare(
            'SELECT metric_code, COUNT(*) AS confirmation_count FROM workload_management_confirmations '
            . 'WHERE report_id = ? AND revoked_at IS NULL GROUP BY metric_code'
        );
        $confirmationStmt->execute([$reportId]);
        foreach ($confirmationStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $count = (int) $row['confirmation_count'];
            $counts[(string) $row['metric_code']] = ($counts[(string) $row['metric_code']] ?? 0) + $count;
            $counts['manager_confirmation'] = ($counts['manager_confirmation'] ?? 0) + $count;
        }
        return $counts;
    }

    private function auditStatusForRule(int $reportId, array $metricCodes): string {
        if ($metricCodes === []) {
            return 'approved';
        }
        $placeholders = implode(',', array_fill(0, count($metricCodes), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT audit_status FROM workload_audit_tasks WHERE report_id = ? '
            . "AND superseded_at IS NULL AND audit_status <> 'superseded' AND metric_code IN ($placeholders)"
        );
        $stmt->execute(array_merge([$reportId], $metricCodes));
        $statuses = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if ($statuses === []) {
            return 'approved';
        }
        if (array_intersect($statuses, ['rejected', 'needs_resubmit']) !== []) {
            return 'rejected';
        }
        return in_array('pending', $statuses, true) ? 'pending' : 'approved';
    }
}
