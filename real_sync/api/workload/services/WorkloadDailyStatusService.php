<?php
declare(strict_types=1);

require_once __DIR__ . '/WorkloadMakeupService.php';

final class WorkloadDailyStatusService {
    private const BUSINESS_TIMEZONE = 'Asia/Shanghai';
    private const TARGET_POINTS = 4.0;

    private PDO $pdo;
    private WorkloadMakeupService $makeupService;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->makeupService = new WorkloadMakeupService($pdo);
    }

    public function forEmployee(int $staffId, int $storeId, string $roleCode): array {
        if ($staffId <= 0 || $storeId <= 0 || trim($roleCode) === '') {
            throw new InvalidArgumentException('员工每日工作量范围无效');
        }
        $now = $this->databaseNow();
        $today = $now->format('Y-m-d');
        $yesterday = $this->makeupService->previousWorkday($now)->format('Y-m-d');
        $todaySettlement = $this->settlement($today, $storeId, $staffId, $roleCode);
        $yesterdaySettlement = $this->settlement($yesterday, $storeId, $staffId, $roleCode);

        return [
            'business_now_at' => $now->format('Y-m-d H:i:s'),
            'makeup_business_date' => $yesterday,
            'today' => $this->dailyState($todaySettlement, $today, $now),
            'yesterday_makeup' => $this->dailyState($yesterdaySettlement, $yesterday, $now),
            'monthly_penalty_summary' => $this->monthlyPenaltySummary($staffId, $now),
            'conversion_table' => $this->conversionTable($roleCode, $today),
        ];
    }

    private function settlement(string $businessDate, int $storeId, int $staffId, string $roleCode): ?array {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM workload_daily_settlements WHERE business_date = ? AND store_id = ? '
            . 'AND staff_id = ? AND role_code = ? LIMIT 1'
        );
        $stmt->execute([$businessDate, $storeId, $staffId, $roleCode]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function dailyState(?array $settlement, string $businessDate, DateTimeImmutable $now): array {
        $date = new DateTimeImmutable($businessDate, new DateTimeZone(self::BUSINESS_TIMEZONE));
        $isMakeupTarget = $this->makeupService->isMakeupDateAt($date, $now);
        $deadline = $isMakeupTarget
            ? $this->makeupDeadline($date)->format('Y-m-d H:i:s')
            : $date->modify('+1 day')->format('Y-m-d H:i:s');
        $effectivePoints = round((float) ($settlement['effective_points'] ?? 0), 2);
        $targetPoints = round((float) ($settlement['target_points'] ?? self::TARGET_POINTS), 2);
        $gapPoints = round(max(0, $targetPoints - $effectivePoints), 2);
        $status = $gapPoints <= 0
            ? 'completed'
            : $this->defaultStatus($businessDate, $now, $gapPoints);
        $penalty = $settlement ? $this->penalty((int) $settlement['id']) : null;
        return [
            'business_date' => $businessDate,
            'target_points' => $targetPoints,
            'reported_points' => round((float) ($settlement['reported_points'] ?? 0), 2),
            'pending_points' => round((float) ($settlement['pending_points'] ?? 0), 2),
            'effective_points' => $effectivePoints,
            'rejected_points' => round((float) ($settlement['rejected_points'] ?? 0), 2),
            'gap_points' => $gapPoints,
            'status' => $status,
            'status_label' => self::statusLabel($status),
            'makeup_deadline_at' => $settlement['makeup_deadline_at'] ?? $deadline,
            'is_makeup_open' => $status === 'makeup_open',
            'is_makeup_target' => $isMakeupTarget,
            'penalty' => $penalty,
        ];
    }

    private function penalty(int $settlementId): ?array {
        $stmt = $this->pdo->prepare('SELECT id, gap_points, penalty_amount, status FROM workload_penalty_records WHERE settlement_id = ? LIMIT 1');
        $stmt->execute([$settlementId]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$record) return null;
        return [
            'id' => (int) $record['id'],
            'gap_points' => round((float) $record['gap_points'], 2),
            'amount' => round((float) $record['penalty_amount'], 2),
            'status' => (string) $record['status'],
            'status_label' => self::penaltyStatusLabel((string) $record['status']),
        ];
    }

    private function monthlyPenaltySummary(int $staffId, DateTimeImmutable $now): array {
        $monthStart = $now->modify('first day of this month')->format('Y-m-d');
        $monthEnd = $now->format('Y-m-d');
        $stmt = $this->pdo->prepare(
            'SELECT status, COUNT(*) AS record_count, COALESCE(SUM(penalty_amount), 0) AS amount '
            . 'FROM workload_penalty_records WHERE staff_id = ? AND business_date BETWEEN ? AND ? '
            . "AND status <> 'cancelled' GROUP BY status"
        );
        $stmt->execute([$staffId, $monthStart, $monthEnd]);
        $summary = ['month' => $now->format('Y-m'), 'record_count' => 0, 'amount' => 0.0, 'pending_amount' => 0.0, 'items' => []];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $count = (int) $row['record_count'];
            $amount = round((float) $row['amount'], 2);
            $status = (string) $row['status'];
            $summary['record_count'] += $count;
            $summary['amount'] += $amount;
            if ($status === 'pending_confirmation') $summary['pending_amount'] += $amount;
            $summary['items'][] = ['status' => $status, 'status_label' => self::penaltyStatusLabel($status), 'record_count' => $count, 'amount' => $amount];
        }
        $summary['amount'] = round($summary['amount'], 2);
        $summary['pending_amount'] = round($summary['pending_amount'], 2);
        return $summary;
    }

    private function conversionTable(string $roleCode, string $businessDate): array {
        $stmt = $this->pdo->prepare(
            'SELECT rule.rule_code, rule.metric_codes_json, rule.conversion_mode, rule.threshold_value, '
            . 'rule.points_per_match, rule.daily_cap_points, rule.tiers_json '
            . 'FROM workload_conversion_rule_versions version INNER JOIN workload_conversion_rules rule ON rule.rule_version_id = version.id '
            . "WHERE version.role_code = ? AND version.status = 'active' AND version.effective_from <= ? "
            . 'AND (version.effective_to IS NULL OR version.effective_to >= ?) ORDER BY rule.id'
        );
        $stmt->execute([$roleCode, $businessDate, $businessDate]);
        $metricNames = $this->metricNames($roleCode);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $rule) {
            $codes = json_decode((string) $rule['metric_codes_json'], true) ?: [];
            $labels = array_map(static fn(string $code): string => $metricNames[$code] ?? $code, $codes);
            $rows[] = [
                'metrics' => $labels,
                'threshold_value' => round((float) $rule['threshold_value'], 2),
                'points_per_unit' => round((float) ($rule['points_per_match'] ?? 0), 2),
                'description' => $this->conversionDescription($rule, $labels),
            ];
        }
        return $rows;
    }

    private function conversionDescription(array $rule, array $labels): string {
        $label = implode('、', $labels);
        if (($rule['rule_code'] ?? '') === 'sales-deal-amount-tier') {
            return $label . '大于 0 元计 1 点，满 4000 元计 2 点';
        }
        if (($rule['conversion_mode'] ?? '') === 'step') {
            $cap = $rule['daily_cap_points'] !== null ? '，每日最多 ' . round((float) $rule['daily_cap_points'], 2) . ' 点' : '';
            return sprintf('%s每 %.2f 计 %.2f 点%s', $label, (float) $rule['threshold_value'], (float) $rule['points_per_match'], $cap);
        }
        return sprintf('%s达到 %.2f 计 %.2f 点', $label, (float) $rule['threshold_value'], (float) $rule['points_per_match']);
    }

    private function metricNames(string $roleCode): array {
        $stmt = $this->pdo->prepare('SELECT metric_code, metric_name FROM metric_definitions WHERE role_code = ?');
        $stmt->execute([$roleCode]);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    }

    private function defaultStatus(string $businessDate, DateTimeImmutable $now, float $gapPoints): string {
        if ($gapPoints <= 0) return 'completed';
        $date = new DateTimeImmutable($businessDate, new DateTimeZone(self::BUSINESS_TIMEZONE));
        if ($now->format('Y-m-d') === $businessDate) return 'today_open';
        return $this->makeupService->isMakeupDateAt($date, $now)
            ? 'makeup_open'
            : 'overdue';
    }

    private function makeupDeadline(DateTimeImmutable $businessDate): DateTimeImmutable {
        $date = $businessDate;
        do {
            $date = $date->modify('+1 day');
        } while ($date->format('N') === '1');
        return $date->modify('+1 day');
    }

    private function databaseNow(): DateTimeImmutable {
        $value = $this->pdo->query('SELECT UTC_TIMESTAMP()')->fetchColumn();
        return (new DateTimeImmutable((string) $value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(self::BUSINESS_TIMEZONE));
    }

    public static function statusLabel(string $status): string {
        return ['today_open' => '今日可完成', 'makeup_open' => '上一工作日可补齐', 'completed' => '已达标', 'overdue' => '已逾期'][$status] ?? '状态待确认';
    }

    public static function penaltyStatusLabel(string $status): string {
        return ['pending_confirmation' => '待确认', 'confirmed' => '已确认', 'cancelled' => '已撤销', 'payroll_handed_off' => '已交薪资'][$status] ?? '处理状态待确认';
    }
}
