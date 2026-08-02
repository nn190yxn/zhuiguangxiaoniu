<?php
declare(strict_types=1);

interface MigrationReadinessDatabase
{
    public function value(string $sql, array $params = []): mixed;

    public function rows(string $sql, array $params = []): array;
}

final class PdoMigrationReadinessDatabase implements MigrationReadinessDatabase
{
    public function __construct(private PDO $db)
    {
    }

    public function value(string $sql, array $params = []): mixed
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    public function rows(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

final class MigrationReadiness
{
    public function __construct(private MigrationReadinessDatabase $db, private array $catalog)
    {
    }

    public function check(array $versions = []): array
    {
        $versions = $this->versions($versions);
        $issues = [];
        if ((int)$this->db->value(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations'"
        ) !== 1) {
            return $this->result($versions, [['type' => 'history_table_missing']]);
        }

        $placeholders = implode(',', array_fill(0, count($versions), '?'));
        $historyRows = $this->db->rows(
            'SELECT version, checksum, status FROM schema_migrations WHERE version IN (' . $placeholders . ')',
            $versions
        );
        $history = [];
        foreach ($historyRows as $row) {
            $history[(string)$row['version']] = $row;
        }

        foreach ($versions as $version) {
            $row = $history[$version] ?? null;
            if ($row === null || ($row['status'] ?? '') !== 'applied') {
                $issues[] = ['version' => $version, 'type' => 'migration_not_applied'];
                continue;
            }
            if (!hash_equals((string)$this->catalog[$version]['sql_checksum'], (string)$row['checksum'])) {
                $issues[] = ['version' => $version, 'type' => 'checksum_mismatch'];
            }
        }

        $structure = $this->structure($versions);
        foreach ($versions as $version) {
            $expectation = $this->catalog[$version];
            foreach ($expectation['tables'] as $table) {
                if (!isset($structure['tables'][$table])) {
                    $issues[] = ['version' => $version, 'type' => 'missing_table', 'target' => $table];
                }
            }
            foreach ($expectation['columns'] as $table => $columns) {
                foreach ($columns as $column) {
                    if (!isset($structure['columns'][$table][$column])) {
                        $issues[] = ['version' => $version, 'type' => 'missing_column', 'target' => $table . '.' . $column];
                    }
                }
            }
            foreach ($expectation['indexes'] as $table => $indexes) {
                foreach ($indexes as $index) {
                    $alternatives = is_array($index) ? $index : [$index];
                    $matched = array_filter($alternatives, static fn(string $name): bool => isset($structure['indexes'][$table][$name]));
                    if ($matched === []) {
                        $issues[] = ['version' => $version, 'type' => 'missing_index', 'target' => $table . '.' . implode('|', $alternatives)];
                    }
                }
            }
        }
        return $this->result($versions, $issues);
    }

    public function verifyData(array $versions = []): array
    {
        $versions = $this->versions($versions);
        $issues = [];
        foreach ($versions as $version) {
            foreach ($this->catalog[$version]['data_checks'] as $check) {
                $sql = trim((string)($check['sql'] ?? ''));
                if (($check['type'] ?? '') !== 'expected_zero' || !preg_match('/^(SELECT|WITH)\b/i', $sql)) {
                    throw new InvalidArgumentException('Unsupported migration data check: ' . $version);
                }
                $actual = (int)$this->db->value($sql);
                if ($actual !== 0) {
                    $issues[] = [
                        'version' => $version,
                        'type' => 'data_check_failed',
                        'check_id' => (string)$check['id'],
                        'difference_count' => $actual,
                    ];
                }
            }
        }
        return $this->result($versions, $issues);
    }

    private function structure(array $versions): array
    {
        $tables = [];
        $columns = [];
        $indexes = [];
        foreach ($versions as $version) {
            $expectation = $this->catalog[$version];
            $tables = array_merge($tables, $expectation['tables'], array_keys($expectation['columns']), array_keys($expectation['indexes']));
        }
        $tables = array_values(array_unique($tables));
        if ($tables === []) {
            return ['tables' => [], 'columns' => [], 'indexes' => []];
        }
        $placeholders = implode(',', array_fill(0, count($tables), '?'));
        $tableRows = $this->db->rows(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $placeholders . ')',
            $tables
        );
        $columnRows = $this->db->rows(
            'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $placeholders . ')',
            $tables
        );
        $indexRows = $this->db->rows(
            'SELECT TABLE_NAME, INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $placeholders . ')',
            $tables
        );
        $structure = ['tables' => [], 'columns' => [], 'indexes' => []];
        foreach ($tableRows as $row) {
            $structure['tables'][(string)$row['TABLE_NAME']] = true;
        }
        foreach ($columnRows as $row) {
            $structure['columns'][(string)$row['TABLE_NAME']][(string)$row['COLUMN_NAME']] = true;
        }
        foreach ($indexRows as $row) {
            $structure['indexes'][(string)$row['TABLE_NAME']][(string)$row['INDEX_NAME']] = true;
        }
        return $structure;
    }

    private function versions(array $versions): array
    {
        if ($versions === []) {
            return array_map('strval', array_keys($this->catalog));
        }
        $versions = array_values(array_unique(array_map('strval', $versions)));
        foreach ($versions as $version) {
            if (!isset($this->catalog[$version])) {
                throw new InvalidArgumentException('Unknown migration version: ' . $version);
            }
        }
        return $versions;
    }

    private function result(array $versions, array $issues): array
    {
        $compatibility = [];
        foreach ($versions as $version) {
            $compatibility[$version] = $this->catalog[$version]['compatibility'];
        }
        return [
            'ready' => $issues === [],
            'checked_versions' => $versions,
            'issues' => $issues,
            'compatibility' => $compatibility,
        ];
    }
}
