-- Version the daily target submission policy and body-test point conversion.

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
    KEY idx_workload_conversion_rule_versions_effective (role_code, effective_from, effective_to, status, id),
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
    UNIQUE KEY uq_workload_conversion_rule (rule_version_id, rule_code),
    KEY idx_workload_conversion_rules_mode (conversion_mode, is_required_check),
    KEY idx_workload_conversion_rules_version (rule_version_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS workload_report_conversion_results (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    report_id BIGINT UNSIGNED NOT NULL,
    conversion_rule_id BIGINT UNSIGNED NOT NULL,
    rule_snapshot_json LONGTEXT NOT NULL,
    raw_value DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    pending_points DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    effective_points DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    rejected_points DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    completion_state VARCHAR(24) NOT NULL DEFAULT 'not_met',
    explanation VARCHAR(500) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_workload_report_conversion_result (report_id, conversion_rule_id),
    KEY idx_workload_report_conversion_results_report (report_id, completion_state),
    KEY idx_workload_report_conversion_results_rule (conversion_rule_id, report_id),
    KEY idx_workload_report_conversion_results_effective (completion_state, effective_points)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

UPDATE workload_role_rule_versions
SET minimum_positive_metrics = 0,
    updated_at = NOW()
WHERE status IN ('active', 'scheduled');

UPDATE workload_templates
SET minimum_positive_metrics = 0,
    updated_at = NOW()
WHERE is_active = 1;

INSERT INTO workload_conversion_rule_versions (
    version_code, role_code, effective_from, effective_to, status, description
) VALUES (
    'workload-daily-points-v1', 'coach', '2026-08-12', NULL, 'draft',
    '每日目标由结算层统一计算；体测每 2 个折算 1 点工作量。'
) ON DUPLICATE KEY UPDATE
    role_code = VALUES(role_code),
    effective_from = VALUES(effective_from),
    effective_to = VALUES(effective_to),
    status = VALUES(status),
    description = VALUES(description);

INSERT INTO workload_conversion_rules (
    rule_version_id, rule_code, metric_codes_json, conversion_mode, threshold_value,
    points_per_match, daily_cap_points, tiers_json, evidence_types_json, requires_all_metrics, is_required_check
)
SELECT version.id, 'coach-body-test-point', '["coach_body_test"]', 'step', 2.00,
       1.00, 1.00, NULL, '["image","screenshot"]', 0, 0
FROM workload_conversion_rule_versions version
WHERE version.version_code = 'workload-daily-points-v1'
ON DUPLICATE KEY UPDATE
    metric_codes_json = VALUES(metric_codes_json),
    conversion_mode = VALUES(conversion_mode),
    threshold_value = VALUES(threshold_value),
    points_per_match = VALUES(points_per_match),
    daily_cap_points = VALUES(daily_cap_points),
    tiers_json = VALUES(tiers_json),
    evidence_types_json = VALUES(evidence_types_json),
    requires_all_metrics = VALUES(requires_all_metrics),
    is_required_check = VALUES(is_required_check);
