<?php
declare(strict_types=1);

final class WorkloadConversionResultQueryService {
    private const REPORT_ID_BATCH_SIZE = 500;

    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function forReport(int $reportId): array {
        $results = $this->forReports([$reportId]);
        return $results[$reportId] ?? [];
    }

    public function forReports(array $reportIds): array {
        $reportIds = array_values(array_unique(array_filter(array_map('intval', $reportIds), static fn(int $id): bool => $id > 0)));
        if ($reportIds === []) {
            return [];
        }
        $grouped = [];
        foreach (array_chunk($reportIds, self::REPORT_ID_BATCH_SIZE) as $batch) {
            $stmt = $this->pdo->prepare(
                'SELECT result.report_id, result.conversion_rule_id, result.rule_snapshot_json, result.raw_value, '
                . 'result.pending_points, result.effective_points, result.rejected_points, result.completion_state, result.explanation, '
                . 'version.version_code AS rule_version_code '
                . 'FROM workload_report_conversion_results result '
                . 'LEFT JOIN workload_conversion_rules rule_detail ON rule_detail.id = result.conversion_rule_id '
                . 'LEFT JOIN workload_conversion_rule_versions version ON version.id = rule_detail.rule_version_id '
                . 'WHERE result.report_id IN (' . implode(',', array_fill(0, count($batch), '?')) . ') '
                . 'ORDER BY result.report_id, result.conversion_rule_id'
            );
            $stmt->execute($batch);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $reportId = (int) $row['report_id'];
                try {
                    $snapshot = json_decode((string) $row['rule_snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    $snapshot = [];
                }
                $grouped[$reportId][] = [
                    'conversion_rule_id' => (int) $row['conversion_rule_id'],
                    'rule_version_code' => (string) ($row['rule_version_code'] ?? ''),
                    'rule_code' => (string) ($snapshot['rule_code'] ?? ''),
                    'metric_codes' => array_values($snapshot['metric_codes'] ?? []),
                    'conversion_mode' => (string) ($snapshot['conversion_mode'] ?? ''),
                    'raw_value' => round((float) $row['raw_value'], 2),
                    'pending_points' => round((float) $row['pending_points'], 2),
                    'effective_points' => round((float) $row['effective_points'], 2),
                    'rejected_points' => round((float) $row['rejected_points'], 2),
                    'completion_state' => (string) $row['completion_state'],
                    'explanation' => (string) $row['explanation'],
                ];
            }
        }
        return $grouped;
    }

    public function summaryForScope(array $filters, array $permissionScope): ?array {
        $where = ['report.report_date BETWEEN ? AND ?'];
        $params = [(string) $filters['date_from'], (string) $filters['date_to']];
        $this->appendInCondition($where, $params, 'report.store_id', $filters['store_ids'] ?? []);
        $this->appendInCondition($where, $params, 'report.staff_id', $filters['staff_ids'] ?? []);
        $this->appendInCondition($where, $params, 'report.role_code', $filters['role_codes'] ?? []);
        $this->appendInCondition($where, $params, 'report.submit_status', $filters['report_statuses'] ?? []);
        $this->appendInCondition($where, $params, 'report.source', $filters['sources'] ?? []);

        if (($permissionScope['scope_type'] ?? '') === 'stores') {
            $allowedStoreIds = $permissionScope['store_ids'] ?? [];
            if ($allowedStoreIds === []) {
                return $this->hasFactLevelFilters($filters) ? null : self::aggregate([]);
            }
            $this->appendInCondition($where, $params, 'report.store_id', $allowedStoreIds);
        } elseif (($permissionScope['scope_type'] ?? '') === 'staff') {
            $where[] = 'report.staff_id = ?';
            $params[] = (int) ($permissionScope['staff_id'] ?? 0);
        } elseif (($permissionScope['scope_type'] ?? '') !== 'all') {
            throw new InvalidArgumentException('工作量换算查询权限范围无效');
        }

        if ($this->hasFactLevelFilters($filters)) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT report.id FROM workload_daily_reports report WHERE ' . implode(' AND ', $where)
        );
        $stmt->execute($params);
        $reportIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        $resultsByReport = $this->forReports($reportIds);
        foreach ($reportIds as $reportId) {
            $resultsByReport[$reportId] ??= [];
        }
        return self::aggregate($resultsByReport);
    }

    private function hasFactLevelFilters(array $filters): bool {
        return ($filters['metric_codes'] ?? []) !== [] || ($filters['audit_statuses'] ?? []) !== [];
    }

    public static function summary(array $results): array {
        $summary = [
            'raw_value' => 0.0,
            'pending_points' => 0.0,
            'effective_points' => 0.0,
            'rejected_points' => 0.0,
            'required_points' => 4.0,
            'gap_points' => 4.0,
            'completion_state' => 'not_met',
        ];
        foreach ($results as $result) {
            foreach (['raw_value', 'pending_points', 'effective_points', 'rejected_points'] as $field) {
                $summary[$field] += (float) ($result[$field] ?? 0);
            }
        }
        foreach (['raw_value', 'pending_points', 'effective_points', 'rejected_points'] as $field) {
            $summary[$field] = round($summary[$field], 2);
        }
        $summary['gap_points'] = round(max(0, $summary['required_points'] - $summary['effective_points']), 2);
        if ($summary['effective_points'] >= $summary['required_points']) {
            $summary['completion_state'] = 'met';
        } elseif ($summary['pending_points'] > 0) {
            $summary['completion_state'] = 'pending_review';
        }
        return $summary;
    }

    public static function aggregate(array $resultsByReport): array {
        $summary = self::summary([]);
        $summary['report_count'] = 0;
        $summary['completed_report_count'] = 0;
        foreach ($resultsByReport as $results) {
            $reportSummary = self::summary($results);
            $summary['report_count']++;
            foreach (['raw_value', 'pending_points', 'effective_points', 'rejected_points'] as $field) {
                $summary[$field] += $reportSummary[$field];
            }
            if ($reportSummary['completion_state'] === 'met') {
                $summary['completed_report_count']++;
            }
        }
        foreach (['raw_value', 'pending_points', 'effective_points', 'rejected_points'] as $field) {
            $summary[$field] = round($summary[$field], 2);
        }
        $summary['required_points'] = round($summary['report_count'] * 4, 2);
        $summary['gap_points'] = round(max(0, $summary['required_points'] - $summary['effective_points']), 2);
        $summary['completion_state'] = $summary['report_count'] > 0 && $summary['completed_report_count'] === $summary['report_count']
            ? 'met'
            : ($summary['pending_points'] > 0 ? 'pending_review' : 'not_met');
        $summary['completion_rate'] = $summary['report_count'] > 0
            ? round($summary['completed_report_count'] / $summary['report_count'], 4)
            : 0.0;
        return $summary;
    }

    private function appendInCondition(array &$where, array &$params, string $column, array $values): void {
        $values = array_values(array_unique(array_filter($values, static fn($value): bool => (string) $value !== '')));
        if ($values === []) {
            return;
        }
        $where[] = $column . ' IN (' . implode(',', array_fill(0, count($values), '?')) . ')';
        array_push($params, ...$values);
    }
}
