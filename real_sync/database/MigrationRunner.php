<?php
declare(strict_types=1);

final class MigrationRunner {
    private PDO $db;
    private string $migrationDirectory;
    private array $manifest;

    public function __construct(PDO $db, string $migrationDirectory, array $manifest) {
        $this->db = $db;
        $this->migrationDirectory = rtrim($migrationDirectory, '/');
        $this->manifest = $manifest;
    }

    public function ensureHistoryTable(): void {
        $this->db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(32) NOT NULL,
            migration_name VARCHAR(255) NOT NULL,
            checksum CHAR(64) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'running',
            error_message VARCHAR(500) NULL,
            started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            applied_at DATETIME NULL,
            PRIMARY KEY (version),
            KEY idx_schema_migrations_status (status, started_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function status(): array {
        $this->ensureHistoryTable();
        $applied = $this->migrationRows();
        $result = [];
        foreach ($this->migrationFiles() as $migration) {
            $row = $applied[$migration['version']] ?? null;
            $result[] = [
                'version' => $migration['version'],
                'name' => $migration['name'],
                'checksum' => $migration['checksum'],
                'status' => $row['status'] ?? 'pending',
                'checksum_matches' => $row === null || hash_equals((string)$row['checksum'], $migration['checksum']),
            ];
        }
        return $result;
    }

    public function apply(bool $dryRun = false): array {
        $this->ensureHistoryTable();
        $before = $this->snapshot();
        $applied = $this->migrationRows();
        $results = [];

        foreach ($this->migrationFiles() as $migration) {
            $existing = $applied[$migration['version']] ?? null;
            if ($existing && $existing['status'] === 'applied') {
                if (!hash_equals((string)$existing['checksum'], $migration['checksum'])) {
                    throw new RuntimeException('Applied migration checksum changed: ' . $migration['version']);
                }
                $results[] = ['version' => $migration['version'], 'status' => 'already_applied'];
                continue;
            }
            if ($dryRun) {
                $results[] = ['version' => $migration['version'], 'status' => 'would_apply'];
                continue;
            }
            $this->markRunning($migration);
            try {
                foreach ($this->splitSqlStatements($migration['sql']) as $statement) {
                    $this->executeStatement($statement);
                }
                $this->markApplied($migration['version']);
                $results[] = ['version' => $migration['version'], 'status' => 'applied'];
            } catch (Throwable $error) {
                $this->markFailed($migration['version'], $error->getMessage());
                throw new RuntimeException('Migration failed at ' . $migration['version'] . ': ' . $error->getMessage(), 0, $error);
            }
        }

        $after = $this->snapshot();
        return [
            'migrations' => $results,
            'structure_diff' => $this->structureDiff($before, $after),
            'count_diff' => $this->countDiff($before['counts'], $after['counts']),
            'verification' => $dryRun ? null : $this->verify(),
        ];
    }

    public function verify(): array {
        $this->ensureHistoryTable();
        $rows = $this->migrationRows();
        $snapshot = $this->snapshot();
        $errors = [];

        foreach ($this->migrationFiles() as $migration) {
            $row = $rows[$migration['version']] ?? null;
            if (!$row || $row['status'] !== 'applied') {
                $errors[] = ['version' => $migration['version'], 'type' => 'migration_not_applied'];
                continue;
            }
            if (!hash_equals((string)$row['checksum'], $migration['checksum'])) {
                $errors[] = ['version' => $migration['version'], 'type' => 'checksum_mismatch'];
            }
            $expectation = $this->manifest[$migration['version']] ?? [];
            foreach ($expectation['tables'] ?? [] as $table) {
                if (!isset($snapshot['tables'][$table])) {
                    $errors[] = ['version' => $migration['version'], 'type' => 'missing_table', 'target' => $table];
                }
            }
            foreach ($expectation['columns'] ?? [] as $table => $columns) {
                foreach ($columns as $column) {
                    if (!isset($snapshot['columns'][$table][$column])) {
                        $errors[] = ['version' => $migration['version'], 'type' => 'missing_column', 'target' => $table . '.' . $column];
                    }
                }
            }
            foreach ($expectation['indexes'] ?? [] as $table => $indexes) {
                foreach ($indexes as $index) {
                    $alternatives = is_array($index) ? $index : [$index];
                    $matched = array_filter(
                        $alternatives,
                        static fn(string $name): bool => isset($snapshot['indexes'][$table][$name])
                    );
                    if ($matched === []) {
                        $errors[] = [
                            'version' => $migration['version'],
                            'type' => 'missing_index',
                            'target' => $table . '.' . implode('|', $alternatives),
                        ];
                    }
                }
            }
        }

        return ['ok' => $errors === [], 'errors' => $errors, 'counts' => $snapshot['counts']];
    }

    public function rollbackPlan(): array {
        $tables = [];
        foreach ($this->manifest as $version => $expectation) {
            foreach ($expectation['tables'] ?? [] as $table) {
                $tables[] = ['version' => $version, 'table' => $table, 'action' => 'retain_and_disable_reads'];
            }
        }
        return [
            'strategy' => 'preserving',
            'steps' => [
                'Disable new API entry points and workers.',
                'Restore the previous application files.',
                'Switch reads to the previous interfaces.',
                'Retain additive tables and columns for audit and recovery.',
                'Restore data from the recorded backup when data reversal is required.',
            ],
            'retained_tables' => $tables,
        ];
    }

    public function splitSqlStatements(string $sql): array {
        $statements = [];
        $buffer = '';
        $quote = null;
        $escaped = false;
        $lineComment = false;
        $blockComment = false;
        $length = strlen($sql);

        for ($index = 0; $index < $length; $index++) {
            $char = $sql[$index];
            $next = $index + 1 < $length ? $sql[$index + 1] : '';
            if ($lineComment) {
                if ($char === "\n") {
                    $lineComment = false;
                    $buffer .= $char;
                }
                continue;
            }
            if ($blockComment) {
                if ($char === '*' && $next === '/') {
                    $blockComment = false;
                    $index++;
                }
                continue;
            }
            if ($quote === null && $char === '-' && $next === '-' && ($index + 2 >= $length || ctype_space($sql[$index + 2]))) {
                $lineComment = true;
                $index++;
                continue;
            }
            if ($quote === null && $char === '/' && $next === '*') {
                $blockComment = true;
                $index++;
                continue;
            }
            $buffer .= $char;
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($char === '\\' && $quote !== null) {
                $escaped = true;
                continue;
            }
            if ($quote !== null) {
                if ($char === $quote) {
                    if ($next === $quote) {
                        $buffer .= $next;
                        $index++;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                continue;
            }
            if ($char === ';') {
                $statement = trim(substr($buffer, 0, -1));
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
            }
        }
        $tail = trim($buffer);
        if ($tail !== '') {
            $statements[] = $tail;
        }
        return $statements;
    }

    private function executeStatement(string $sql): void {
        $statement = $this->db->prepare($sql);
        $statement->execute();
        if ($statement->columnCount() > 0) {
            $statement->fetchAll(PDO::FETCH_NUM);
        }
        $statement->closeCursor();
    }

    private function migrationFiles(): array {
        $paths = glob($this->migrationDirectory . '/*.sql') ?: [];
        sort($paths, SORT_STRING);
        $migrations = [];
        foreach ($paths as $path) {
            $name = basename($path);
            if (!preg_match('/^(\d{12})_(.+)\.sql$/', $name, $matches)) {
                continue;
            }
            $sql = (string)file_get_contents($path);
            $migrations[] = [
                'version' => $matches[1],
                'name' => $name,
                'path' => $path,
                'sql' => $sql,
                'checksum' => hash('sha256', $sql),
            ];
        }
        return $migrations;
    }

    private function migrationRows(): array {
        $rows = $this->db->query('SELECT version, migration_name, checksum, status, error_message, started_at, applied_at FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[(string)$row['version']] = $row;
        }
        return $result;
    }

    private function markRunning(array $migration): void {
        $stmt = $this->db->prepare("INSERT INTO schema_migrations (version, migration_name, checksum, status, error_message, started_at, applied_at)
            VALUES (?, ?, ?, 'running', NULL, NOW(), NULL)
            ON DUPLICATE KEY UPDATE migration_name = VALUES(migration_name), checksum = VALUES(checksum), status = 'running', error_message = NULL, started_at = NOW(), applied_at = NULL");
        $stmt->execute([$migration['version'], $migration['name'], $migration['checksum']]);
    }

    private function markApplied(string $version): void {
        $stmt = $this->db->prepare("UPDATE schema_migrations SET status = 'applied', error_message = NULL, applied_at = NOW() WHERE version = ?");
        $stmt->execute([$version]);
    }

    private function markFailed(string $version, string $message): void {
        $stmt = $this->db->prepare("UPDATE schema_migrations SET status = 'failed', error_message = ?, applied_at = NULL WHERE version = ?");
        $stmt->execute([mb_substr($message, 0, 500), $version]);
    }

    private function snapshot(): array {
        $tables = [];
        $columns = [];
        $indexes = [];
        $counts = [];
        $tableRows = $this->db->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tableRows as $table) {
            $table = (string)$table;
            $tables[$table] = true;
            $quotedTable = '`' . str_replace('`', '``', $table) . '`';
            $counts[$table] = (int)$this->db->query('SELECT COUNT(*) FROM ' . $quotedTable)->fetchColumn();
        }
        $columnRows = $this->db->query('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columnRows as $row) {
            $columns[$row['TABLE_NAME']][$row['COLUMN_NAME']] = $row;
        }
        $indexRows = $this->db->query('SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($indexRows as $row) {
            $indexes[$row['TABLE_NAME']][$row['INDEX_NAME']][] = $row['COLUMN_NAME'];
        }
        return ['tables' => $tables, 'columns' => $columns, 'indexes' => $indexes, 'counts' => $counts];
    }

    private function structureDiff(array $before, array $after): array {
        $addedTables = array_values(array_diff(array_keys($after['tables']), array_keys($before['tables'])));
        $addedColumns = [];
        $addedIndexes = [];
        foreach ($after['columns'] as $table => $columns) {
            foreach (array_diff(array_keys($columns), array_keys($before['columns'][$table] ?? [])) as $column) {
                $addedColumns[] = $table . '.' . $column;
            }
        }
        foreach ($after['indexes'] as $table => $indexes) {
            foreach (array_diff(array_keys($indexes), array_keys($before['indexes'][$table] ?? [])) as $index) {
                $addedIndexes[] = $table . '.' . $index;
            }
        }
        return ['added_tables' => $addedTables, 'added_columns' => $addedColumns, 'added_indexes' => $addedIndexes];
    }

    private function countDiff(array $before, array $after): array {
        $diff = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $table) {
            $beforeCount = $before[$table] ?? 0;
            $afterCount = $after[$table] ?? 0;
            if ($beforeCount !== $afterCount) {
                $diff[$table] = ['before' => $beforeCount, 'after' => $afterCount, 'difference' => $afterCount - $beforeCount];
            }
        }
        return $diff;
    }
}
