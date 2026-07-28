<?php

declare(strict_types=1);

require_once __DIR__ . '/DrillPlanPolicy.php';

final class DrillPlanTargetResolver
{
    public function __construct(private PDO $pdo)
    {
    }

    public function resolve(array $scopes, DateTimeImmutable $at): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT staff.id AS staff_id, staff.employee_no, staff.stage AS growth_stage, '
            . 'position.position_code, store.store_code '
            . 'FROM staffs staff '
            . 'LEFT JOIN staff_assignments assignment ON assignment.staff_id = staff.id '
            . 'AND assignment.start_date <= ? AND (assignment.end_date IS NULL OR assignment.end_date >= ?) '
            . 'LEFT JOIN organization_positions position ON position.id = assignment.position_id AND position.status = 1 '
            . 'LEFT JOIN stores store ON store.id = assignment.store_id '
            . "WHERE staff.status = 1 AND staff.lifecycle_status = 'active'"
        );
        $date = $at->format('Y-m-d');
        $stmt->execute([$date, $date]);

        $candidates = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $staffId = (int) $row['staff_id'];
            $candidates[$staffId] ??= [
                'staff_id' => $staffId,
                'employee_no' => (string) $row['employee_no'],
                'growth_stage' => (string) $row['growth_stage'],
                'position_codes' => [],
                'store_codes' => [],
                'active' => true,
            ];
            if (trim((string) ($row['position_code'] ?? '')) !== '') {
                $candidates[$staffId]['position_codes'][] = (string) $row['position_code'];
            }
            if (trim((string) ($row['store_code'] ?? '')) !== '') {
                $candidates[$staffId]['store_codes'][] = (string) $row['store_code'];
            }
        }
        foreach ($candidates as &$candidate) {
            $candidate['position_codes'] = array_values(array_unique($candidate['position_codes']));
            $candidate['store_codes'] = array_values(array_unique($candidate['store_codes']));
        }
        unset($candidate);

        return DrillPlanPolicy::resolveTargets(array_values($candidates), $scopes);
    }
}
