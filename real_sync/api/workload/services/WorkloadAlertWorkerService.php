<?php
declare(strict_types=1);

require_once __DIR__ . '/WorkloadObligationService.php';
require_once __DIR__ . '/WorkloadReportStateService.php';
require_once __DIR__ . '/WorkloadAlertService.php';
require_once __DIR__ . '/WorkloadRecommendationService.php';

final class WorkloadAlertWorkerService {
    private const BUSINESS_TIMEZONE = 'Asia/Shanghai';
    private const MAX_ATTEMPTS = 3;

    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function run(?DateTimeImmutable $now = null): array {
        $now = ($now ?: new DateTimeImmutable('now', new DateTimeZone(self::BUSINESS_TIMEZONE)))
            ->setTimezone(new DateTimeZone(self::BUSINESS_TIMEZONE));
        $this->ensureRunLogTable();
        $runKey = $now->format('Y-m-d-H-i');
        $runId = $this->startRun($runKey, $now);
        $lastError = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $summary = [
                    'run_key' => $runKey,
                    'attempt_count' => $attempt,
                    'obligations' => (new WorkloadObligationService($this->pdo))->generateForDate($now->format('Y-m-d')),
                    'locks' => (new WorkloadReportStateService($this->pdo))->lockExpired($now),
                    'alerts' => (new WorkloadAlertService($this->pdo))->evaluate($now),
                    'recommendations' => (new WorkloadRecommendationService($this->pdo))->evaluate($now->format('Y-m-d')),
                ];
                $status = $summary['alerts']['notification_failures'] === [] ? 'completed' : 'completed_with_warnings';
                $this->completeRun($runId, $status, $attempt, $summary);
                return $summary + ['status' => $status];
            } catch (Throwable $error) {
                $lastError = $error;
                $this->recordAttemptFailure($runId, $attempt, $error);
            }
        }

        $this->failRun($runId, self::MAX_ATTEMPTS, $lastError);
        throw $lastError ?: new RuntimeException('预警 worker 执行失败');
    }

    private function ensureRunLogTable(): void {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS workload_alert_worker_runs ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
            . 'run_key VARCHAR(32) NOT NULL, business_date DATE NOT NULL, status VARCHAR(32) NOT NULL, '
            . 'attempt_count INT UNSIGNED NOT NULL DEFAULT 0, summary_json LONGTEXT NULL, '
            . 'error_message VARCHAR(500) NULL, started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
            . 'completed_at DATETIME NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, '
            . 'PRIMARY KEY (id), UNIQUE KEY uq_workload_alert_worker_run_key (run_key), '
            . 'KEY idx_workload_alert_worker_runs_status (status, business_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    private function startRun(string $runKey, DateTimeImmutable $now): int {
        $stmt = $this->pdo->prepare(
            "INSERT INTO workload_alert_worker_runs (run_key, business_date, status, attempt_count, started_at) "
            . "VALUES (?, ?, 'running', 0, ?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), "
            . "status = 'running', attempt_count = 0, error_message = NULL, started_at = VALUES(started_at), completed_at = NULL"
        );
        $stmt->execute([$runKey, $now->format('Y-m-d'), $now->format('Y-m-d H:i:s')]);
        return (int) $this->pdo->lastInsertId();
    }

    private function completeRun(int $runId, string $status, int $attempt, array $summary): void {
        $stmt = $this->pdo->prepare(
            'UPDATE workload_alert_worker_runs SET status = ?, attempt_count = ?, summary_json = ?, '
            . 'error_message = NULL, completed_at = NOW() WHERE id = ?'
        );
        $stmt->execute([
            $status,
            $attempt,
            json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $runId,
        ]);
    }

    private function recordAttemptFailure(int $runId, int $attempt, Throwable $error): void {
        $stmt = $this->pdo->prepare(
            "UPDATE workload_alert_worker_runs SET status = 'retrying', attempt_count = ?, error_message = ? WHERE id = ?"
        );
        $stmt->execute([$attempt, mb_substr($error->getMessage(), 0, 500), $runId]);
    }

    private function failRun(int $runId, int $attempt, ?Throwable $error): void {
        $stmt = $this->pdo->prepare(
            "UPDATE workload_alert_worker_runs SET status = 'failed', attempt_count = ?, error_message = ?, completed_at = NOW() WHERE id = ?"
        );
        $stmt->execute([$attempt, mb_substr($error?->getMessage() ?? 'unknown_error', 0, 500), $runId]);
    }
}
