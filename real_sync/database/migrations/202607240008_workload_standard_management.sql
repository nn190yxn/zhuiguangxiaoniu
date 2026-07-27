-- Workload role standard lifecycle, snapshots, and idempotent administration.

SET NAMES utf8mb4;

ALTER TABLE workload_role_rule_versions MODIFY COLUMN role_code VARCHAR(64) NOT NULL;
ALTER TABLE metric_definitions MODIFY COLUMN role_code VARCHAR(64) NOT NULL;
ALTER TABLE workload_templates MODIFY COLUMN role_code VARCHAR(64) NOT NULL;
ALTER TABLE workload_daily_reports MODIFY COLUMN role_code VARCHAR(64) NOT NULL;
ALTER TABLE workload_submission_obligations MODIFY COLUMN role_code VARCHAR(64) NOT NULL;
ALTER TABLE workload_alert_rules MODIFY COLUMN target_role_code VARCHAR(64) NOT NULL;
ALTER TABLE workload_alert_events MODIFY COLUMN role_code VARCHAR(64) NOT NULL DEFAULT '';
ALTER TABLE workload_alert_events MODIFY COLUMN target_role_code VARCHAR(64) NOT NULL;
ALTER TABLE workload_metric_relations MODIFY COLUMN role_code VARCHAR(64) NOT NULL;

SET @migration_sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workload_role_rule_versions' AND COLUMN_NAME = 'requires_daily_report'
    ),
    'SELECT 1',
    'ALTER TABLE workload_role_rule_versions ADD COLUMN requires_daily_report TINYINT(1) NOT NULL DEFAULT 1 AFTER minimum_positive_metrics'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workload_role_rule_versions' AND COLUMN_NAME = 'source_rule_version_id'
    ),
    'SELECT 1',
    'ALTER TABLE workload_role_rule_versions ADD COLUMN source_rule_version_id BIGINT UNSIGNED NULL AFTER template_id'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workload_role_rule_versions' AND COLUMN_NAME = 'published_by_staff_id'
    ),
    'SELECT 1',
    'ALTER TABLE workload_role_rule_versions ADD COLUMN published_by_staff_id BIGINT UNSIGNED NULL AFTER created_by_staff_id'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workload_role_rule_versions' AND COLUMN_NAME = 'published_at'
    ),
    'SELECT 1',
    'ALTER TABLE workload_role_rule_versions ADD COLUMN published_at DATETIME NULL AFTER published_by_staff_id'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workload_role_metric_rules' AND COLUMN_NAME = 'metric_name_snapshot'
    ),
    'SELECT 1',
    'ALTER TABLE workload_role_metric_rules ADD COLUMN metric_name_snapshot VARCHAR(100) NULL AFTER metric_code'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workload_role_metric_rules' AND COLUMN_NAME = 'unit_snapshot'
    ),
    'SELECT 1',
    'ALTER TABLE workload_role_metric_rules ADD COLUMN unit_snapshot VARCHAR(32) NULL AFTER metric_name_snapshot'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workload_role_metric_rules' AND COLUMN_NAME = 'value_type_snapshot'
    ),
    'SELECT 1',
    'ALTER TABLE workload_role_metric_rules ADD COLUMN value_type_snapshot VARCHAR(20) NULL AFTER unit_snapshot'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

UPDATE workload_role_metric_rules rule
INNER JOIN workload_role_rule_versions version ON version.id = rule.rule_version_id
LEFT JOIN metric_definitions metric
    ON metric.metric_code = rule.metric_code AND metric.role_code = version.role_code
SET rule.metric_name_snapshot = COALESCE(rule.metric_name_snapshot, metric.metric_name, rule.metric_code),
    rule.unit_snapshot = COALESCE(rule.unit_snapshot, metric.unit, ''),
    rule.value_type_snapshot = COALESCE(rule.value_type_snapshot, metric.value_type, 'number')
WHERE rule.metric_name_snapshot IS NULL OR rule.unit_snapshot IS NULL OR rule.value_type_snapshot IS NULL;

CREATE TABLE IF NOT EXISTS workload_standard_idempotency_keys (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    idempotency_key VARCHAR(128) NOT NULL,
    action VARCHAR(40) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    response_json LONGTEXT NULL,
    operator_staff_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_workload_standard_idempotency (idempotency_key, action),
    KEY idx_workload_standard_idempotency_operator (operator_staff_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
