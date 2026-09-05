-- V4.0 draft conversion rules. They remain inactive until an administrator publishes them.

SET NAMES utf8mb4;

INSERT INTO metric_definitions (metric_code, metric_name, role_code, metric_group, metric_category, unit, value_type, is_required, is_system_calculated, default_value, min_value, sort_order, description)
VALUES
    ('teaching_supervisor_class_inspection', '课程巡检', 'teaching_supervisor', 'daily_input', 'process', '次', 'integer', 0, 0, 0, 0, 10, '完成一次课程巡检'),
    ('teaching_supervisor_lesson_plan_review', '教案审核', 'teaching_supervisor', 'daily_input', 'process', '份', 'integer', 0, 0, 0, 0, 20, '完成一份教案审核'),
    ('teaching_supervisor_coach_mentoring', '教练带教', 'teaching_supervisor', 'daily_input', 'process', '次', 'integer', 0, 0, 0, 0, 30, '完成一次教练带教'),
    ('teaching_supervisor_research', '教研组织', 'teaching_supervisor', 'daily_input', 'process', '次', 'integer', 0, 0, 0, 0, 40, '完成一次教研组织'),
    ('teaching_supervisor_parent_feedback', '家长反馈复核', 'teaching_supervisor', 'daily_input', 'process', '次', 'integer', 0, 0, 0, 0, 50, '完成一次家长反馈复核'),
    ('teaching_supervisor_issue_closed', '教学问题闭环', 'teaching_supervisor', 'daily_input', 'result', '项', 'integer', 0, 0, 0, 0, 60, '完成一个教学问题闭环'),
    ('supervisor_store_inspection', '巡店检查', 'supervisor', 'daily_input', 'process', '次', 'integer', 0, 0, 0, 0, 10, '完成一次巡店检查'),
    ('supervisor_data_review', '数据复盘', 'supervisor', 'daily_input', 'process', '次', 'integer', 0, 0, 0, 0, 20, '完成一次经营数据复盘'),
    ('supervisor_manager_mentoring', '店长带教', 'supervisor', 'daily_input', 'process', '次', 'integer', 0, 0, 0, 0, 30, '完成一次店长带教'),
    ('supervisor_rectification_acceptance', '整改验收', 'supervisor', 'daily_input', 'result', '项', 'integer', 0, 0, 0, 0, 40, '完成一项整改验收'),
    ('supervisor_staff_training', '员工培训', 'supervisor', 'daily_input', 'process', '次', 'integer', 0, 0, 0, 0, 50, '完成一次员工培训'),
    ('supervisor_cross_store_support', '跨店支持', 'supervisor', 'daily_input', 'process', '次', 'integer', 0, 0, 0, 0, 60, '完成一次跨店支持')
ON DUPLICATE KEY UPDATE metric_name = VALUES(metric_name), role_code = VALUES(role_code), metric_group = VALUES(metric_group), metric_category = VALUES(metric_category), unit = VALUES(unit), value_type = VALUES(value_type), sort_order = VALUES(sort_order), description = VALUES(description), is_active = 1;

INSERT INTO workload_templates (template_code, template_name, role_code, is_active, version_no, minimum_positive_metrics, effective_from)
VALUES
    ('teaching_supervisor_daily_v4', '教学主管日报模板 V4', 'teaching_supervisor', 1, 4, 4, '2026-08-01'),
    ('supervisor_daily_v4', '督导日报模板 V4', 'supervisor', 1, 4, 4, '2026-08-01')
ON DUPLICATE KEY UPDATE template_name = VALUES(template_name), minimum_positive_metrics = VALUES(minimum_positive_metrics), effective_from = VALUES(effective_from), is_active = 1;

INSERT IGNORE INTO workload_template_items (template_id, metric_id, is_visible, is_editable, sort_order)
SELECT template.id, metric.id, 1, 1, metric.sort_order
FROM workload_templates template
JOIN metric_definitions metric ON metric.role_code = template.role_code AND metric.metric_group = 'daily_input'
WHERE template.template_code IN ('teaching_supervisor_daily_v4', 'supervisor_daily_v4');

INSERT INTO workload_role_rule_versions (version_code, role_code, template_id, minimum_positive_metrics, requires_daily_report, effective_from, effective_to, status, description, created_by_staff_id)
SELECT CONCAT(template.role_code, '-v4-draft'), template.role_code, template.id, 4, 1, '2026-08-01', NULL, 'draft', 'V4.0 动作池草稿，发布前仅用于差异预览。', NULL
FROM workload_templates template
WHERE template.template_code IN ('teaching_supervisor_daily_v4', 'supervisor_daily_v4')
ON DUPLICATE KEY UPDATE template_id = VALUES(template_id), minimum_positive_metrics = VALUES(minimum_positive_metrics), description = VALUES(description);

INSERT INTO workload_conversion_rule_versions (version_code, role_code, source_role_rule_version_id, effective_from, effective_to, status, description, created_by_staff_id)
VALUES
    ('sales-v4-draft', 'sales', NULL, '2026-08-01', NULL, 'draft', 'V4.0 销售动作池草稿：任意 4 个有效点达成。', NULL),
    ('coach-v4-draft', 'coach', NULL, '2026-08-01', NULL, 'draft', 'V4.0 教练动作池草稿：任意 4 个有效点达成。', NULL),
    ('manager-v4-draft', 'manager', NULL, '2026-08-01', NULL, 'draft', 'V4.0 店长动作池草稿：任意 4 个有效点达成。', NULL),
    ('teaching-supervisor-v4-draft', 'teaching_supervisor', NULL, '2026-08-01', NULL, 'draft', 'V4.0 教学主管动作池草稿：任意 4 个有效点达成。', NULL),
    ('supervisor-v4-draft', 'supervisor', NULL, '2026-08-01', NULL, 'draft', 'V4.0 督导动作池草稿：任意 4 个有效点达成。', NULL)
ON DUPLICATE KEY UPDATE effective_from = VALUES(effective_from), effective_to = VALUES(effective_to), status = VALUES(status), description = VALUES(description);

INSERT INTO workload_conversion_rules (rule_version_id, rule_code, metric_codes_json, conversion_mode, threshold_value, points_per_match, daily_cap_points, tiers_json, evidence_types_json, requires_all_metrics, is_required_check)
SELECT version.id, seed.rule_code, seed.metric_codes_json, seed.conversion_mode, seed.threshold_value, seed.points_per_match, seed.daily_cap_points, seed.tiers_json, seed.evidence_types_json, seed.requires_all_metrics, 0
FROM workload_conversion_rule_versions version
JOIN (
    SELECT 'sales' AS role_code, 'sales-visit' AS rule_code, '["sales_actual_visit"]' AS metric_codes_json, 'threshold' AS conversion_mode, 1 AS threshold_value, 1 AS points_per_match, NULL AS daily_cap_points, NULL AS tiers_json, '["image","screenshot"]' AS evidence_types_json, 0 AS requires_all_metrics
    UNION ALL SELECT 'sales', 'sales-calls', '["sales_calls"]', 'step', 30, 1, 1, NULL, '["image","screenshot"]', 0
    UNION ALL SELECT 'sales', 'sales-deal-amount', '["sales_new_revenue"]', 'step', 4000, 1, NULL, NULL, '["image","screenshot"]', 0
    UNION ALL SELECT 'sales', 'sales-moments', '["sales_moments"]', 'step', 3, 1, 1, NULL, '["image","screenshot"]', 0
    UNION ALL SELECT 'sales', 'sales-store-poi', '["sales_store_poi_checkin"]', 'step', 3, 1, NULL, NULL, '["image","screenshot"]', 0
    UNION ALL SELECT 'coach', 'coach-body-plan', '["coach_body_test","coach_motion_plan"]', 'composite', 1, 1, 2, NULL, '["image","screenshot"]', 1
    UNION ALL SELECT 'coach', 'coach-renewal', '["coach_renew_count"]', 'step', 1, 1, NULL, NULL, '["image","screenshot"]', 0
    UNION ALL SELECT 'coach', 'coach-parent-communication', '["coach_parent_comm"]', 'step', 1, 1, NULL, NULL, '["image","screenshot"]', 0
    UNION ALL SELECT 'coach', 'coach-moments', '["coach_moments"]', 'step', 3, 1, NULL, NULL, '["image","screenshot"]', 0
    UNION ALL SELECT 'coach', 'coach-store-poi', '["coach_store_poi_checkin"]', 'step', 3, 1, NULL, NULL, '["image","screenshot"]', 0
    UNION ALL SELECT 'manager', 'manager-poi', '["manager_store_poi_checkin"]', 'threshold', 5, 1, NULL, NULL, '["image","screenshot","manager_confirmation"]', 0
    UNION ALL SELECT 'manager', 'manager-favorite', '["manager_store_favorite"]', 'threshold', 5, 1, NULL, NULL, '["image","screenshot","manager_confirmation"]', 0
    UNION ALL SELECT 'manager', 'manager-nine-review', '["manager_nine_image_review"]', 'threshold', 1, 1, NULL, NULL, '["image","screenshot","manager_confirmation"]', 0
    UNION ALL SELECT 'manager', 'manager-three-review', '["manager_three_image_review"]', 'threshold', 1, 1, NULL, NULL, '["image","screenshot","manager_confirmation"]', 0
    UNION ALL SELECT 'manager', 'manager-order-count', '["manager_online_order_count"]', 'threshold', 3, 1, NULL, NULL, '["image","screenshot","manager_confirmation"]', 0
    UNION ALL SELECT 'manager', 'manager-order-amount', '["manager_online_order_amount"]', 'threshold', 500, 1, NULL, NULL, '["image","screenshot","manager_confirmation"]', 0
    UNION ALL SELECT 'manager', 'manager-video', '["manager_video_post"]', 'threshold', 1, 1, NULL, NULL, '["image","screenshot","manager_confirmation"]', 0
    UNION ALL SELECT 'teaching_supervisor', 'teaching-class-inspection', '["teaching_supervisor_class_inspection"]', 'threshold', 1, 1, NULL, NULL, '["image","screenshot","manager_confirmation"]', 0
    UNION ALL SELECT 'teaching_supervisor', 'teaching-plan-review', '["teaching_supervisor_lesson_plan_review"]', 'threshold', 1, 1, NULL, NULL, '["image","screenshot","manager_confirmation"]', 0
    UNION ALL SELECT 'teaching_supervisor', 'teaching-coach-mentoring', '["teaching_supervisor_coach_mentoring"]', 'threshold', 1, 1, NULL, NULL, '["image","screenshot","manager_confirmation"]', 0
    UNION ALL SELECT 'teaching_supervisor', 'teaching-research', '["teaching_supervisor_research"]', 'threshold', 1, 1, NULL, NULL, '["image","screenshot","manager_confirmation"]', 0
    UNION ALL SELECT 'teaching_supervisor', 'teaching-parent-feedback', '["teaching_supervisor_parent_feedback"]', 'threshold', 1, 1, NULL, NULL, '["image","screenshot","manager_confirmation"]', 0
    UNION ALL SELECT 'teaching_supervisor', 'teaching-issue-closed', '["teaching_supervisor_issue_closed"]', 'threshold', 1, 1, NULL, NULL, '["image","screenshot","manager_confirmation"]', 0
    UNION ALL SELECT 'supervisor', 'supervisor-store-inspection', '["supervisor_store_inspection"]', 'threshold', 1, 1, NULL, NULL, '["image","screenshot","manager_confirmation"]', 0
    UNION ALL SELECT 'supervisor', 'supervisor-data-review', '["supervisor_data_review"]', 'threshold', 1, 1, NULL, NULL, '["image","screenshot","manager_confirmation"]', 0
    UNION ALL SELECT 'supervisor', 'supervisor-manager-mentoring', '["supervisor_manager_mentoring"]', 'threshold', 1, 1, NULL, NULL, '["image","screenshot","manager_confirmation"]', 0
    UNION ALL SELECT 'supervisor', 'supervisor-rectification', '["supervisor_rectification_acceptance"]', 'threshold', 1, 1, NULL, NULL, '["image","screenshot","manager_confirmation"]', 0
    UNION ALL SELECT 'supervisor', 'supervisor-staff-training', '["supervisor_staff_training"]', 'threshold', 1, 1, NULL, NULL, '["image","screenshot","manager_confirmation"]', 0
    UNION ALL SELECT 'supervisor', 'supervisor-cross-store-support', '["supervisor_cross_store_support"]', 'threshold', 1, 1, NULL, NULL, '["image","screenshot","manager_confirmation"]', 0
) seed ON seed.role_code = version.role_code
WHERE version.version_code IN ('sales-v4-draft', 'coach-v4-draft', 'manager-v4-draft', 'teaching-supervisor-v4-draft', 'supervisor-v4-draft')
ON DUPLICATE KEY UPDATE metric_codes_json = VALUES(metric_codes_json), conversion_mode = VALUES(conversion_mode), threshold_value = VALUES(threshold_value), points_per_match = VALUES(points_per_match), daily_cap_points = VALUES(daily_cap_points), tiers_json = VALUES(tiers_json), evidence_types_json = VALUES(evidence_types_json), requires_all_metrics = VALUES(requires_all_metrics);
