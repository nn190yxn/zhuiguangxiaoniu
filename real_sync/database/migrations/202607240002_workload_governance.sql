-- Workload governance schema foundation.
-- Historical reports remain business facts; this migration only adds governance metadata and indexes.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS workload_source_policies (
    source_code VARCHAR(16) NOT NULL,
    source_kind VARCHAR(16) NOT NULL,
    included_by_default TINYINT(1) NOT NULL DEFAULT 0,
    description VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (source_code),
    KEY idx_workload_source_policies_kind (source_kind, included_by_default)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS workload_metric_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    version_code VARCHAR(32) NOT NULL,
    effective_at DATETIME NOT NULL,
    source_policy_json LONGTEXT NOT NULL,
    obligation_policy_json LONGTEXT NOT NULL,
    effective_value_policy_json LONGTEXT NOT NULL,
    description VARCHAR(500) NOT NULL DEFAULT '',
    created_by_staff_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_workload_metric_versions_code (version_code),
    KEY idx_workload_metric_versions_effective (effective_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS workload_role_rule_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    version_code VARCHAR(64) NOT NULL,
    role_code VARCHAR(32) NOT NULL,
    template_id BIGINT UNSIGNED NULL,
    minimum_positive_metrics INT UNSIGNED NOT NULL DEFAULT 4,
    effective_from DATE NOT NULL,
    effective_to DATE NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'active',
    description VARCHAR(500) NOT NULL DEFAULT '',
    created_by_staff_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_workload_role_rule_versions_code (version_code),
    KEY idx_workload_role_rule_versions_effective (role_code, effective_from, effective_to, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS workload_role_metric_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rule_version_id BIGINT UNSIGNED NOT NULL,
    metric_code VARCHAR(64) NOT NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    allow_zero TINYINT(1) NOT NULL DEFAULT 1,
    min_value DECIMAL(18,2) NULL,
    max_value DECIMAL(18,2) NULL,
    need_evidence TINYINT(1) NOT NULL DEFAULT 0,
    min_evidence_count INT UNSIGNED NOT NULL DEFAULT 0,
    max_evidence_count INT UNSIGNED NOT NULL DEFAULT 10,
    audit_mode VARCHAR(16) NOT NULL DEFAULT 'none',
    statistic_direction VARCHAR(16) NOT NULL DEFAULT 'higher',
    target_value DECIMAL(18,2) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_workload_role_metric_rule (rule_version_id, metric_code),
    KEY idx_workload_role_metric_rules_metric (metric_code, rule_version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS workload_submission_obligations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    obligation_date DATE NOT NULL,
    store_id BIGINT UNSIGNED NOT NULL,
    staff_id BIGINT UNSIGNED NOT NULL,
    role_code VARCHAR(32) NOT NULL,
    required_status VARCHAR(16) NOT NULL DEFAULT 'required',
    reason_code VARCHAR(32) NOT NULL DEFAULT 'scheduled',
    report_id BIGINT UNSIGNED NULL,
    completion_status VARCHAR(24) NOT NULL DEFAULT 'missing',
    deadline_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    source VARCHAR(16) NOT NULL DEFAULT 'generated',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_workload_submission_obligation (obligation_date, store_id, staff_id, role_code),
    KEY idx_workload_obligations_store_status (obligation_date, store_id, completion_status),
    KEY idx_workload_obligations_staff_status (staff_id, obligation_date, completion_status),
    KEY idx_workload_obligations_report (report_id),
    KEY idx_workload_obligations_deadline (completion_status, deadline_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS workload_alert_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rule_code VARCHAR(64) NOT NULL,
    target_role_code VARCHAR(32) NOT NULL,
    metric_type VARCHAR(32) NOT NULL,
    comparison_operator VARCHAR(8) NOT NULL,
    threshold_value DECIMAL(18,4) NOT NULL,
    minimum_report_sample INT UNSIGNED NOT NULL DEFAULT 1,
    minimum_staff_sample INT UNSIGNED NOT NULL DEFAULT 1,
    reminder_time TIME NULL,
    deadline_time TIME NULL,
    severity VARCHAR(16) NOT NULL DEFAULT 'warning',
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    version_no INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_workload_alert_rules_version (rule_code, version_no),
    KEY idx_workload_alert_rules_enabled (enabled, metric_type, target_role_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS workload_alert_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rule_code VARCHAR(64) NOT NULL,
    business_date DATE NOT NULL,
    period_type VARCHAR(16) NOT NULL,
    period_key VARCHAR(64) NOT NULL,
    store_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    staff_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    role_code VARCHAR(32) NOT NULL DEFAULT '',
    metric_code VARCHAR(64) NOT NULL DEFAULT '',
    target_role_code VARCHAR(32) NOT NULL,
    severity VARCHAR(16) NOT NULL DEFAULT 'warning',
    numerator DECIMAL(20,4) NOT NULL DEFAULT 0,
    denominator DECIMAL(20,4) NOT NULL DEFAULT 0,
    current_value DECIMAL(20,4) NOT NULL DEFAULT 0,
    threshold_value DECIMAL(20,4) NOT NULL DEFAULT 0,
    evidence_json LONGTEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'open',
    handled_by_staff_id BIGINT UNSIGNED NULL,
    handler_comment VARCHAR(500) NULL,
    handled_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_workload_alert_event_scope (rule_code, period_key, store_id, staff_id, role_code, metric_code),
    KEY idx_workload_alert_events_status_date (status, business_date, severity),
    KEY idx_workload_alert_events_store_date (store_id, business_date, status),
    KEY idx_workload_alert_events_staff_date (staff_id, business_date, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS workload_export_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_key CHAR(36) NOT NULL,
    export_type VARCHAR(32) NOT NULL,
    requested_by_staff_id BIGINT UNSIGNED NOT NULL,
    filters_json LONGTEXT NOT NULL,
    scope_hash CHAR(64) NOT NULL,
    metric_version_id BIGINT UNSIGNED NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    row_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    file_path VARCHAR(500) NULL,
    expires_at DATETIME NULL,
    error_message VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_workload_export_jobs_key (job_key),
    KEY idx_workload_export_jobs_requester (requested_by_staff_id, created_at),
    KEY idx_workload_export_jobs_status (status, created_at),
    KEY idx_workload_export_jobs_expiry (expires_at, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS workload_report_corrections (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    correction_key CHAR(36) NOT NULL,
    report_id BIGINT UNSIGNED NOT NULL,
    obligation_id BIGINT UNSIGNED NULL,
    before_snapshot_json LONGTEXT NOT NULL,
    after_snapshot_json LONGTEXT NOT NULL,
    correction_reason VARCHAR(500) NOT NULL,
    requested_by_staff_id BIGINT UNSIGNED NULL,
    operated_by_staff_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_workload_report_corrections_key (correction_key),
    KEY idx_workload_report_corrections_report (report_id, created_at),
    KEY idx_workload_report_corrections_obligation (obligation_id, created_at),
    KEY idx_workload_report_corrections_operator (operated_by_staff_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @migration_sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workload_daily_reports' AND COLUMN_NAME = 'metric_version_id'
    ),
    'SELECT 1',
    'ALTER TABLE workload_daily_reports ADD COLUMN metric_version_id BIGINT UNSIGNED NULL AFTER template_id'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workload_daily_reports' AND COLUMN_NAME = 'rule_version_id'
    ),
    'SELECT 1',
    'ALTER TABLE workload_daily_reports ADD COLUMN rule_version_id BIGINT UNSIGNED NULL AFTER metric_version_id'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workload_templates' AND COLUMN_NAME = 'minimum_positive_metrics'
    ),
    'SELECT 1',
    'ALTER TABLE workload_templates ADD COLUMN minimum_positive_metrics INT UNSIGNED NOT NULL DEFAULT 4 AFTER version_no'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workload_templates' AND COLUMN_NAME = 'effective_from'
    ),
    'SELECT 1',
    'ALTER TABLE workload_templates ADD COLUMN effective_from DATE NULL AFTER minimum_positive_metrics'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workload_templates' AND COLUMN_NAME = 'effective_to'
    ),
    'SELECT 1',
    'ALTER TABLE workload_templates ADD COLUMN effective_to DATE NULL AFTER effective_from'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workload_daily_reports' AND INDEX_NAME = 'idx_workload_reports_source_stats'
    ),
    'SELECT 1',
    'ALTER TABLE workload_daily_reports ADD KEY idx_workload_reports_source_stats (report_date, source, submit_status, store_id, role_code)'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workload_daily_reports' AND INDEX_NAME = 'idx_workload_reports_staff_source'
    ),
    'SELECT 1',
    'ALTER TABLE workload_daily_reports ADD KEY idx_workload_reports_staff_source (staff_id, report_date, source, submit_status)'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workload_daily_reports' AND INDEX_NAME = 'idx_workload_reports_versions'
    ),
    'SELECT 1',
    'ALTER TABLE workload_daily_reports ADD KEY idx_workload_reports_versions (metric_version_id, rule_version_id, report_date)'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workload_daily_report_values' AND INDEX_NAME = 'idx_workload_values_metric_report_value'
    ),
    'SELECT 1',
    'ALTER TABLE workload_daily_report_values ADD KEY idx_workload_values_metric_report_value (metric_id, report_id, numeric_value)'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workload_audit_tasks' AND INDEX_NAME = 'idx_workload_audit_backlog'
    ),
    'SELECT 1',
    'ALTER TABLE workload_audit_tasks ADD KEY idx_workload_audit_backlog (audit_status, store_id, role_code, created_at)'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workload_audit_tasks' AND INDEX_NAME = 'idx_workload_audit_report_status'
    ),
    'SELECT 1',
    'ALTER TABLE workload_audit_tasks ADD KEY idx_workload_audit_report_status (report_id, metric_code, audit_status)'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

INSERT INTO workload_source_policies (
    source_code,
    source_kind,
    included_by_default,
    description
)
VALUES
    ('h5', 'production', 1, 'Employee H5 workload entry'),
    ('mini_program', 'production', 1, 'WeChat mini program workload entry'),
    ('codex-smoke', 'synthetic', 0, 'Automated smoke test'),
    ('debug', 'synthetic', 0, 'Debug data'),
    ('h5-e2e', 'synthetic', 0, 'H5 end-to-end test'),
    ('live_check', 'synthetic', 0, 'Live verification data'),
    ('test', 'synthetic', 0, 'Legacy functional test data')
ON DUPLICATE KEY UPDATE
    source_kind = VALUES(source_kind),
    included_by_default = VALUES(included_by_default),
    description = VALUES(description);

INSERT INTO workload_metric_versions (
    version_code,
    effective_at,
    source_policy_json,
    obligation_policy_json,
    effective_value_policy_json,
    description,
    created_by_staff_id
)
VALUES (
    'workload-v1',
    '1970-01-01 00:00:00',
    '{"production":["h5","mini_program"],"synthetic":["codex-smoke","debug","h5-e2e","live_check","test"]}',
    '{"business_days":[2,3,4,5,6,7],"rest_day":1,"deadline":"24:00:00"}',
    '{"pending_audit":"separate","approved":"raw_value","rejected":"zero"}',
    'Initial workload metric definition migrated from the existing system',
    NULL
)
ON DUPLICATE KEY UPDATE
    source_policy_json = VALUES(source_policy_json),
    obligation_policy_json = VALUES(obligation_policy_json),
    effective_value_policy_json = VALUES(effective_value_policy_json),
    description = VALUES(description);

INSERT INTO workload_role_rule_versions (
    version_code,
    role_code,
    template_id,
    minimum_positive_metrics,
    effective_from,
    effective_to,
    status,
    description,
    created_by_staff_id
)
SELECT
    CONCAT('legacy-', LEFT(SHA2(role_source.role_code, 256), 16), '-v1'),
    role_source.role_code,
    (
        SELECT MIN(t.id)
        FROM workload_templates t
        WHERE t.role_code = role_source.role_code
    ),
    4,
    '1970-01-01',
    NULL,
    'active',
    'Initial rule version preserving the four-positive-metric requirement',
    NULL
FROM (
    SELECT role_code FROM workload_templates
    UNION
    SELECT role_code FROM metric_definitions
    UNION
    SELECT role_code FROM workload_daily_reports
) role_source
WHERE role_source.role_code IS NOT NULL
  AND TRIM(role_source.role_code) <> ''
ON DUPLICATE KEY UPDATE
    template_id = VALUES(template_id),
    minimum_positive_metrics = VALUES(minimum_positive_metrics),
    description = VALUES(description);

INSERT INTO workload_role_metric_rules (
    rule_version_id,
    metric_code,
    is_required,
    allow_zero,
    min_value,
    max_value,
    need_evidence,
    min_evidence_count,
    max_evidence_count,
    audit_mode,
    statistic_direction,
    target_value,
    sort_order
)
SELECT
    rule_version.id,
    metric.metric_code,
    metric.is_required,
    1,
    metric.min_value,
    metric.max_value,
    COALESCE(audit_rule.need_evidence, 0),
    CASE
        WHEN COALESCE(audit_rule.need_evidence, 0) = 1 THEN COALESCE(audit_rule.min_evidence_count, 1)
        ELSE 0
    END,
    COALESCE(audit_rule.max_evidence_count, 10),
    COALESCE(audit_rule.audit_mode, 'none'),
    'higher',
    NULL,
    metric.sort_order
FROM metric_definitions metric
JOIN workload_role_rule_versions rule_version
    ON rule_version.role_code = metric.role_code
   AND rule_version.effective_from = '1970-01-01'
LEFT JOIN workload_metric_rules audit_rule
    ON audit_rule.role_code = metric.role_code
   AND audit_rule.metric_code = metric.metric_code
   AND audit_rule.enabled = 1
ON DUPLICATE KEY UPDATE
    is_required = VALUES(is_required),
    min_value = VALUES(min_value),
    max_value = VALUES(max_value),
    need_evidence = VALUES(need_evidence),
    min_evidence_count = VALUES(min_evidence_count),
    max_evidence_count = VALUES(max_evidence_count),
    audit_mode = VALUES(audit_mode),
    sort_order = VALUES(sort_order);

UPDATE workload_templates
SET minimum_positive_metrics = 4,
    effective_from = COALESCE(effective_from, '1970-01-01');

UPDATE workload_daily_reports report
JOIN workload_metric_versions metric_version
    ON metric_version.version_code = 'workload-v1'
SET report.metric_version_id = metric_version.id
WHERE report.metric_version_id IS NULL;

UPDATE workload_daily_reports report
JOIN workload_role_rule_versions rule_version
    ON rule_version.role_code = report.role_code
   AND rule_version.effective_from = '1970-01-01'
SET report.rule_version_id = rule_version.id
WHERE report.rule_version_id IS NULL;

INSERT INTO workload_submission_obligations (
    obligation_date,
    store_id,
    staff_id,
    role_code,
    required_status,
    reason_code,
    report_id,
    completion_status,
    deadline_at,
    completed_at,
    source
)
SELECT
    report.report_date,
    report.store_id,
    report.staff_id,
    report.role_code,
    CASE WHEN DAYOFWEEK(report.report_date) = 2 THEN 'exempt' ELSE 'required' END,
    CASE WHEN DAYOFWEEK(report.report_date) = 2 THEN 'weekly_rest_day' ELSE 'historical_report' END,
    report.id,
    CASE WHEN report.submit_status = 'submitted' THEN 'submitted' ELSE 'draft' END,
    TIMESTAMP(DATE_ADD(report.report_date, INTERVAL 1 DAY)),
    CASE WHEN report.submit_status = 'submitted' THEN COALESCE(report.submitted_at, report.updated_at) ELSE NULL END,
    'backfill'
FROM workload_daily_reports report
ON DUPLICATE KEY UPDATE
    report_id = VALUES(report_id),
    completion_status = VALUES(completion_status),
    completed_at = VALUES(completed_at),
    updated_at = CURRENT_TIMESTAMP;

INSERT INTO workload_alert_rules (
    rule_code,
    target_role_code,
    metric_type,
    comparison_operator,
    threshold_value,
    minimum_report_sample,
    minimum_staff_sample,
    reminder_time,
    deadline_time,
    severity,
    enabled,
    version_no
)
VALUES
    ('draft_submission_reminder', 'staff', 'completion_status', '=', 1, 1, 1, '20:30:00', '24:00:00', 'warning', 1, 1),
    ('locked_missing_notice', 'manager', 'locked_missing_count', '>', 0, 1, 1, NULL, '24:00:00', 'critical', 1, 1),
    ('store_completion_yellow', 'manager', 'completion_rate', '<', 90, 10, 3, NULL, NULL, 'warning', 1, 1),
    ('store_completion_red', 'headquarters', 'completion_rate', '<', 80, 10, 3, NULL, NULL, 'critical', 1, 1),
    ('audit_backlog_yellow', 'headquarters', 'audit_age_hours', '>', 24, 1, 1, NULL, NULL, 'warning', 1, 1),
    ('audit_backlog_red', 'headquarters', 'audit_age_hours', '>', 48, 1, 1, NULL, NULL, 'critical', 1, 1)
ON DUPLICATE KEY UPDATE
    target_role_code = VALUES(target_role_code),
    metric_type = VALUES(metric_type),
    comparison_operator = VALUES(comparison_operator),
    threshold_value = VALUES(threshold_value),
    minimum_report_sample = VALUES(minimum_report_sample),
    minimum_staff_sample = VALUES(minimum_staff_sample),
    reminder_time = VALUES(reminder_time),
    deadline_time = VALUES(deadline_time),
    severity = VALUES(severity),
    enabled = VALUES(enabled);
