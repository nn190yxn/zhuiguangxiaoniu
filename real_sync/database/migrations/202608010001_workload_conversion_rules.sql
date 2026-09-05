-- Versioned workload conversion rules and immutable report result snapshots.
-- Raw daily report values remain unchanged; conversion results are additive.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS workload_conversion_rule_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    version_code VARCHAR(64) NOT NULL,
    role_code VARCHAR(32) NOT NULL,
    source_role_rule_version_id BIGINT UNSIGNED NULL,
    effective_from DATE NOT NULL,
    effective_to DATE NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'draft',
    description VARCHAR(500) NOT NULL DEFAULT '',
    created_by_staff_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_workload_conversion_rule_versions_code (version_code),
    KEY idx_workload_conversion_rule_versions_effective (role_code, effective_from, effective_to, status),
    KEY idx_workload_conversion_rule_versions_source (source_role_rule_version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS workload_conversion_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rule_version_id BIGINT UNSIGNED NOT NULL,
    rule_code VARCHAR(64) NOT NULL,
    metric_codes_json LONGTEXT NOT NULL,
    conversion_mode VARCHAR(24) NOT NULL,
    threshold_value DECIMAL(18,2) NULL,
    points_per_match DECIMAL(18,2) NULL,
    daily_cap_points DECIMAL(18,2) NULL,
    tiers_json LONGTEXT NULL,
    evidence_types_json LONGTEXT NULL,
    requires_all_metrics TINYINT(1) NOT NULL DEFAULT 0,
    is_required_check TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT chk_workload_conversion_mode CHECK (conversion_mode IN ('threshold', 'step', 'tier', 'composite', 'required_check')),
    UNIQUE KEY uq_workload_conversion_rule (rule_version_id, rule_code),
    KEY idx_workload_conversion_rules_mode (conversion_mode, is_required_check),
    KEY idx_workload_conversion_rules_version (rule_version_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS workload_report_conversion_results (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    report_id BIGINT UNSIGNED NOT NULL,
    conversion_rule_id BIGINT UNSIGNED NOT NULL,
    rule_snapshot_json LONGTEXT NOT NULL,
    raw_value DECIMAL(20,4) NOT NULL DEFAULT 0,
    pending_points DECIMAL(20,4) NOT NULL DEFAULT 0,
    effective_points DECIMAL(20,4) NOT NULL DEFAULT 0,
    rejected_points DECIMAL(20,4) NOT NULL DEFAULT 0,
    completion_state VARCHAR(24) NOT NULL DEFAULT 'not_met',
    explanation VARCHAR(1000) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_workload_report_conversion_result (report_id, conversion_rule_id),
    KEY idx_workload_report_conversion_results_report (report_id, completion_state),
    KEY idx_workload_report_conversion_results_rule (conversion_rule_id, report_id),
    KEY idx_workload_report_conversion_results_effective (effective_points, pending_points)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
