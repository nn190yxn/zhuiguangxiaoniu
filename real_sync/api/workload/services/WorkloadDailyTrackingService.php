<?php
declare(strict_types=1);

require_once __DIR__ . '/WorkloadPermissionScopeService.php';

final class WorkloadDailyTrackingService {
    private PDO $pdo;
    private WorkloadPermissionScopeService $permissionScope;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->permissionScope = new WorkloadPermissionScopeService($pdo);
    }

    public function dailyTracking(array $context, array $input): array {
        $filters = $this->filters($input, 31);
        $scope = $this->permissionScope->resolve($context);
        [$where, $params] = $this->where($scope, $filters, 'settlement');
        $sql = 'SELECT settlement.id AS settlement_id, settlement.business_date, settlement.store_id, store.name AS store_name, '
            . 'settlement.staff_id, staff.name AS staff_name, settlement.role_code, settlement.target_points, '
            . 'settlement.reported_points, settlement.pending_points, settlement.effective_points, settlement.rejected_points, '
            . 'settlement.gap_points, settlement.settlement_status, settlement.makeup_deadline_at, '
            . 'penalty.id AS penalty_id, penalty.penalty_amount, penalty.status AS penalty_status '
            . 'FROM workload_daily_settlements settlement '
            . 'JOIN staffs staff ON staff.id = settlement.staff_id '
            . 'LEFT JOIN stores store ON store.id = settlement.store_id '
            . 'LEFT JOIN workload_penalty_records penalty ON penalty.settlement_id = settlement.id '
            . "WHERE {$where} ORDER BY settlement.business_date DESC, store.name, staff.name, settlement.role_code";
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $rows = array_map(fn(array $row): array => $this->trackingRow($row), $statement->fetchAll(PDO::FETCH_ASSOC) ?: []);
        return ['filters' => $filters, 'scope' => $this->publicScope($scope), 'items' => $rows, 'summary' => $this->trackingSummary($rows)];
    }

    public function penaltyList(array $context, array $input): array {
        $filters = $this->filters($input, 366);
        $scope = $this->permissionScope->resolve($context);
        [$where, $params] = $this->where($scope, $filters, 'penalty');
        $status = trim((string) ($input['status'] ?? ''));
        if ($status !== '') {
            if (!in_array($status, ['pending_confirmation', 'confirmed', 'cancelled', 'payroll_handed_off'], true)) {
                throw new InvalidArgumentException('处罚状态无效');
            }
            $where .= ' AND penalty.status = ?';
            $params[] = $status;
        }
        $sql = 'SELECT penalty.id AS penalty_id, penalty.business_date, penalty.store_id, store.name AS store_name, '
            . 'penalty.staff_id, staff.name AS staff_name, penalty.role_code, penalty.gap_points, penalty.unit_amount, '
            . 'penalty.penalty_amount, penalty.status AS penalty_status, penalty.adjustment_reason, penalty.confirmation_comment, '
            . 'penalty.cancellation_reason, penalty.payroll_handoff_note, penalty.created_at, penalty.updated_at '
            . 'FROM workload_penalty_records penalty '
            . 'JOIN staffs staff ON staff.id = penalty.staff_id '
            . 'LEFT JOIN stores store ON store.id = penalty.store_id '
            . "WHERE {$where} ORDER BY penalty.business_date DESC, penalty.id DESC";
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $rows = array_map(fn(array $row): array => $this->penaltyRow($row), $statement->fetchAll(PDO::FETCH_ASSOC) ?: []);
        return ['filters' => $filters + ['status' => $status], 'scope' => $this->publicScope($scope), 'items' => $rows, 'summary' => $this->penaltySummary($rows)];
    }

    private function filters(array $input, int $maxDays): array {
        $dateTo = trim((string) ($input['date_to'] ?? $input['date'] ?? date('Y-m-d')));
        $dateFrom = trim((string) ($input['date_from'] ?? $dateTo));
        foreach ([$dateFrom, $dateTo] as $date) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new InvalidArgumentException('日期格式应为 YYYY-MM-DD');
        }
        $days = (new DateTimeImmutable($dateFrom))->diff(new DateTimeImmutable($dateTo))->days + 1;
        if ($dateFrom > $dateTo || $days > $maxDays) throw new InvalidArgumentException("日期范围不能超过 {$maxDays} 天");
        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'store_id' => max(0, (int) ($input['store_id'] ?? 0)),
            'staff_id' => max(0, (int) ($input['staff_id'] ?? 0)),
            'role_code' => trim((string) ($input['role_code'] ?? $input['role'] ?? '')),
            'settlement_status' => trim((string) ($input['settlement_status'] ?? '')),
        ];
    }

    private function where(array $scope, array $filters, string $alias): array {
        $where = ["{$alias}.business_date BETWEEN ? AND ?"];
        $params = [$filters['date_from'], $filters['date_to']];
        if ($scope['scope_type'] === 'staff') {
            $where[] = "{$alias}.staff_id = ?";
            $params[] = (int) $scope['staff_id'];
        } elseif ($scope['scope_type'] === 'stores') {
            $storeIds = array_values(array_map('intval', $scope['store_ids']));
            $where[] = "{$alias}.store_id IN (" . implode(',', array_fill(0, count($storeIds), '?')) . ')';
            array_push($params, ...$storeIds);
        }
        foreach (['store_id', 'staff_id'] as $field) {
            if ($filters[$field] > 0) {
                $where[] = "{$alias}.{$field} = ?";
                $params[] = $filters[$field];
            }
        }
        if ($filters['role_code'] !== '') {
            $where[] = "{$alias}.role_code = ?";
            $params[] = $filters['role_code'];
        }
        if ($alias === 'settlement' && $filters['settlement_status'] !== '') {
            if (!in_array($filters['settlement_status'], ['today_open', 'makeup_open', 'completed', 'overdue'], true)) {
                throw new InvalidArgumentException('每日结算状态无效');
            }
            $where[] = 'settlement.settlement_status = ?';
            $params[] = $filters['settlement_status'];
        }
        return [implode(' AND ', $where), $params];
    }

    private function trackingRow(array $row): array {
        $status = (string) $row['settlement_status'];
        $penaltyStatus = (string) ($row['penalty_status'] ?? '');
        $row['settlement_status_label'] = $this->settlementLabel($status);
        $row['penalty_status_label'] = $penaltyStatus === '' ? '' : $this->penaltyLabel($penaltyStatus);
        $row['next_action'] = $this->nextAction($status, $penaltyStatus);
        return $row;
    }

    private function penaltyRow(array $row): array {
        $row['penalty_status_label'] = $this->penaltyLabel((string) $row['penalty_status']);
        $row['next_action'] = match ((string) $row['penalty_status']) {
            'pending_confirmation' => '确认处罚处理结果',
            'confirmed' => '等待薪资交接',
            'payroll_handed_off' => '已完成薪资交接',
            default => '处罚已撤销',
        };
        return $row;
    }

    private function trackingSummary(array $rows): array {
        $summary = ['today_open_count' => 0, 'makeup_open_count' => 0, 'overdue_count' => 0, 'pending_review_count' => 0, 'pending_penalty_count' => 0];
        foreach ($rows as $row) {
            $status = (string) $row['settlement_status'];
            if (array_key_exists($status . '_count', $summary)) $summary[$status . '_count']++;
            if ((float) $row['pending_points'] > 0) $summary['pending_review_count']++;
            if ((string) ($row['penalty_status'] ?? '') === 'pending_confirmation') $summary['pending_penalty_count']++;
        }
        return $summary;
    }

    private function penaltySummary(array $rows): array {
        $summary = ['record_count' => count($rows), 'amount' => 0.0, 'pending_amount' => 0.0];
        foreach ($rows as $row) {
            $amount = round((float) $row['penalty_amount'], 2);
            $summary['amount'] += $amount;
            if ((string) $row['penalty_status'] === 'pending_confirmation') $summary['pending_amount'] += $amount;
        }
        $summary['amount'] = round($summary['amount'], 2);
        $summary['pending_amount'] = round($summary['pending_amount'], 2);
        return $summary;
    }

    private function publicScope(array $scope): array {
        return ['scope_type' => $scope['scope_type'], 'store_ids' => $scope['store_ids'], 'staff_id' => $scope['staff_id']];
    }

    private function settlementLabel(string $status): string {
        return ['today_open' => '今日待完成', 'makeup_open' => '昨日待补', 'completed' => '已达标', 'overdue' => '已逾期'][$status] ?? '状态待确认';
    }

    private function penaltyLabel(string $status): string {
        return ['pending_confirmation' => '待确认处罚', 'confirmed' => '已确认处罚', 'cancelled' => '已撤销处罚', 'payroll_handed_off' => '已交薪资'][$status] ?? '处理状态待确认';
    }

    private function nextAction(string $status, string $penaltyStatus): string {
        if ($penaltyStatus === 'pending_confirmation') return '确认处罚处理结果';
        return match ($status) {
            'today_open' => '提醒员工今日完成日报',
            'makeup_open' => '提醒员工在截止前补齐',
            'overdue' => '跟进逾期处理结果',
            default => '等待审核结果',
        };
    }
}
