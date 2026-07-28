UPDATE metric_definitions
SET max_value = 2,
    updated_at = NOW()
WHERE role_code = 'coach'
  AND metric_code = 'coach_body_test'
  AND (max_value IS NULL OR max_value <> 2);

UPDATE workload_role_metric_rules rule_item
JOIN workload_role_rule_versions rule_version
    ON rule_version.id = rule_item.rule_version_id
SET rule_item.max_value = 2,
    rule_item.updated_at = NOW()
WHERE rule_version.role_code = 'coach'
  AND rule_item.metric_code = 'coach_body_test'
  AND (rule_item.max_value IS NULL OR rule_item.max_value <> 2);
