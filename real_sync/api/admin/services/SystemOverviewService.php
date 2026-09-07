<?php

declare(strict_types=1);

final class SystemOverviewService
{
    public function __construct(private PDO $db) {}

    public function metrics(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now');
        $queries = [
            'locked_account_count' => [
                'SELECT COUNT(*) FROM staffs WHERE account_locked_until > ?',
                [$now->format('Y-m-d H:i:s')],
            ],
            'system_error_count' => [
                "SELECT COUNT(*) FROM system_error_logs WHERE created_at >= ? AND created_at < ? AND level IN ('error', 'critical', 'fatal')",
                [$now->format('Y-m-d 00:00:00'), $now->modify('+1 day')->format('Y-m-d 00:00:00')],
            ],
        ];
        $summary = [];
        $availability = [];
        foreach ($queries as $key => [$sql, $params]) {
            try {
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
                $value = $stmt->fetchColumn();
                if ($value === false) throw new RuntimeException('metric_result_missing');
                $summary[$key] = (int) $value;
                $availability[$key] = 'available';
            } catch (Throwable $error) {
                error_log('[admin.system.overview] ' . $key . ': ' . $error->getMessage());
                $summary[$key] = null;
                $availability[$key] = 'unavailable';
            }
        }
        return ['summary' => $summary, 'metric_availability' => $availability];
    }
}
