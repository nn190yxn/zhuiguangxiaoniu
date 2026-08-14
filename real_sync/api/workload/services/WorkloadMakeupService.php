<?php
declare(strict_types=1);

final class WorkloadMakeupException extends RuntimeException {
    private int $statusCode;

    public function __construct(string $message, int $statusCode = 400) {
        parent::__construct($message);
        $this->statusCode = $statusCode;
    }

    public function statusCode(): int {
        return $this->statusCode;
    }
}

final class WorkloadMakeupService {
    private const BUSINESS_TIMEZONE = 'Asia/Shanghai';

    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function assertReportWritable(array $report, int $staffId): array {
        if ((int) ($report['staff_id'] ?? 0) !== $staffId) {
            throw new WorkloadMakeupException('无权补齐该日报', 403);
        }
        $businessDate = $this->businessDate((string) ($report['report_date'] ?? ''));
        $now = $this->databaseNow();
        if ($businessDate->format('Y-m-d') !== $now->modify('-1 day')->format('Y-m-d')) {
            throw new WorkloadMakeupException('仅可补齐昨天的日报', 409);
        }
        if (!$this->isMakeupInstant($businessDate, $now)) {
            $deadline = $businessDate->modify('+2 days');
            throw new WorkloadMakeupException(
                '该日报不在补齐期内，补齐截止时间为 ' . $deadline->format('Y-m-d H:i:s'),
                409
            );
        }
        $deadline = $businessDate->modify('+2 days');
        $penalty = $this->pdo->prepare(
            "SELECT id FROM workload_penalty_records WHERE business_date = ? AND store_id = ? AND staff_id = ? "
            . "AND role_code = ? AND status = 'payroll_handed_off' LIMIT 1 FOR UPDATE"
        );
        $penalty->execute([
            $businessDate->format('Y-m-d'),
            (int) ($report['store_id'] ?? 0),
            $staffId,
            (string) ($report['role_code'] ?? ''),
        ]);
        if ($penalty->fetchColumn() !== false) {
            throw new WorkloadMakeupException('该日报处罚已交薪资，无法直接补齐', 409);
        }
        return [
            'is_makeup' => true,
            'business_date' => $businessDate->format('Y-m-d'),
            'makeup_deadline_at' => $deadline->format('Y-m-d H:i:s'),
        ];
    }

    public function isMakeupDate(string $reportDate): bool {
        return $this->isMakeupInstant($this->businessDate($reportDate), $this->databaseNow());
    }

    private function isMakeupInstant(DateTimeImmutable $businessDate, DateTimeImmutable $now): bool {
        $opensAt = $businessDate->modify('+1 day');
        $deadline = $businessDate->modify('+2 days');
        return $now >= $opensAt
            && $now < $deadline
            && $businessDate->format('Y-m-d') === $now->modify('-1 day')->format('Y-m-d');
    }

    private function databaseNow(): DateTimeImmutable {
        $value = $this->pdo->query('SELECT UTC_TIMESTAMP()')->fetchColumn();
        return (new DateTimeImmutable((string) $value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone(self::BUSINESS_TIMEZONE));
    }

    private function businessDate(string $value): DateTimeImmutable {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone(self::BUSINESS_TIMEZONE));
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new WorkloadMakeupException('营业日期无效');
        }
        return $date;
    }
}
