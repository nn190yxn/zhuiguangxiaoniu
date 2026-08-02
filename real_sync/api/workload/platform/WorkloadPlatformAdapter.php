<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/kernel/bootstrap.php';

final class WorkloadPlatformAdapter
{
    private const SUBMISSION_TABLES = [
        'metric_definitions',
        'workload_templates',
        'workload_template_items',
        'workload_daily_reports',
        'workload_daily_report_values',
        'workload_metric_versions',
        'workload_role_rule_versions',
        'workload_submission_obligations',
        'workload_evidences',
        'workload_audit_tasks',
        'platform_sync_changes',
    ];

    private const WORKER_TABLES = [
        'workload_export_jobs',
        'workload_alert_worker_runs',
    ];

    private const REQUIRED_COLUMNS = [
        'workload_daily_reports' => ['id', 'updated_at', 'metric_version_id', 'rule_version_id'],
        'workload_export_jobs' => ['job_key', 'file_path', 'expires_at', 'status'],
        'platform_sync_changes' => ['scope_hash', 'domain', 'object_type', 'object_id', 'state_version', 'sync_level'],
    ];

    public static function assertSubmissionReady(PDO $db): void
    {
        self::assertResult(self::readiness($db, false));
    }

    public static function assertReady(PDO $db): void
    {
        self::assertResult(self::readiness($db));
    }

    public static function readiness(PDO $db, bool $includeWorkers = true): array
    {
        try {
            $requiredTables = self::SUBMISSION_TABLES;
            if ($includeWorkers) {
                $requiredTables = array_merge($requiredTables, self::WORKER_TABLES);
            }
            $tablePlaceholders = implode(',', array_fill(0, count($requiredTables), '?'));
            $tableStatement = $db->prepare(
                'SELECT TABLE_NAME FROM information_schema.TABLES '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $tablePlaceholders . ')'
            );
            $tableStatement->execute($requiredTables);
            $foundTables = array_map('strval', $tableStatement->fetchAll(PDO::FETCH_COLUMN));
            $missingTables = array_values(array_diff($requiredTables, $foundTables));

            $columnTables = array_keys(self::REQUIRED_COLUMNS);
            if (!$includeWorkers) {
                $columnTables = array_values(array_diff($columnTables, ['workload_export_jobs']));
            }
            $columnPlaceholders = implode(',', array_fill(0, count($columnTables), '?'));
            $columnStatement = $db->prepare(
                'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $columnPlaceholders . ')'
            );
            $columnStatement->execute($columnTables);
            $foundColumns = [];
            foreach ($columnStatement->fetchAll(PDO::FETCH_ASSOC) as $column) {
                $foundColumns[(string)$column['TABLE_NAME']][] = (string)$column['COLUMN_NAME'];
            }
            $missingColumns = [];
            foreach ($columnTables as $table) {
                foreach (self::REQUIRED_COLUMNS[$table] as $column) {
                    if (!in_array($column, $foundColumns[$table] ?? [], true)) {
                        $missingColumns[] = $table . '.' . $column;
                    }
                }
            }

            return [
                'status' => $missingTables === [] && $missingColumns === [] ? 'healthy' : 'unhealthy',
                'missing_tables' => $missingTables,
                'missing_columns' => $missingColumns,
            ];
        } catch (Throwable) {
            return [
                'status' => 'unhealthy',
                'error' => 'workload_readiness_check_failed',
                'missing_tables' => [],
                'missing_columns' => [],
            ];
        }
    }

    public static function expectedVersion(array $input): ?int
    {
        if (!array_key_exists('state_version', $input)) {
            return null;
        }
        $version = filter_var($input['state_version'], FILTER_VALIDATE_INT);
        if ($version === false || $version < 0) {
            throw new PlatformApiException(400, 'state_version_required', '请提供有效的状态版本');
        }
        return $version;
    }

    public static function submissionState(PDO $db, ?array $report, array $state, array $staffContext): ?array
    {
        if ($report === null || (int)($report['id'] ?? 0) <= 0) {
            return null;
        }
        $objectId = (string)(int)$report['id'];
        $scopeHash = self::scopeHash($staffContext);
        $statement = $db->prepare(
            "SELECT state_version, occurred_at FROM platform_sync_changes "
            . "WHERE scope_hash = ? AND domain = 'workload' AND object_type = 'submission' AND object_id = ? "
            . 'ORDER BY state_version DESC LIMIT 1'
        );
        $statement->execute([$scopeHash, $objectId]);
        $change = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        $version = max(0, (int)($change['state_version'] ?? 0));
        $updatedAt = (string)($change['occurred_at'] ?? $report['updated_at'] ?? gmdate('Y-m-d H:i:s'));
        return PlatformSyncProtocol::syncObject('submission', $objectId, $version, $updatedAt, 'A', $state);
    }

    public static function recordSubmission(
        PDO $db,
        array $report,
        array $state,
        array $staffContext,
        ?int $expectedVersion
    ): array {
        if (!$db->inTransaction()) {
            throw new LogicException('workload_submission_sync_transaction_required');
        }
        $objectId = (string)(int)($report['id'] ?? 0);
        if ($objectId === '0') {
            throw new InvalidArgumentException('workload_submission_id_required');
        }
        $scopeHash = self::scopeHash($staffContext);
        $versionStatement = $db->prepare(
            "SELECT state_version FROM platform_sync_changes "
            . "WHERE scope_hash = ? AND domain = 'workload' AND object_type = 'submission' AND object_id = ? "
            . 'ORDER BY state_version DESC LIMIT 1 FOR UPDATE'
        );
        $versionStatement->execute([$scopeHash, $objectId]);
        $currentVersion = max(0, (int)($versionStatement->fetchColumn() ?: 0));
        if ($expectedVersion !== null) {
            PlatformStateVersion::assertExpected($currentVersion, $expectedVersion, [
                'object_type' => 'submission',
                'object_id' => $objectId,
                'authoritative_state' => $state,
            ]);
        }
        $nextVersion = PlatformStateVersion::next($currentVersion);
        $updatedAt = (string)($report['updated_at'] ?? gmdate('Y-m-d H:i:s'));
        $sync = PlatformSyncProtocol::syncObject('submission', $objectId, $nextVersion, $updatedAt, 'A', $state);
        $insert = $db->prepare(
            'INSERT INTO platform_sync_changes '
            . '(scope_hash, domain, object_type, object_id, state_version, sync_level, status, state_json, etag, reason, occurred_at, created_at) '
            . "VALUES (?, 'workload', 'submission', ?, ?, 'A', 'active', ?, ?, 'report_saved', ?, ?)"
        );
        $insert->execute([
            $scopeHash,
            $objectId,
            $nextVersion,
            json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $sync['etag'],
            $updatedAt,
            $updatedAt,
        ]);
        return $sync;
    }

    private static function scopeHash(array $staffContext): string
    {
        $storeId = (int)($staffContext['store_id'] ?? 0);
        return PlatformSyncProtocol::scopeHash([
            'staff_id' => (int)($staffContext['staff_id'] ?? 0),
            'session_version' => 0,
            'scope_type' => !empty($staffContext['permissions']['can_view_all']) ? 'all' : 'self',
            'store_ids' => $storeId > 0 ? [$storeId] : [],
        ]);
    }

    private static function assertResult(array $result): void
    {
        if (($result['status'] ?? 'unhealthy') !== 'healthy') {
            throw new PlatformApiException(503, 'schema_not_ready', '工作量数据库结构尚未就绪', [
                'missing_tables' => $result['missing_tables'] ?? [],
                'missing_columns' => $result['missing_columns'] ?? [],
            ]);
        }
    }
}
