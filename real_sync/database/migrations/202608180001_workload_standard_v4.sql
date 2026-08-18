-- Publish workload standard v4.0 from 2026-08-18 and preserve historical rule versions.

SET NAMES utf8mb4;

UPDATE workload_role_rule_versions
SET effective_to = '2026-08-17', updated_at = CURRENT_TIMESTAMP
WHERE role_code IN ('sales', 'coach', 'manager', 'teaching_supervisor', 'supervisor')
  AND status IN ('active', 'scheduled')
  AND effective_from < '2026-08-18'
  AND (effective_to IS NULL OR effective_to >= '2026-08-18');

UPDATE workload_conversion_rule_versions
SET effective_to = '2026-08-17', updated_at = CURRENT_TIMESTAMP
WHERE role_code IN ('sales', 'coach', 'manager', 'teaching_supervisor', 'supervisor')
  AND status IN ('active', 'scheduled')
  AND effective_from < '2026-08-18'
  AND (effective_to IS NULL OR effective_to >= '2026-08-18');

UPDATE metric_definitions
SET metric_name = '有效电话邀约', unit = '通', value_type = 'integer', min_value = 0,
    description = '有效电话邀约，当天达到 30 通折算 1 点工作量。', is_active = 1
WHERE metric_code = 'sales_calls' AND role_code = 'sales';

INSERT INTO workload_templates (template_code, template_name, role_code, is_active, version_no, minimum_positive_metrics, effective_from)
VALUES
    ('workload-v4-20260818-coach', '工作量标准 v4.0 教练日报', 'coach', 1, 5, 4, '2026-08-18'),
    ('workload-v4-20260818-sales', '工作量标准 v4.0 销售顾问日报', 'sales', 1, 5, 4, '2026-08-18'),
    ('workload-v4-20260818-manager', '工作量标准 v4.0 店长日报', 'manager', 1, 5, 6, '2026-08-18'),
    ('workload-v4-20260818-teaching-supervisor', '工作量标准 v4.0 教学主管日报', 'teaching_supervisor', 1, 5, 2, '2026-08-18'),
    ('workload-v4-20260818-supervisor', '工作量标准 v4.0 督导日报', 'supervisor', 1, 5, 6, '2026-08-18')
ON DUPLICATE KEY UPDATE
    template_name = VALUES(template_name), role_code = VALUES(role_code), is_active = 1,
    version_no = VALUES(version_no), minimum_positive_metrics = VALUES(minimum_positive_metrics),
    effective_from = VALUES(effective_from);

INSERT IGNORE INTO workload_template_items (template_id, metric_id, is_visible, is_editable, sort_order)
SELECT template.id, metric.id, 1, 1, metric.sort_order
FROM workload_templates template
JOIN metric_definitions metric ON metric.role_code = template.role_code AND metric.metric_code IN (
    'coach_trial_delivery', 'coach_body_test', 'coach_motion_plan', 'coach_renew_count',
    'coach_referral', 'coach_review_ugc', 'coach_live_ground', 'coach_moments'
)
WHERE template.template_code = 'workload-v4-20260818-coach';

INSERT IGNORE INTO workload_template_items (template_id, metric_id, is_visible, is_editable, sort_order)
SELECT template.id, metric.id, 1, 1, metric.sort_order
FROM workload_templates template
JOIN metric_definitions metric ON metric.role_code = template.role_code AND metric.metric_code IN (
    'sales_actual_visit', 'sales_second_visit', 'sales_calls', 'sales_deal_amount',
    'sales_referral', 'sales_live_ground', 'sales_moments'
)
WHERE template.template_code = 'workload-v4-20260818-sales';

INSERT IGNORE INTO workload_template_items (template_id, metric_id, is_visible, is_editable, sort_order)
SELECT template.id, metric.id, 1, 1, metric.sort_order
FROM workload_templates template
JOIN metric_definitions metric ON metric.role_code = template.role_code AND metric.metric_code IN (
    'manager_team_goal_tracking', 'manager_workload_check', 'manager_renewal_list_management',
    'manager_blue_v_post', 'manager_standup_review', 'manager_operation_support'
)
WHERE template.template_code = 'workload-v4-20260818-manager';

INSERT IGNORE INTO workload_template_items (template_id, metric_id, is_visible, is_editable, sort_order)
SELECT template.id, metric.id, 1, 1, metric.sort_order
FROM workload_templates template
JOIN metric_definitions metric ON metric.role_code = template.role_code AND metric.metric_code IN (
    'teaching_supervisor_body_plan', 'teaching_supervisor_renewal', 'teaching_supervisor_parent_comm',
    'teaching_supervisor_moments', 'teaching_supervisor_store_operation', 'teaching_supervisor_trial_delivery',
    'teaching_supervisor_referral', 'teaching_supervisor_growth_action', 'teaching_supervisor_coach_mentoring_revised',
    'teaching_supervisor_training_observation', 'teaching_supervisor_research_coaching', 'teaching_supervisor_case_review',
    'teaching_supervisor_lesson_plan_final_review', 'teaching_supervisor_classroom_spot_check'
)
WHERE template.template_code = 'workload-v4-20260818-teaching-supervisor';

INSERT IGNORE INTO workload_template_items (template_id, metric_id, is_visible, is_editable, sort_order)
SELECT template.id, metric.id, 1, 1, metric.sort_order
FROM workload_templates template
JOIN metric_definitions metric ON metric.role_code = template.role_code AND metric.metric_code IN (
    'supervisor_daily_report_submit', 'supervisor_douyin_post_check', 'supervisor_meituan_score_check',
    'supervisor_live_execution_check', 'supervisor_lead_allocation_check', 'supervisor_work_log'
)
WHERE template.template_code = 'workload-v4-20260818-supervisor';

INSERT INTO workload_role_rule_versions (
    version_code, role_code, template_id, minimum_positive_metrics, requires_daily_report,
    effective_from, effective_to, status, description, created_by_staff_id
)
SELECT CONCAT('workload-v4-20260818-', template.role_code), template.role_code, template.id,
       template.minimum_positive_metrics, 1, '2026-08-18', NULL, 'active',
       CONCAT('工作量管理标准 v4.0，', template.template_name), NULL
FROM workload_templates template
WHERE template.template_code IN (
    'workload-v4-20260818-coach', 'workload-v4-20260818-sales', 'workload-v4-20260818-manager',
    'workload-v4-20260818-teaching-supervisor', 'workload-v4-20260818-supervisor'
)
ON DUPLICATE KEY UPDATE
    template_id = VALUES(template_id), minimum_positive_metrics = VALUES(minimum_positive_metrics),
    effective_from = VALUES(effective_from), effective_to = VALUES(effective_to), status = VALUES(status),
    description = VALUES(description);

INSERT INTO workload_role_metric_rules (
    rule_version_id, metric_code, metric_name_snapshot, unit_snapshot, value_type_snapshot,
    is_required, allow_zero, min_value, max_value, need_evidence, min_evidence_count,
    max_evidence_count, audit_mode, statistic_direction, target_value, sort_order
)
SELECT version.id, metric.metric_code, metric.metric_name, metric.unit, metric.value_type,
       CASE WHEN version.role_code IN ('manager', 'teaching_supervisor', 'supervisor') THEN 1 ELSE 0 END,
       1, metric.min_value, metric.max_value, 1, 1, 10, 'full', 'higher',
       CASE
           WHEN metric.metric_code IN ('coach_moments', 'sales_moments') THEN 3
           WHEN metric.metric_code IN ('sales_calls') THEN 30
           WHEN metric.metric_code IN ('sales_deal_amount') THEN 4000
           WHEN metric.metric_code IN ('coach_body_test', 'coach_motion_plan', 'teaching_supervisor_store_operation') THEN 2
           ELSE 1
       END,
       metric.sort_order
FROM workload_role_rule_versions version
JOIN metric_definitions metric ON metric.role_code = version.role_code AND metric.is_active = 1
WHERE version.version_code IN (
    'workload-v4-20260818-coach', 'workload-v4-20260818-sales', 'workload-v4-20260818-manager',
    'workload-v4-20260818-teaching_supervisor', 'workload-v4-20260818-supervisor'
)
AND metric.metric_code IN (
    'coach_trial_delivery', 'coach_body_test', 'coach_motion_plan', 'coach_renew_count', 'coach_referral', 'coach_review_ugc', 'coach_live_ground', 'coach_moments',
    'sales_actual_visit', 'sales_second_visit', 'sales_calls', 'sales_deal_amount', 'sales_referral', 'sales_live_ground', 'sales_moments',
    'manager_team_goal_tracking', 'manager_workload_check', 'manager_renewal_list_management', 'manager_blue_v_post', 'manager_standup_review', 'manager_operation_support',
    'teaching_supervisor_body_plan', 'teaching_supervisor_renewal', 'teaching_supervisor_parent_comm', 'teaching_supervisor_moments', 'teaching_supervisor_store_operation', 'teaching_supervisor_trial_delivery', 'teaching_supervisor_referral', 'teaching_supervisor_growth_action', 'teaching_supervisor_coach_mentoring_revised', 'teaching_supervisor_training_observation', 'teaching_supervisor_research_coaching', 'teaching_supervisor_case_review', 'teaching_supervisor_lesson_plan_final_review', 'teaching_supervisor_classroom_spot_check',
    'supervisor_daily_report_submit', 'supervisor_douyin_post_check', 'supervisor_meituan_score_check', 'supervisor_live_execution_check', 'supervisor_lead_allocation_check', 'supervisor_work_log'
)
ON DUPLICATE KEY UPDATE
    metric_name_snapshot = VALUES(metric_name_snapshot), unit_snapshot = VALUES(unit_snapshot),
    value_type_snapshot = VALUES(value_type_snapshot), is_required = VALUES(is_required), allow_zero = VALUES(allow_zero),
    min_value = VALUES(min_value), max_value = VALUES(max_value), need_evidence = VALUES(need_evidence),
    min_evidence_count = VALUES(min_evidence_count), max_evidence_count = VALUES(max_evidence_count),
    audit_mode = VALUES(audit_mode), statistic_direction = VALUES(statistic_direction), target_value = VALUES(target_value),
    sort_order = VALUES(sort_order);

INSERT INTO workload_conversion_rule_versions (
    version_code, role_code, source_role_rule_version_id, effective_from, effective_to, status, description
)
SELECT CONCAT('workload-v4-20260818-', role_code), role_code, id, '2026-08-18', NULL, 'active', description
FROM workload_role_rule_versions
WHERE version_code IN (
    'workload-v4-20260818-coach', 'workload-v4-20260818-sales', 'workload-v4-20260818-manager',
    'workload-v4-20260818-teaching_supervisor', 'workload-v4-20260818-supervisor'
)
ON DUPLICATE KEY UPDATE
    source_role_rule_version_id = VALUES(source_role_rule_version_id), effective_from = VALUES(effective_from),
    effective_to = VALUES(effective_to), status = VALUES(status), description = VALUES(description);

INSERT INTO workload_conversion_rules (
    rule_version_id, rule_code, metric_codes_json, conversion_mode, threshold_value, points_per_match,
    daily_cap_points, tiers_json, evidence_types_json, requires_all_metrics, is_required_check
)
SELECT version.id, rules.rule_code, rules.metric_codes_json, rules.conversion_mode, rules.threshold_value,
       rules.points_per_match, rules.daily_cap_points, rules.tiers_json, '["image","screenshot"]', 0, 0
FROM workload_conversion_rule_versions version
JOIN workload_role_rule_versions role_version ON role_version.id = version.source_role_rule_version_id
JOIN (
    SELECT 'coach' role_code, 'coach-trial-delivery' rule_code, '["coach_trial_delivery"]' metric_codes_json, 'threshold' conversion_mode, 1 threshold_value, 1 points_per_match, NULL daily_cap_points, NULL tiers_json
    UNION ALL SELECT 'coach', 'coach-body-plan', '["coach_body_test","coach_motion_plan"]', 'composite', 1, 1, 2, NULL
    UNION ALL SELECT 'coach', 'coach-renewal', '["coach_renew_count"]', 'threshold', 1, 1, NULL, NULL
    UNION ALL SELECT 'coach', 'coach-referral', '["coach_referral"]', 'threshold', 1, 1, NULL, NULL
    UNION ALL SELECT 'coach', 'coach-growth-action', '["coach_review_ugc","coach_live_ground"]', 'threshold', 1, 1, NULL, NULL
    UNION ALL SELECT 'coach', 'coach-moments', '["coach_moments"]', 'step', 3, 1, NULL, NULL
    UNION ALL SELECT 'sales', 'sales-visit', '["sales_actual_visit"]', 'threshold', 1, 1, NULL, NULL
    UNION ALL SELECT 'sales', 'sales-second-visit', '["sales_second_visit"]', 'threshold', 1, 1, NULL, NULL
    UNION ALL SELECT 'sales', 'sales-calls', '["sales_calls"]', 'threshold', 30, 1, NULL, NULL
    UNION ALL SELECT 'sales', 'sales-deal-amount-tier', '["sales_deal_amount"]', 'tier', NULL, NULL, 2, '[{"min":0.01,"max":3999.99,"points":1,"priority":1},{"min":4000,"points":2,"priority":2}]'
    UNION ALL SELECT 'sales', 'sales-referral', '["sales_referral"]', 'threshold', 1, 1, NULL, NULL
    UNION ALL SELECT 'sales', 'sales-live-ground', '["sales_live_ground"]', 'threshold', 1, 1, NULL, NULL
    UNION ALL SELECT 'sales', 'sales-moments', '["sales_moments"]', 'step', 3, 1, NULL, NULL
) rules ON rules.role_code = role_version.role_code
WHERE version.version_code IN ('workload-v4-20260818-coach', 'workload-v4-20260818-sales')
ON DUPLICATE KEY UPDATE
    metric_codes_json = VALUES(metric_codes_json), conversion_mode = VALUES(conversion_mode), threshold_value = VALUES(threshold_value),
    points_per_match = VALUES(points_per_match), daily_cap_points = VALUES(daily_cap_points), tiers_json = VALUES(tiers_json),
    evidence_types_json = VALUES(evidence_types_json), requires_all_metrics = VALUES(requires_all_metrics), is_required_check = VALUES(is_required_check);

INSERT INTO workload_conversion_rules (
    rule_version_id, rule_code, metric_codes_json, conversion_mode, evidence_types_json, requires_all_metrics, is_required_check
)
SELECT version.id, metric.metric_code, CONCAT('["', metric.metric_code, '"]'), 'required_check', '["image","screenshot"]', 0, 1
FROM workload_conversion_rule_versions version
JOIN workload_role_rule_versions role_version ON role_version.id = version.source_role_rule_version_id
JOIN workload_role_metric_rules metric ON metric.rule_version_id = role_version.id
WHERE version.version_code IN ('workload-v4-20260818-manager', 'workload-v4-20260818-teaching_supervisor', 'workload-v4-20260818-supervisor')
ON DUPLICATE KEY UPDATE
    metric_codes_json = VALUES(metric_codes_json), conversion_mode = VALUES(conversion_mode), evidence_types_json = VALUES(evidence_types_json),
    requires_all_metrics = VALUES(requires_all_metrics), is_required_check = VALUES(is_required_check);
