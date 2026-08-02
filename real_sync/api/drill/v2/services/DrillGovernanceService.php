<?php

declare(strict_types=1);

require_once __DIR__ . '/DrillMediaService.php';

final class DrillGovernanceService
{
    public function __construct(private PDO $pdo, private ?string $storageRoot = null)
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
        $summary = ['eligible_count' => count($assets), 'expired_count' => 0, 'physical_cleanup' => $dryRun ? 'preview' : 'completed', 'assets' => array_map(static fn(array $row): array => ['audio_asset_id' => (int) $row['id'], 'attempt_id' => (int) $row['attempt_id']], $assets)];
        if (!$dryRun && $assets !== []) {
            $cleanup = (new DrillMediaService($this->pdo, $this->storageRoot))->expireDueAudioAssets(new DateTimeImmutable('now'), 500);
            $summary['expired_count'] = (int) $cleanup['expired_count'];
            $summary['expired_audio_asset_ids'] = $cleanup['expired_audio_asset_ids'];
            $summary['cleanup_results'] = $cleanup['physical_cleanup'];
            $this->audit($actorStaffId, 'audio.retention_expired', 'drill_audio_asset', 0, $summary);
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
