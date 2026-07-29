<?php

declare(strict_types=1);

final class DrillCutoverService
{
    private const LEGACY = ['tasks' => 'user_drill_tasks', 'attempts' => 'drill_recordings', 'recordings' => 'drill_recordings', 'analyses' => 'script_analysis_records', 'certifications' => 'script_ai_feedback'];
    private const V2 = ['tasks' => 'drill_assignments', 'attempts' => 'drill_attempts', 'recordings' => 'drill_audio_assets', 'analyses' => 'drill_evaluations', 'certifications' => 'drill_certifications'];

    public function __construct(private PDO $pdo)
    {
    }

    public function preflight(string $surface, string $batchKey, int $actorStaffId, array $scope = []): array
    {
        if (!in_array($surface, ['admin', 'pwa', 'mini_program'], true) || !preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $batchKey)) throw new InvalidArgumentException('切换批次参数无效。');
        $report = $this->reconcile();
        $status = array_reduce($report, static fn(bool $ok, array $row): bool => $ok && $row['status'] !== 'mismatch', true) ? 'preflight_passed' : 'planned';
        $stmt = $this->pdo->prepare('INSERT INTO drill_cutover_batches (batch_key, surface, status, target_scope_json, preflight_json, created_by_staff_id) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE preflight_json = VALUES(preflight_json), status = VALUES(status)');
        $stmt->execute([$batchKey, $surface, $status, $this->json($scope), $this->json($report), $actorStaffId]);
        $batchId = (int) $this->pdo->query('SELECT id FROM drill_cutover_batches WHERE batch_key = ' . $this->pdo->quote($batchKey))->fetchColumn();
        foreach ($report as $row) { $this->pdo->prepare('INSERT INTO drill_cutover_reconciliations (batch_id, entity_type, legacy_count, v2_count, mapped_count, status, details_json) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE legacy_count = VALUES(legacy_count), v2_count = VALUES(v2_count), mapped_count = VALUES(mapped_count), status = VALUES(status), details_json = VALUES(details_json)')->execute([$batchId, $row['entity_type'], $row['legacy_count'], $row['v2_count'], $row['mapped_count'], $row['status'], $this->json($row)]); }
        return ['batch_id' => $batchId, 'surface' => $surface, 'status' => $status, 'reconciliation' => $report, 'production_switch' => 'not_executed'];
    }

    public function rollbackPlan(int $batchId, int $actorStaffId): array
    {
        $plan = ['freeze_new_v2_writes' => true, 'restore_legacy_read_routes' => true, 'retain_v2_as_readonly' => true, 'verify_legacy_feedback_adapter' => true, 'no_delete_operations' => true];
        $this->pdo->prepare("UPDATE drill_cutover_batches SET status = 'rollback_planned' WHERE id = ? AND status IN ('planned', 'preflight_passed', 'drill_completed')")->execute([$batchId]);
        $this->pdo->prepare('INSERT INTO drill_cutover_rollback_drills (batch_id, status, plan_json) VALUES (?, \'planned\', ?) ON DUPLICATE KEY UPDATE plan_json = VALUES(plan_json)')->execute([$batchId, $this->json($plan)]);
        $this->pdo->prepare('INSERT INTO drill_audit_logs (actor_staff_id, action, object_type, object_id, after_snapshot_json) VALUES (?, ?, ?, ?, ?)')->execute([$actorStaffId, 'cutover.rollback_drill_planned', 'drill_cutover_batch', $batchId, $this->json($plan)]);
        return ['batch_id' => $batchId, 'status' => 'rollback_planned', 'plan' => $plan, 'production_rollback' => 'not_executed'];
    }

    private function reconcile(): array
    {
        $items = [];
        foreach (self::LEGACY as $type => $legacyTable) {
            $legacy = $this->tableExists($legacyTable) ? $this->count($legacyTable) : 0;
            $v2 = $this->count(self::V2[$type]);
            $mapped = $type === 'recordings' ? $this->count('drill_legacy_history_instances') : ($type === 'analyses' ? $this->count('drill_legacy_feedback_mappings') : 0);
            $items[] = ['entity_type' => $type, 'legacy_count' => $legacy, 'v2_count' => $v2, 'mapped_count' => $mapped, 'status' => $legacy === 0 || $mapped >= $legacy ? 'matched' : 'mismatch'];
        }
        return $items;
    }
    private function tableExists(string $table): bool { $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'); $stmt->execute([$table]); return (int) $stmt->fetchColumn() === 1; }
    private function count(string $table): int { return (int) $this->pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn(); }
    private function json(array $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
}
