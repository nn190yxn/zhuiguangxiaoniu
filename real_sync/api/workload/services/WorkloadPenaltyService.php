<?php
declare(strict_types=1);

final class WorkloadPenaltyService {
    private const UNIT_AMOUNT = 20.00;
    private const PENALTY_POLICY_EFFECTIVE_DATE = '2026-08-18';

    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function refreshForSettlement(array $settlement): ?array {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('处罚记录必须在同一事务内刷新');
        }
        $this->assertSettlement($settlement);
        $existing = $this->lockByScope($settlement);
        $businessDate = (string) $settlement['business_date'];
        $isOverdueGap = (string) $settlement['settlement_status'] === 'overdue'
            && (float) $settlement['gap_points'] > 0;
        $isPenaltyEligible = $businessDate < self::PENALTY_POLICY_EFFECTIVE_DATE
            || $this->hasPreviousOverdueGap($settlement);
        $isOverdueGap = $isOverdueGap && $isPenaltyEligible;
        if (!$isOverdueGap) {
            if (!$existing || (string) $existing['status'] === 'payroll_handed_off') {
                return $existing;
            }
            if ((string) $existing['status'] === 'cancelled') {
                return $existing;
            }
            $before = $existing;
            $cancel = $this->pdo->prepare(
                "UPDATE workload_penalty_records SET status = 'cancelled', adjustment_reason = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?"
            );
            $cancel->execute(['审核或管理更正后已无最终差额', (int) $existing['id']]);
            $record = $this->lockById((int) $existing['id']);
            $this->log($record, 'cancelled', $before, '审核或管理更正后已无最终差额');
            return $record;
        }

        $gapPoints = round((float) $settlement['gap_points'], 2);
        $penaltyAmount = round($gapPoints * self::UNIT_AMOUNT, 2);
        if (!$existing) {
            $insert = $this->pdo->prepare(
                'INSERT INTO workload_penalty_records '
                . '(settlement_id, business_date, store_id, staff_id, role_code, gap_points, unit_amount, penalty_amount, status) '
                . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending_confirmation')"
            );
            $insert->execute([
                (int) $settlement['settlement_id'],
                (string) $settlement['business_date'],
                (int) $settlement['store_id'],
                (int) $settlement['staff_id'],
                (string) $settlement['role_code'],
                $gapPoints,
                self::UNIT_AMOUNT,
                $penaltyAmount,
            ]);
            $record = $this->lockById((int) $this->pdo->lastInsertId());
            $this->log($record, 'created', null, '逾期差额自动生成处罚记录');
            return $record;
        }

        if ((string) $existing['status'] === 'payroll_handed_off') {
            return $existing;
        }
        if (
            (float) $existing['gap_points'] === $gapPoints
            && (float) $existing['unit_amount'] === self::UNIT_AMOUNT
            && (float) $existing['penalty_amount'] === $penaltyAmount
        ) {
            return $existing;
        }

        $before = $existing;
        $status = (string) $existing['status'] === 'cancelled' ? 'pending_confirmation' : (string) $existing['status'];
        $update = $this->pdo->prepare(
            'UPDATE workload_penalty_records SET settlement_id = ?, gap_points = ?, unit_amount = ?, penalty_amount = ?, status = ?, '
            . 'adjustment_reason = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $update->execute([
            (int) $settlement['settlement_id'],
            $gapPoints,
            self::UNIT_AMOUNT,
            $penaltyAmount,
            $status,
            '审核或管理更正导致最终差额变化',
            (int) $existing['id'],
        ]);
        $record = $this->lockById((int) $existing['id']);
        $this->log($record, 'adjusted', $before, '审核或管理更正导致最终差额变化');
        return $record;
    }

    public function applyAction(int $penaltyId, string $action, string $reason, int $operatorStaffId): array {
        if ($penaltyId <= 0 || $operatorStaffId <= 0) {
            throw new InvalidArgumentException('处罚操作参数无效');
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason, 'UTF-8') > 500) {
            throw new InvalidArgumentException('处理原因需填写且不超过 500 字');
        }
        if (!in_array($action, ['confirm', 'cancel', 'payroll_handoff'], true)) {
            throw new InvalidArgumentException('处罚操作无效');
        }

        $this->pdo->beginTransaction();
        try {
            $before = $this->lockById($penaltyId);
            $status = (string) $before['status'];
            $updates = match ($action) {
                'confirm' => $status === 'pending_confirmation'
                    ? ["status = 'confirmed', confirmed_by_staff_id = ?, confirmed_at = UTC_TIMESTAMP(), confirmation_comment = ?", 'confirmed']
                    : throw new RuntimeException('当前处罚状态不能确认'),
                'cancel' => in_array($status, ['pending_confirmation', 'confirmed'], true)
                    ? ["status = 'cancelled', cancelled_by_staff_id = ?, cancelled_at = UTC_TIMESTAMP(), cancellation_reason = ?", 'cancelled']
                    : throw new RuntimeException('当前处罚状态不能撤销'),
                'payroll_handoff' => $status === 'confirmed'
                    ? ["status = 'payroll_handed_off', payroll_handed_off_by_staff_id = ?, payroll_handed_off_at = UTC_TIMESTAMP(), payroll_handoff_note = ?", 'payroll_handed_off']
                    : throw new RuntimeException('处罚确认后才能交薪资'),
            };
            $update = $this->pdo->prepare("UPDATE workload_penalty_records SET {$updates[0]}, updated_at = UTC_TIMESTAMP() WHERE id = ?");
            $update->execute([$operatorStaffId, $reason, $penaltyId]);
            $record = $this->lockById($penaltyId);
            $this->log($record, $updates[1], $before, $reason, $operatorStaffId);
            $this->pdo->commit();
            return $record;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    private function lockByScope(array $settlement): ?array {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM workload_penalty_records WHERE business_date = ? AND store_id = ? '
            . 'AND staff_id = ? AND role_code = ? FOR UPDATE'
        );
        $stmt->execute([
            (string) $settlement['business_date'],
            (int) $settlement['store_id'],
            (int) $settlement['staff_id'],
            (string) $settlement['role_code'],
        ]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function hasPreviousOverdueGap(array $settlement): bool {
        $previousDate = (new DateTimeImmutable((string) $settlement['business_date']))
            ->modify('-1 day')->format('Y-m-d');
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM workload_daily_settlements WHERE business_date = ? AND store_id = ? "
            . "AND staff_id = ? AND role_code = ? AND settlement_status = 'overdue' AND gap_points > 0 LIMIT 1"
        );
        $stmt->execute([
            $previousDate,
            (int) $settlement['store_id'],
            (int) $settlement['staff_id'],
            (string) $settlement['role_code'],
        ]);
        return $stmt->fetchColumn() !== false;
    }

    private function lockById(int $id): array {
        $stmt = $this->pdo->prepare('SELECT * FROM workload_penalty_records WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$record) {
            throw new RuntimeException('处罚记录不存在');
        }
        return $record;
    }

    private function log(array $after, string $actionCode, ?array $before, string $reason, ?int $operatorStaffId = null): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO workload_penalty_record_logs '
            . '(penalty_record_id, action_code, before_snapshot_json, after_snapshot_json, reason, operated_by_staff_id) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (int) $after['id'],
            $actionCode,
            $before === null ? null : $this->snapshot($before),
            $this->snapshot($after),
            $reason,
            $operatorStaffId,
        ]);
    }

    private function snapshot(array $record): string {
        return json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function assertSettlement(array $settlement): void {
        foreach (['settlement_id', 'business_date', 'store_id', 'staff_id', 'role_code', 'gap_points', 'settlement_status'] as $field) {
            if (!array_key_exists($field, $settlement)) {
                throw new InvalidArgumentException('处罚结算数据不完整');
            }
        }
    }
}
