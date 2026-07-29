<?php

declare(strict_types=1);

final class DrillGovernanceService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function monitor(): array
    {
        return [
            'audio_expiry_pending' => $this->count("SELECT COUNT(*) FROM drill_audio_assets WHERE retention_until <= CURRENT_TIMESTAMP AND status <> 'expired'"),
            'ai_retry_pending' => $this->count("SELECT COUNT(*) FROM drill_evaluations WHERE status = 'retry_pending'"),
            'migration_failed' => $this->count("SELECT COUNT(*) FROM drill_migration_batches WHERE status = 'failed'"),
            'migration_unreconciled' => $this->count("SELECT COUNT(*) FROM drill_migration_batches WHERE status <> 'completed'"),
            'checked_at' => gmdate('c'),
        ];
    }

    public function expireAudio(int $actorStaffId, bool $dryRun = true): array
    {
        $assets = $this->rows("SELECT id, attempt_id, storage_path FROM drill_audio_assets WHERE retention_until <= CURRENT_TIMESTAMP AND status <> 'expired' ORDER BY id LIMIT 500");
        $summary = ['eligible_count' => count($assets), 'expired_count' => 0, 'physical_cleanup' => 'manual_or_deployment_worker_required', 'assets' => array_map(static fn(array $row): array => ['audio_asset_id' => (int) $row['id'], 'attempt_id' => (int) $row['attempt_id']], $assets)];
        if (!$dryRun && $assets !== []) {
            $this->pdo->beginTransaction();
            try {
                $update = $this->pdo->prepare("UPDATE drill_audio_assets SET status = 'expired', expired_at = CURRENT_TIMESTAMP, storage_path = CONCAT('expired:', id) WHERE id = ? AND status <> 'expired'");
                foreach ($assets as $asset) { $update->execute([(int) $asset['id']]); $summary['expired_count'] += $update->rowCount(); }
                $this->audit($actorStaffId, 'audio.retention_expired', 'drill_audio_asset', 0, $summary);
                $this->pdo->commit();
            } catch (Throwable $error) { $this->pdo->rollBack(); throw $error; }
        }
        return $this->record('audio_expiry', $dryRun ? 'preview' : 'completed', $dryRun, $summary, $actorStaffId);
    }

    public function retryQueue(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        return ['items' => $this->rows("SELECT id, attempt_id, evaluation_context, failure_code, updated_at FROM drill_evaluations WHERE status = 'retry_pending' ORDER BY updated_at ASC LIMIT " . $limit), 'retry_policy' => 'worker_rebuilds_context_then_retries; invalid output remains retry_pending'];
    }

    private function record(string $type, string $status, bool $dryRun, array $summary, int $actor): array
    {
        $stmt = $this->pdo->prepare('INSERT INTO drill_governance_runs (run_type, status, dry_run, summary_json, actor_staff_id, completed_at) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)');
        $stmt->execute([$type, $status, $dryRun ? 1 : 0, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $actor]);
        return ['run_id' => (int) $this->pdo->lastInsertId(), 'dry_run' => $dryRun, ...$summary];
    }
    private function audit(int $actor, string $action, string $type, int $id, array $after): void { $stmt = $this->pdo->prepare('INSERT INTO drill_audit_logs (actor_staff_id, action, object_type, object_id, after_snapshot_json) VALUES (?, ?, ?, ?, ?)'); $stmt->execute([$actor, $action, $type, $id, json_encode($after, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]); }
    private function count(string $sql): int { return (int) $this->pdo->query($sql)->fetchColumn(); }
    private function rows(string $sql): array { return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: []; }
}
