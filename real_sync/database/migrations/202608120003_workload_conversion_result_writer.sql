-- Seed one-point conversion rules from each published role metric target.

SET NAMES utf8mb4;

INSERT INTO workload_conversion_rule_versions (
    version_code, role_code, source_role_rule_version_id, effective_from, effective_to, status, description
)
SELECT
    CONCAT('daily-points-role-rule-', role_version.id),
    role_version.role_code,
    role_version.id,
    role_version.effective_from,
    role_version.effective_to,
    role_version.status,
    '按已发布岗位指标档位换算每日工作量点数。'
FROM workload_role_rule_versions role_version
WHERE role_version.status IN ('active', 'scheduled')
  AND NOT EXISTS (
      SELECT 1
      FROM workload_conversion_rule_versions existing
      WHERE existing.source_role_rule_version_id = role_version.id
  )
ON DUPLICATE KEY UPDATE
    role_code = VALUES(role_code),
    source_role_rule_version_id = VALUES(source_role_rule_version_id),
    effective_from = VALUES(effective_from),
    effective_to = VALUES(effective_to),
    status = VALUES(status),
    description = VALUES(description);

INSERT INTO workload_conversion_rules (
    rule_version_id, rule_code, metric_codes_json, conversion_mode, threshold_value,
    points_per_match, daily_cap_points, tiers_json, evidence_types_json, requires_all_metrics, is_required_check
)
SELECT
    conversion_version.id,
    CONCAT('daily-target-', role_rule.metric_code),
    JSON_ARRAY(role_rule.metric_code),
    CASE WHEN role_rule.metric_code = 'coach_body_test' THEN 'step' ELSE 'threshold' END,
    CASE WHEN role_rule.metric_code = 'coach_body_test' THEN 2.00 ELSE role_rule.target_value END,
    1.00,
    CASE WHEN role_rule.metric_code = 'coach_body_test' THEN 1.00 ELSE NULL END,
    NULL,
    '["image","screenshot"]',
    0,
    0
FROM workload_conversion_rule_versions conversion_version
INNER JOIN workload_role_metric_rules role_rule
    ON role_rule.rule_version_id = conversion_version.source_role_rule_version_id
WHERE conversion_version.source_role_rule_version_id IS NOT NULL
  AND conversion_version.version_code LIKE 'daily-points-role-rule-%'
  AND role_rule.target_value IS NOT NULL
  AND role_rule.target_value > 0
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
