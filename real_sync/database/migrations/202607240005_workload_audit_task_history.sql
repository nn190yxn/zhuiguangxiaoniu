-- Preserve workload audit task history across report resubmissions.

SET NAMES utf8mb4;

SET @migration_sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workload_audit_tasks' AND COLUMN_NAME = 'task_version'
    ),
    'SELECT 1',
    'ALTER TABLE workload_audit_tasks ADD COLUMN task_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER metric_code'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workload_audit_tasks' AND COLUMN_NAME = 'previous_task_id'
    ),
    'SELECT 1',
    'ALTER TABLE workload_audit_tasks ADD COLUMN previous_task_id BIGINT UNSIGNED NULL AFTER task_version'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workload_audit_tasks' AND COLUMN_NAME = 'superseded_at'
    ),
    'SELECT 1',
    'ALTER TABLE workload_audit_tasks ADD COLUMN superseded_at DATETIME NULL AFTER audited_at'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workload_audit_tasks' AND INDEX_NAME = 'idx_workload_audit_version_history'
    ),
    'SELECT 1',
    'ALTER TABLE workload_audit_tasks ADD KEY idx_workload_audit_version_history (report_id, metric_code, task_version, id)'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workload_audit_tasks' AND INDEX_NAME = 'idx_workload_audit_previous_task'
    ),
    'SELECT 1',
    'ALTER TABLE workload_audit_tasks ADD KEY idx_workload_audit_previous_task (previous_task_id)'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workload_audit_tasks' AND INDEX_NAME = 'idx_workload_audit_current_backlog'
    ),
    'SELECT 1',
    'ALTER TABLE workload_audit_tasks ADD KEY idx_workload_audit_current_backlog (audit_status, superseded_at, store_id, created_at)'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;
