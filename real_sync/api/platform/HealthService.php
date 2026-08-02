<?php
declare(strict_types=1);

final class PlatformHealthService
{
    public static function live(): array
    {
        return self::result('healthy', [
            'application' => [
                'status' => 'healthy',
                'php_version' => PHP_VERSION,
            ],
        ]);
    }

    public static function ready(PDO $db, array $versions): array
    {
        $checks = [
            'application' => ['status' => 'healthy'],
            'database' => self::database($db),
            'migrations' => self::migrations($db, $versions),
            'legacy_endpoint_governance' => self::legacyEndpointGovernance($db),
            'workload' => self::workload($db),
        ];

        return self::result(self::overall($checks), $checks);
    }

    public static function dependencies(PDO $db, array $versions, array $environment = []): array
    {
        $checks = [
            'database' => self::database($db),
            'migrations' => self::migrations($db, $versions),
            'legacy_endpoint_governance' => self::legacyEndpointGovernance($db),
            'workload' => self::workload($db),
            'queue' => self::queue($db),
            'worker' => self::worker(),
            'external' => self::external($environment),
        ];

        return self::result(self::overall($checks), $checks);
    }

    private static function database(PDO $db): array
    {
        try {
            $statement = $db->query('SELECT 1');
            $healthy = $statement !== false && (int)$statement->fetchColumn() === 1;
            return ['status' => $healthy ? 'healthy' : 'unhealthy'];
        } catch (Throwable $error) {
            return ['status' => 'unhealthy', 'error' => 'connection_failed'];
        }
    }

    private static function migrations(PDO $db, array $versions): array
    {
        try {
            require_once dirname(__DIR__, 2) . '/database/MigrationReadiness.php';
            $catalog = require dirname(__DIR__, 2) . '/database/migration_catalog.php';
            $result = (new MigrationReadiness(new PdoMigrationReadinessDatabase($db), $catalog))->check($versions);
            return [
                'status' => $result['ready'] ? 'healthy' : 'unhealthy',
                'checked_versions' => count($result['checked_versions']),
                'issue_count' => count($result['issues']),
            ];
        } catch (Throwable $error) {
            return ['status' => 'unhealthy', 'error' => 'readiness_check_failed'];
        }
    }

    private static function queue(PDO $db): array
    {
        try {
            $tables = ['platform_outbox_events', 'platform_jobs'];
            $placeholders = implode(',', array_fill(0, count($tables), '?'));
            $statement = $db->prepare(
                'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $placeholders . ')'
            );
            $statement->execute($tables);
            $found = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
            $backlog = ['oldest_pending_age_seconds' => null, 'backlog_degraded' => false];
            if (in_array('platform_jobs', $found, true)) {
                $ageStatement = $db->query(
                    "SELECT TIMESTAMPDIFF(SECOND, MIN(created_at), CURRENT_TIMESTAMP)
                     FROM platform_jobs WHERE status IN ('pending', 'retry_wait')"
                );
                $age = $ageStatement->fetchColumn();
                $age = $age === false || $age === null ? null : max(0, (int)$age);
                $backlog = ['oldest_pending_age_seconds' => $age, 'backlog_degraded' => $age !== null && $age >= 300];
            }
            return [
                'status' => !in_array('platform_jobs', $found, true) ? 'not_configured' : ($backlog['backlog_degraded'] ? 'degraded' : 'healthy'),
                'registered_tables' => $found,
                ...$backlog,
            ];
        } catch (Throwable $error) {
            return ['status' => 'unhealthy', 'error' => 'queue_check_failed'];
        }
    }

    private static function legacyEndpointGovernance(PDO $db): array
    {
        require_once __DIR__ . '/LegacyEndpointGovernance.php';
        return LegacyEndpointGovernance::readiness($db);
    }

    private static function workload(PDO $db): array
    {
        require_once dirname(__DIR__) . '/workload/platform/WorkloadPlatformAdapter.php';
        return WorkloadPlatformAdapter::readiness($db);
    }

    private static function worker(): array
    {
        return [
            'status' => PHP_SAPI === 'cli' || is_dir(dirname(__DIR__, 2) . '/scripts') ? 'healthy' : 'unhealthy',
            'cli_available' => PHP_SAPI === 'cli',
            'scripts_directory' => is_dir(dirname(__DIR__, 2) . '/scripts'),
        ];
    }

    private static function external(array $environment): array
    {
        $configured = [];
        foreach ($environment as $name => $value) {
            $configured[$name] = $value !== null && $value !== '';
        }
        $missing = array_keys(array_filter($configured, static fn(bool $value): bool => !$value));
        return [
            'status' => $missing === [] ? 'healthy' : 'degraded',
            'configured' => array_keys(array_filter($configured)),
            'missing' => $missing,
        ];
    }

    private static function overall(array $checks): string
    {
        $statuses = array_column($checks, 'status');
        if (in_array('unhealthy', $statuses, true)) {
            return 'unhealthy';
        }
        if (in_array('degraded', $statuses, true)) {
            return 'degraded';
        }
        return 'healthy';
    }

    private static function result(string $status, array $checks): array
    {
        return [
            'status' => $status,
            'checked_at' => gmdate('c'),
            'checks' => $checks,
        ];
    }
}
