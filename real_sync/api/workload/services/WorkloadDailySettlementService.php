<?php
declare(strict_types=1);

require_once __DIR__ . '/WorkloadPenaltyService.php';

final class WorkloadDailySettlementService {
    private const BUSINESS_TIMEZONE = 'Asia/Shanghai';
    private const TARGET_POINTS = 4.0;
    private const POINT_SETTLEMENT_ROLES = ['sales', 'coach'];

    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function refreshReport(int $reportId, ?DateTimeImmutable $now = null): array {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('日报结算必须在同一事务内刷新');
        }
        $stmt = $this->pdo->prepare(
            'SELECT report_date, store_id, staff_id, role_code FROM workload_daily_reports WHERE id = ? FOR UPDATE'
        );
        $stmt->execute([$reportId]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$report) {
            throw new RuntimeException('日报不存在，无法刷新每日结算');
        }
        return $this->refreshScope(
            (string) $report['report_date'],
            (int) $report['store_id'],
            (int) $report['staff_id'],
            (string) $report['role_code'],
            $now
        );
    }

    public function refreshScope(
        string $businessDate,
        int $storeId,
        int $staffId,
        string $roleCode,
        ?DateTimeImmutable $now = null
    ): array {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('每日结算必须在同一事务内刷新');
        }
        if ($storeId <= 0 || $staffId <= 0 || trim($roleCode) === '') {
            throw new InvalidArgumentException('每日结算范围无效');
        }

        $businessDate = $this->businessDate($businessDate);
        if (!in_array($roleCode, self::POINT_SETTLEMENT_ROLES, true)) {
            return [
                'settlement_id' => null,
                'business_date' => $businessDate,
                'store_id' => $storeId,
                'staff_id' => $staffId,
                'role_code' => $roleCode,
                'target_points' => 0.0,
                'reported_points' => 0.0,
                'pending_points' => 0.0,
                'effective_points' => 0.0,
                'rejected_points' => 0.0,
                'gap_points' => 0.0,
                'settlement_status' => 'not_applicable',
                'makeup_deadline_at' => null,
                'penalty' => ['applicable' => false, 'reason' => 'management_statistics_only'],
            ];
        }
        $now = ($now ?: new DateTimeImmutable('now', new DateTimeZone(self::BUSINESS_TIMEZONE)))
            ->setTimezone(new DateTimeZone(self::BUSINESS_TIMEZONE));
        $existing = $this->lockSettlement($businessDate, $storeId, $staffId, $roleCode);
        $totals = $this->conversionTotals($businessDate, $storeId, $staffId, $roleCode);
        $targetPoints = $existing ? (float) $existing['target_points'] : self::TARGET_POINTS;
        $gapPoints = round(max(0, $targetPoints - $totals['effective_points']), 2);
        $makeupDeadline = (new DateTimeImmutable($businessDate, new DateTimeZone(self::BUSINESS_TIMEZONE)))
            ->modify('+2 days')
            ->format('Y-m-d 00:00:00');
        $status = $this->statusFor($businessDate, $now, $gapPoints);
        $snapshot = $existing ? (string) $existing['rule_snapshot_json'] : $this->ruleSnapshot($targetPoints, $totals);
        $settledAt = in_array($status, ['completed', 'overdue'], true)
            ? $now->format('Y-m-d H:i:s')
            : null;

        $upsert = $this->pdo->prepare(
            'INSERT INTO workload_daily_settlements '
            . '(business_date, store_id, staff_id, role_code, target_points, reported_points, pending_points, '
            . 'effective_points, rejected_points, gap_points, settlement_status, makeup_deadline_at, settled_at, rule_snapshot_json) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), reported_points = VALUES(reported_points), '
            . 'pending_points = VALUES(pending_points), effective_points = VALUES(effective_points), '
            . 'rejected_points = VALUES(rejected_points), gap_points = VALUES(gap_points), '
            . 'settlement_status = VALUES(settlement_status), makeup_deadline_at = VALUES(makeup_deadline_at), '
            . 'settled_at = VALUES(settled_at), rule_snapshot_json = VALUES(rule_snapshot_json), updated_at = CURRENT_TIMESTAMP'
        );
        $upsert->execute([
            $businessDate, $storeId, $staffId, $roleCode, $targetPoints,
            $totals['reported_points'], $totals['pending_points'], $totals['effective_points'],
            $totals['rejected_points'], $gapPoints, $status, $makeupDeadline, $settledAt, $snapshot,
        ]);
        $settlementId = $existing ? (int) $existing['id'] : (int) $this->pdo->lastInsertId();

        $settlement = [
            'settlement_id' => $settlementId,
            'business_date' => $businessDate,
            'store_id' => $storeId,
            'staff_id' => $staffId,
            'role_code' => $roleCode,
            'target_points' => $targetPoints,
            'reported_points' => $totals['reported_points'],
            'pending_points' => $totals['pending_points'],
            'effective_points' => $totals['effective_points'],
            'rejected_points' => $totals['rejected_points'],
            'gap_points' => $gapPoints,
            'settlement_status' => $status,
            'makeup_deadline_at' => $makeupDeadline,
        ];
        $penalty = (new WorkloadPenaltyService($this->pdo))->refreshForSettlement($settlement);
        $settlement['penalty'] = $penalty;
        return $settlement;
    }

    public function refreshDate(string $businessDate, ?DateTimeImmutable $now = null): array {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('每日结算必须在同一事务内刷新');
        }
        $businessDate = $this->businessDate($businessDate);
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT report_date, store_id, staff_id, role_code FROM workload_daily_reports "
            . "WHERE report_date = ? AND submit_status = 'submitted' ORDER BY store_id, staff_id, role_code FOR UPDATE"
        );
        $stmt->execute([$businessDate]);
        $settlements = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $scope) {
            $settlements[] = $this->refreshScope(
                (string) $scope['report_date'],
                (int) $scope['store_id'],
                (int) $scope['staff_id'],
                (string) $scope['role_code'],
                $now
            );
        }
        return $settlements;
    }

    private function lockSettlement(string $businessDate, int $storeId, int $staffId, string $roleCode): ?array {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM workload_daily_settlements WHERE business_date = ? AND store_id = ? '
            . 'AND staff_id = ? AND role_code = ? FOR UPDATE'
        );
        $stmt->execute([$businessDate, $storeId, $staffId, $roleCode]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function conversionTotals(string $businessDate, int $storeId, int $staffId, string $roleCode): array {
        $stmt = $this->pdo->prepare(
            'SELECT result.rule_snapshot_json, result.reported_points, result.pending_points, result.effective_points, result.rejected_points '
            . 'FROM workload_report_conversion_results result '
            . 'INNER JOIN workload_daily_reports report ON report.id = result.report_id '
            . "WHERE report.report_date = ? AND report.store_id = ? AND report.staff_id = ? AND report.role_code = ? "
            . "AND report.submit_status = 'submitted' ORDER BY result.id FOR UPDATE"
        );
        $stmt->execute([$businessDate, $storeId, $staffId, $roleCode]);
        $totals = [
            'reported_points' => 0.0,
            'pending_points' => 0.0,
            'effective_points' => 0.0,
            'rejected_points' => 0.0,
            'rule_snapshots' => [],
        ];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $result) {
            $totals['reported_points'] += (float) $result['reported_points'];
            $totals['pending_points'] += (float) $result['pending_points'];
            $totals['effective_points'] += (float) $result['effective_points'];
            $totals['rejected_points'] += (float) $result['rejected_points'];
            $totals['rule_snapshots'][] = json_decode((string) $result['rule_snapshot_json'], true) ?: [];
        }
        foreach (['reported_points', 'pending_points', 'effective_points', 'rejected_points'] as $field) {
            $totals[$field] = round($totals[$field], 2);
        }
        return $totals;
    }

    private function ruleSnapshot(float $targetPoints, array $totals): string {
        return json_encode([
            'target_points' => $targetPoints,
            'conversion_rules' => $totals['rule_snapshots'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function statusFor(string $businessDate, DateTimeImmutable $now, float $gapPoints): string {
        if ($gapPoints <= 0) return 'completed';
        $businessStart = new DateTimeImmutable($businessDate, new DateTimeZone(self::BUSINESS_TIMEZONE));
        if ($now < $businessStart->modify('+1 day')) return 'today_open';
        if ($now < $businessStart->modify('+2 days')) return 'makeup_open';
        return 'overdue';
    }

    private function businessDate(string $value): string {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone(self::BUSINESS_TIMEZONE));
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('营业日期无效');
        }
        return $value;
    }
}
