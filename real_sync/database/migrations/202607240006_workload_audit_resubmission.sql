-- Track the evidence baseline used by needs-resubmit audit decisions.

SET NAMES utf8mb4;

SET @migration_sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'workload_audit_tasks'
          AND COLUMN_NAME = 'evidence_count_at_review'
    ),
    'SELECT 1',
    'ALTER TABLE workload_audit_tasks ADD COLUMN evidence_count_at_review INT UNSIGNED NULL AFTER audited_at'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;
