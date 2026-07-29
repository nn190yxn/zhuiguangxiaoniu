<?php

declare(strict_types=1);

final class DrillMigrationService
{
    private const LEGACY_TABLES = [
        'drill_templates', 'drill_scripts', 'user_drill_tasks', 'drill_recordings',
        'script_ai_feedback', 'script_dimensions', 'script_knowledge',
        'script_analysis_records', 'drill_conversations', 'drill_messages',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    public function preflight(): array
    {
        $counts = [];
        $duplicates = [];
        $orphans = [];
        foreach (self::LEGACY_TABLES as $table) {
            if (!$this->tableExists($table)) {
                $counts[$table] = null;
                continue;
            }
            $counts[$table] = (int) $this->pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
            $duplicates[$table] = $this->duplicateIds($table);
        }
        $orphans['drill_recordings.task_id'] = $this->orphanCount('drill_recordings', 'task_id', 'user_drill_tasks');
        $orphans['script_ai_feedback.recording_id'] = $this->orphanCount('script_ai_feedback', 'recording_id', 'drill_recordings');
        $orphans['script_analysis_records.script_id'] = $this->orphanCount('script_analysis_records', 'script_id', 'drill_scripts');
        return [
            'status' => 'preflight_only',
            'execution_available' => true,
            'source_counts' => $counts,
            'duplicate_ids' => $duplicates,
            'orphan_references' => $orphans,
            'unmappable' => $this->unmappableReport($counts, $orphans),
            'legacy_access' => 'read_only_select',
        ];
    }

    public function execute(string $batchKey, bool $retryFailed = false): array
    {
        if (!preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $batchKey)) {
            throw new InvalidArgumentException('迁移批次键无效。');
        }
        $preflight = $this->preflight();
        $hash = hash('sha256', $batchKey);
        $this->pdo->beginTransaction();
        try {
            $batch = $this->upsertBatch($hash, $preflight['source_counts'], $retryFailed);
            if ($batch['status'] === 'completed' && !$retryFailed) {
                $report = json_decode((string) ($batch['report_json'] ?? '{}'), true) ?: [];
                $this->pdo->commit();
                return ['batch_id' => (int) $batch['id'], 'batch_key' => $batchKey, 'status' => 'completed', 'report' => $report, 'idempotent_replay' => true];
            }
            $this->migrateContent($batch['id']);
            $this->migrateHistory($batch['id']);
            $this->preserveUnhandledSources($batch['id']);
            $report = $this->reconcile((int) $batch['id'], $preflight['source_counts']);
            $status = $report['failed'] > 0 ? 'failed' : 'completed';
            $stmt = $this->pdo->prepare('UPDATE drill_migration_batches SET status = ?, report_json = ?, completed_at = IF(? = \'completed\', CURRENT_TIMESTAMP, NULL), last_error = ? WHERE id = ?');
            $stmt->execute([$status, $this->json($report), $status, $status === 'failed' ? '迁移存在失败项目，可使用 retry_failed 重试。' : null, $batch['id']]);
            $this->pdo->commit();
            return ['batch_id' => (int) $batch['id'], 'batch_key' => $batchKey, 'status' => $status, 'report' => $report];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $stmt = $this->pdo->prepare("UPDATE drill_migration_batches SET status = 'failed', last_error = ?, attempt_count = attempt_count + 1 WHERE batch_key = ?");
            $stmt->execute([mb_substr($error->getMessage(), 0, 1000), $hash]);
            throw $error;
        }
    }

    public function historyForLegacyFeedback(string $legacyId, ?int $userId = null): ?array
    {
        $sql = 'SELECT mapping.*, history.* FROM drill_legacy_feedback_mappings mapping LEFT JOIN drill_legacy_history_instances history ON history.id = mapping.history_instance_id WHERE (mapping.legacy_feedback_id = ? OR mapping.legacy_analysis_id = ? OR mapping.legacy_recording_id = ?)';
        $params = [$legacyId, $legacyId, $legacyId];
        if ($userId !== null) {
            $sql .= ' AND (history.legacy_user_id = ? OR history.legacy_user_id IS NULL)';
            $params[] = $userId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function migrateContent(int $batchId): void
    {
        foreach (['drill_scripts', 'script_knowledge'] as $table) {
            foreach ($this->legacyRows($table) as $row) {
                $id = (string) ($row['id'] ?? '');
                if ($id === '') {
                    $this->item($batchId, $table, 'missing-id-' . hash('sha256', $this->json($row)), 'failed', null, null, $row, '缺少旧 ID', true);
                    continue;
                }
                $summary = $this->summary($table, $row);
                $sourceKind = $this->contentSourceType($row);
                // Keep source ID spaces separate because old script tables can reuse integers.
                $sourceType = $sourceKind . ':' . $table;
                $contentBatchId = $this->contentBatch($batchId, $sourceKind);
                $this->item($batchId, $table, $id, 'pending_review', 'legacy_content_mapping', null, $summary);
                $mapping = $this->pdo->prepare('INSERT INTO drill_legacy_content_mappings (source_type, source_id, review_status, migration_batch_id, source_ref, source_snapshot_json) VALUES (?, ?, \'pending\', ?, ?, ?) ON DUPLICATE KEY UPDATE migration_batch_id = VALUES(migration_batch_id), source_snapshot_json = VALUES(source_snapshot_json)');
                $mapping->execute([$sourceType, $id, $batchId, $table . ':' . $id, $this->json($summary)]);
                $item = $this->pdo->prepare('INSERT INTO drill_content_import_items (batch_id, domain_id, content_type, stable_code, review_status, source_ref, payload_hash, source_snapshot_json) VALUES (?, ?, \'scenario\', ?, \'pending\', ?, ?, ?) ON DUPLICATE KEY UPDATE source_snapshot_json = VALUES(source_snapshot_json)');
                $item->execute([$contentBatchId['id'], $contentBatchId['domain_id'], 'legacy_' . $sourceType . '_' . $id, $table . ':' . $id, hash('sha256', $this->json($summary)), $this->json($summary)]);
            }
        }
    }

    private function migrateHistory(int $batchId): void
    {
        foreach ($this->legacyRows('drill_recordings') as $recording) {
            $recordingId = (string) ($recording['id'] ?? '');
            if ($recordingId === '') {
                continue;
            }
            $analysis = $this->firstLegacyRow('script_analysis_records', 'audio_url', (string) ($recording['audio_url'] ?? ''));
            $feedback = $this->firstLegacyRow('script_ai_feedback', 'recording_id', $recordingId);
            $task = $this->firstLegacyRow('user_drill_tasks', 'id', (string) ($recording['task_id'] ?? ''));
            $summary = $this->summary('drill_recordings', $recording, $analysis, $feedback, $task);
            $complete = $task !== null && ($analysis !== null || $feedback !== null);
            if (!$complete) {
                $this->item($batchId, 'drill_recordings', $recordingId, 'preserved_summary', 'legacy_source_summary', null, $summary);
                continue;
            }
            $key = $this->migrationKey('legacy_history_instance', $recordingId);
            $insert = $this->pdo->prepare('INSERT INTO drill_legacy_history_instances (migration_key, legacy_task_id, legacy_recording_id, legacy_analysis_id, legacy_user_id, participant_source_json, reference_source_json, context_snapshot_json, source_summary_json, occurred_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE source_summary_json = VALUES(source_summary_json)');
            $insert->execute([$key, (string) ($task['id'] ?? ''), $recordingId, $analysis['id'] ?? null, (int) ($recording['user_id'] ?? $task['user_id'] ?? 0) ?: null, $this->json(['source' => 'legacy_recording', 'employee_user_id' => $recording['user_id'] ?? null]), $this->json(['source' => 'legacy_script', 'script_id' => $recording['script_id'] ?? null]), $this->json(['evaluation_context' => 'real_call_review', 'legacy_task_status' => $task['status'] ?? null]), $this->json($summary), $recording['created_at'] ?? null]);
            $historyId = (int) $this->pdo->query('SELECT id FROM drill_legacy_history_instances WHERE migration_key = ' . $this->pdo->quote($key))->fetchColumn();
            $this->item($batchId, 'drill_recordings', $recordingId, 'migrated', 'legacy_history_instance', $historyId, $summary);
            if ($feedback !== null) {
                $this->feedbackMap($feedback, $analysis, $recordingId, $historyId, $summary);
            }
        }
        foreach ($this->legacyRows('script_ai_feedback') as $feedback) {
            $recordingId = (string) ($feedback['recording_id'] ?? '');
            if ($recordingId !== '' && $this->firstLegacyRow('drill_recordings', 'id', $recordingId) !== null) {
                $this->item($batchId, 'script_ai_feedback', (string) ($feedback['id'] ?? ''), 'migrated', 'legacy_feedback_mapping', null, $this->summary('script_ai_feedback', $feedback));
                continue;
            }
            $this->item($batchId, 'script_ai_feedback', (string) ($feedback['id'] ?? ''), 'preserved_summary', 'legacy_source_summary', null, $this->summary('script_ai_feedback', $feedback));
        }
    }

    private function preserveUnhandledSources(int $batchId): void
    {
        foreach (['drill_templates', 'user_drill_tasks', 'script_dimensions', 'script_analysis_records', 'drill_conversations', 'drill_messages'] as $table) {
            foreach ($this->legacyRows($table) as $row) {
                $sourceId = (string) ($row['id'] ?? hash('sha256', $this->json($row)));
                $this->item($batchId, $table, $sourceId, 'preserved_summary', 'legacy_source_summary', null, $this->summary($table, $row));
            }
        }
    }

    private function feedbackMap(array $feedback, ?array $analysis, string $recordingId, int $historyId, array $summary): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO drill_legacy_feedback_mappings (legacy_feedback_id, legacy_analysis_id, legacy_recording_id, history_instance_id, source_summary_json) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE legacy_analysis_id = VALUES(legacy_analysis_id), legacy_recording_id = VALUES(legacy_recording_id), history_instance_id = VALUES(history_instance_id), source_summary_json = VALUES(source_summary_json)');
        $stmt->execute([(string) ($feedback['id'] ?? ''), $analysis['id'] ?? null, $recordingId, $historyId, $this->json($summary)]);
    }

    private function contentBatch(int $migrationBatchId, string $sourceType): array
    {
        $domain = $this->pdo->query("SELECT id FROM drill_training_domains WHERE domain_code = 'new_signing' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$domain) {
            throw new RuntimeException('新签训练域不存在，无法建立旧内容待审核批次。');
        }
        $code = 'legacy_' . $sourceType . '_' . substr(hash('sha256', (string) $migrationBatchId), 0, 16);
        $summary = ['migration_batch_id' => $migrationBatchId, 'source_type' => $sourceType, 'review_policy' => 'pending_review'];
        $insert = $this->pdo->prepare("INSERT INTO drill_content_import_batches (domain_id, batch_code, source_name, source_hash, status, summary_json, imported_by_staff_id, completed_at) VALUES (?, ?, ?, ?, 'review_pending', ?, 0, CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE summary_json = VALUES(summary_json)");
        $insert->execute([(int) $domain['id'], $code, $sourceType, hash('sha256', $code), $this->json($summary)]);
        $select = $this->pdo->prepare('SELECT id, domain_id FROM drill_content_import_batches WHERE batch_code = ? LIMIT 1');
        $select->execute([$code]);
        return $select->fetch(PDO::FETCH_ASSOC) ?: throw new RuntimeException('旧内容待审核批次创建失败。');
    }

    private function upsertBatch(string $key, array $counts, bool $retryFailed): array
    {
        $stmt = $this->pdo->prepare('INSERT INTO drill_migration_batches (batch_key, status, source_counts_json, attempt_count, started_at) VALUES (?, \'running\', ?, 1, CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE status = IF(status = \'completed\', \'completed\', \'running\'), source_counts_json = VALUES(source_counts_json), attempt_count = attempt_count + 1, started_at = CURRENT_TIMESTAMP');
        $stmt->execute([$key, $this->json($counts)]);
        $select = $this->pdo->prepare('SELECT * FROM drill_migration_batches WHERE batch_key = ? FOR UPDATE');
        $select->execute([$key]);
        $batch = $select->fetch(PDO::FETCH_ASSOC);
        if (!$batch || ($batch['status'] === 'completed' && !$retryFailed)) {
            return $batch ?: throw new RuntimeException('迁移批次创建失败。');
        }
        return $batch;
    }

    private function item(int $batchId, string $sourceType, string $sourceId, string $outcome, ?string $targetType, ?int $targetId, array $summary, ?string $error = null, bool $retryable = false): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO drill_migration_items (batch_id, migration_key, source_type, source_id, outcome, target_type, target_id, source_summary_json, error_message, retryable) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE outcome = VALUES(outcome), target_type = VALUES(target_type), target_id = VALUES(target_id), source_summary_json = VALUES(source_summary_json), error_message = VALUES(error_message), retryable = VALUES(retryable)');
        $stmt->execute([$batchId, $this->migrationKey($sourceType, $sourceId), $sourceType, $sourceId, $outcome, $targetType, $targetId, $this->json($summary), $error, $retryable ? 1 : 0]);
    }

    private function reconcile(int $batchId, array $sourceCounts): array
    {
        $rows = $this->pdo->prepare('SELECT outcome, COUNT(*) AS count FROM drill_migration_items WHERE batch_id = ? GROUP BY outcome');
        $rows->execute([$batchId]);
        $report = ['input_total' => array_sum(array_map(static fn($count): int => (int) ($count ?? 0), $sourceCounts)), 'migrated' => 0, 'pending_review' => 0, 'preserved_summary' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $report[$row['outcome']] = (int) $row['count'];
        }
        $report['accounted_total'] = $report['migrated'] + $report['pending_review'] + $report['preserved_summary'] + $report['failed'] + $report['skipped'];
        $report['conserved'] = $report['accounted_total'] === $report['input_total'];
        $report['unrepresented_input'] = max(0, $report['input_total'] - $report['accounted_total']);
        return $report;
    }

    private function legacyRows(string $table): array { return $this->tableExists($table) ? ($this->pdo->query('SELECT * FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC) ?: []) : []; }
    private function firstLegacyRow(string $table, string $column, string $value): ?array { if ($value === '' || !$this->tableExists($table) || !$this->columnExists($table, $column)) return null; $stmt = $this->pdo->prepare('SELECT * FROM `' . $table . '` WHERE `' . $column . '` = ? LIMIT 1'); $stmt->execute([$value]); $row = $stmt->fetch(PDO::FETCH_ASSOC); return $row ?: null; }
    private function tableExists(string $table): bool { $stmt = $this->pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'); $stmt->execute([$table]); return (bool) $stmt->fetchColumn(); }
    private function columnExists(string $table, string $column): bool { $stmt = $this->pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'); $stmt->execute([$table, $column]); return (bool) $stmt->fetchColumn(); }
    private function duplicateIds(string $table): int { return $this->columnExists($table, 'id') ? (int) $this->pdo->query('SELECT COUNT(*) FROM (SELECT id FROM `' . $table . '` GROUP BY id HAVING COUNT(*) > 1) duplicates')->fetchColumn() : 0; }
    private function orphanCount(string $child, string $column, string $parent): ?int { if (!$this->tableExists($child) || !$this->tableExists($parent) || !$this->columnExists($child, $column)) return null; return (int) $this->pdo->query('SELECT COUNT(*) FROM `' . $child . '` c LEFT JOIN `' . $parent . '` p ON p.id = c.`' . $column . '` WHERE c.`' . $column . '` IS NOT NULL AND p.id IS NULL')->fetchColumn(); }
    private function unmappableReport(array $counts, array $orphans): array { return ['missing_tables' => array_keys(array_filter($counts, static fn($count): bool => $count === null)), 'orphan_count' => array_sum(array_map(static fn($count): int => (int) ($count ?? 0), $orphans)), 'policy' => '保留来源摘要并标记为只读。']; }
    private function contentSourceType(array $row): string { $text = implode(' ', array_map('strval', $row)); if (str_contains($text, '七步')) return 'legacy_sales_seven_steps'; if (str_contains($text, '十问')) return 'legacy_sales_ten_questions'; return 'legacy_script'; }
    private function summary(string $source, array ...$rows): array { $sources = []; foreach ($rows as $row) { if ($row !== []) $sources[] = ['source' => $source, 'id' => $row['id'] ?? null, 'snapshot_hash' => hash('sha256', $this->json($row))]; } return ['source_summary' => $sources]; }
    private function migrationKey(string $sourceType, string $sourceId): string { return hash('sha256', 'drill-migration:v1:' . $sourceType . ':' . $sourceId); }
    private function json(array $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
}
