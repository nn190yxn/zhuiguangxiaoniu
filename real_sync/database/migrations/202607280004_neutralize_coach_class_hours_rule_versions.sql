UPDATE workload_role_metric_rules rule_item
JOIN workload_role_rule_versions rule_version
    ON rule_version.id = rule_item.rule_version_id
SET rule_item.is_required = 0,
    rule_item.allow_zero = 1,
    rule_item.min_value = 0,
    rule_item.max_value = NULL,
    rule_item.need_evidence = 0,
    rule_item.min_evidence_count = 0,
    rule_item.audit_mode = 'none',
    rule_item.target_value = NULL,
    rule_item.updated_at = NOW()
WHERE rule_version.role_code = 'coach'
  AND rule_item.metric_code IN ('coach_plan_hours', 'coach_actual_hours');
