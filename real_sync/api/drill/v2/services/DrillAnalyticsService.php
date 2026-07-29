<?php

declare(strict_types=1);

final class DrillAnalyticsService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function summary(array $filters, array $context, array $staff): array
    {
        [$where, $params] = $this->filters($filters, $context, $staff);
        $join = ' FROM drill_attempts attempt LEFT JOIN drill_assignments assignment ON assignment.id = attempt.assignment_id';
        $sql = 'SELECT attempt.staff_id, assignment.status AS assignment_status, attempt.current_stage_id, attempt.plan_id, '
            . 'MAX(evaluation.total_score) AS score, COUNT(DISTINCT attempt.id) AS attempts' . $join
            . ' LEFT JOIN drill_evaluations evaluation ON evaluation.attempt_id = attempt.id AND evaluation.status = \'completed\''
            . ' WHERE ' . implode(' AND ', $where)
            . ' GROUP BY attempt.staff_id, assignment.status, attempt.current_stage_id, attempt.plan_id';
        $rows = $this->rows($sql, $params);
        $staffIds = array_unique(array_map(static fn(array $row): int => (int) $row['staff_id'], $rows));
        $attempts = array_sum(array_map(static fn(array $row): int => (int) $row['attempts'], $rows));
        $completed = $this->count($join . ' WHERE ' . implode(' AND ', [...$where, "attempt.status IN ('completed', 'evaluated')"]), $params);
        $participated = $this->count(' FROM drill_assignments assignment WHERE ' . $this->assignmentWhere($filters, $context, $staff), $this->assignmentParams($filters, $context, $staff));
        $passed = $this->count(' FROM drill_assignments assignment WHERE ' . $this->assignmentWhere($filters, $context, $staff) . " AND assignment.status = 'passed'", $this->assignmentParams($filters, $context, $staff));
        $pendingReview = $this->count(' FROM drill_review_tasks review INNER JOIN drill_assignments assignment ON assignment.id = review.assignment_id WHERE ' . $this->assignmentWhere($filters, $context, $staff) . " AND review.status = 'pending'", $this->assignmentParams($filters, $context, $staff));
        $needsCoaching = $this->count(' FROM drill_assignments assignment WHERE ' . $this->assignmentWhere($filters, $context, $staff) . " AND assignment.status = 'coaching_required'", $this->assignmentParams($filters, $context, $staff));
        $dimensionRows = $this->rows('SELECT evidence.dimension_code, COUNT(*) AS evidence_count FROM drill_evaluation_evidence evidence INNER JOIN drill_attempts attempt ON attempt.id = evidence.attempt_id LEFT JOIN drill_assignments assignment ON assignment.id = attempt.assignment_id WHERE ' . implode(' AND ', $where) . ' GROUP BY evidence.dimension_code ORDER BY evidence.dimension_code', $params);
        return [
            'filters' => $filters,
            'participation_count' => $participated,
            'completion_count' => $completed,
            'pass_count' => $passed,
            'pass_rate' => $completed === 0 ? null : round($passed / $completed, 4),
            'average_attempts' => count($staffIds) === 0 ? 0.0 : round($attempts / count($staffIds), 2),
            'pending_review_count' => $pendingReview,
            'needs_coaching_count' => $needsCoaching,
            'dimension_distribution' => $dimensionRows,
            'sample' => ['staff_count' => count($staffIds), 'attempt_count' => $attempts, 'low_sample' => count($staffIds) < 3 || $attempts < 10],
            'drilldown' => $this->drilldown($rows),
        ];
    }

    private function drilldown(array $rows): array
    {
        $result = ['staff' => [], 'stage' => [], 'plan' => [], 'status' => []];
        foreach ($rows as $row) {
            foreach (['staff' => 'staff_id', 'stage' => 'current_stage_id', 'plan' => 'plan_id', 'status' => 'assignment_status'] as $group => $field) {
                $key = (string) ($row[$field] ?? 'unassigned');
                $result[$group][$key] = ($result[$group][$key] ?? 0) + (int) $row['attempts'];
            }
        }
        return $result;
    }

    private function filters(array $filters, array $context, array $staff): array
    {
        $where = ['attempt.status <> \'cancelled\''];
        $params = [];
        foreach (['staff_id' => 'attempt.staff_id', 'domain_id' => 'attempt.domain_id', 'stage_id' => 'attempt.current_stage_id', 'plan_id' => 'attempt.plan_id'] as $key => $column) {
            if (isset($filters[$key]) && (int) $filters[$key] > 0) { $where[] = $column . ' = ?'; $params[] = (int) $filters[$key]; }
        }
        if (!empty($filters['status'])) { $where[] = 'assignment.status = ?'; $params[] = (string) $filters['status']; }
        if (!empty($filters['date_from'])) { $where[] = 'DATE(COALESCE(attempt.completed_at, attempt.created_at)) >= ?'; $params[] = (string) $filters['date_from']; }
        if (!empty($filters['date_to'])) { $where[] = 'DATE(COALESCE(attempt.completed_at, attempt.created_at)) <= ?'; $params[] = (string) $filters['date_to']; }
        if (!empty($filters['store_id'])) { $where[] = 'EXISTS (SELECT 1 FROM staff_assignments scope_store WHERE scope_store.staff_id = attempt.staff_id AND scope_store.store_id = ? AND scope_store.start_date <= CURRENT_DATE AND (scope_store.end_date IS NULL OR scope_store.end_date >= CURRENT_DATE))'; $params[] = (int) $filters['store_id']; }
        if (!empty($filters['position_id'])) { $where[] = 'EXISTS (SELECT 1 FROM staff_assignments scope_position WHERE scope_position.staff_id = attempt.staff_id AND scope_position.position_id = ?)'; $params[] = (int) $filters['position_id']; }
        $role = strtolower((string) ($staff['role'] ?? ''));
        if ($role === 'manager') { $where[] = 'EXISTS (SELECT 1 FROM staff_assignments manager_scope WHERE manager_scope.staff_id = attempt.staff_id AND manager_scope.store_id IN (SELECT store_id FROM staff_assignments WHERE staff_id = ? AND system_role = \'manager\' AND start_date <= CURRENT_DATE AND (end_date IS NULL OR end_date >= CURRENT_DATE)))'; $params[] = (int) $context['staff_id']; }
        return [$where, $params];
    }

    private function assignmentWhere(array $filters, array $context, array $staff): string { return '1 = 1'; }
    private function assignmentParams(array $filters, array $context, array $staff): array { return []; }
    private function rows(string $sql, array $params): array { $stmt = $this->pdo->prepare($sql); $stmt->execute($params); return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []; }
    private function count(string $fromWhere, array $params): int { $stmt = $this->pdo->prepare('SELECT COUNT(DISTINCT assignment.id)' . $fromWhere); $stmt->execute($params); return (int) $stmt->fetchColumn(); }
}
