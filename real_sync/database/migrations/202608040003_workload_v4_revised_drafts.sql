-- Revised V4.0 workload conversion drafts based on the confirmed business rules.
-- Existing *-v4-draft versions remain as historical drafts.

SET NAMES utf8mb4;

INSERT INTO metric_definitions (metric_code, metric_name, role_code, metric_group, metric_category, unit, value_type, is_required, is_system_calculated, default_value, min_value, sort_order, description)
VALUES
    ('sales_second_visit', '二访推进', 'sales', 'daily_input', 'process', '次', 'integer', 0, 0, 0, 0, 86, '销售完成一次二访推进'),
    ('sales_referral', '转介绍', 'sales', 'daily_input', 'result', '次', 'integer', 0, 0, 0, 0, 87, '销售获得一次有效转介绍'),
    ('sales_live_ground', '直播地推', 'sales', 'daily_input', 'process', '次', 'integer', 0, 0, 0, 0, 99, '销售完成一次直播或地推动作'),
    ('coach_trial_delivery', '体验课交付', 'coach', 'daily_input', 'process', '次', 'integer', 0, 0, 0, 0, 116, '教练完成一次体验课交付'),
    ('coach_referral', '转介绍', 'coach', 'daily_input', 'result', '次', 'integer', 0, 0, 0, 0, 117, '教练获得一次有效转介绍'),
    ('coach_review_ugc', '好评UGC', 'coach', 'daily_input', 'result', '次', 'integer', 0, 0, 0, 0, 118, '教练完成一次好评或UGC沉淀'),
    ('coach_live_ground', '直播地推', 'coach', 'daily_input', 'process', '次', 'integer', 0, 0, 0, 0, 119, '教练完成一次直播或地推动作'),
    ('coach_store_favorite', '收藏门店', 'coach', 'daily_input', 'behavior', '次', 'integer', 0, 0, 0, 0, 120, '教练完成一次门店收藏动作'),
    ('manager_team_goal_tracking', '团队目标跟踪', 'manager', 'daily_input', 'process', '次', 'integer', 1, 0, 0, 0, 10, '店长每日完成团队目标跟踪'),
    ('manager_workload_check', '工作量核查', 'manager', 'daily_input', 'process', '次', 'integer', 1, 0, 0, 0, 20, '店长每日完成团队工作量核查'),
    ('manager_renewal_list_management', '续费名单管理', 'manager', 'daily_input', 'process', '次', 'integer', 1, 0, 0, 0, 30, '店长每日完成续费名单管理'),
    ('manager_blue_v_post', '蓝V发布', 'manager', 'daily_input', 'process', '条', 'integer', 1, 0, 0, 0, 40, '店长每日完成蓝V内容发布'),
    ('manager_standup_review', '班前会班后会', 'manager', 'daily_input', 'process', '次', 'integer', 1, 0, 0, 0, 50, '店长每日完成班前会或班后会管理'),
    ('manager_operation_support', '运营兜底', 'manager', 'daily_input', 'process', '项', 'integer', 1, 0, 0, 0, 60, '店长每日完成运营兜底事项'),
    ('teaching_supervisor_body_plan', '体测规划', 'teaching_supervisor', 'daily_input', 'process', '次', 'integer', 0, 0, 0, 0, 10, '教学主管完成体测规划类动作'),
    ('teaching_supervisor_renewal', '续费推进', 'teaching_supervisor', 'daily_input', 'result', '次', 'integer', 0, 0, 0, 0, 20, '教学主管完成续费推进动作'),
    ('teaching_supervisor_parent_comm', '家长沟通', 'teaching_supervisor', 'daily_input', 'process', '次', 'integer', 0, 0, 0, 0, 30, '教学主管完成家长重点沟通'),
    ('teaching_supervisor_moments', '朋友圈', 'teaching_supervisor', 'daily_input', 'process', '次', 'integer', 0, 0, 0, 0, 40, '教学主管完成朋友圈动作'),
    ('teaching_supervisor_store_operation', '点亮收藏', 'teaching_supervisor', 'daily_input', 'behavior', '次', 'integer', 0, 0, 0, 0, 50, '教学主管完成门店点亮或收藏动作'),
    ('teaching_supervisor_trial_delivery', '体验课交付', 'teaching_supervisor', 'daily_input', 'process', '次', 'integer', 0, 0, 0, 0, 60, '教学主管完成体验课交付'),
    ('teaching_supervisor_referral', '转介绍', 'teaching_supervisor', 'daily_input', 'result', '次', 'integer', 0, 0, 0, 0, 70, '教学主管获得一次有效转介绍'),
    ('teaching_supervisor_growth_action', '好评UGC直播地推', 'teaching_supervisor', 'daily_input', 'process', '次', 'integer', 0, 0, 0, 0, 80, '教学主管完成好评UGC、直播或地推动作'),
    ('teaching_supervisor_coach_mentoring_revised', '教练带教', 'teaching_supervisor', 'daily_input', 'process', '次', 'integer', 0, 0, 0, 0, 90, '教学主管完成教练带教'),
    ('teaching_supervisor_training_observation', '听评课带教', 'teaching_supervisor', 'daily_input', 'process', '次', 'integer', 0, 0, 0, 0, 100, '教学主管完成听评课带教'),
    ('teaching_supervisor_research_coaching', '教研带教', 'teaching_supervisor', 'daily_input', 'process', '次', 'integer', 0, 0, 0, 0, 110, '教学主管完成教研带教'),
    ('teaching_supervisor_case_review', '案例复盘', 'teaching_supervisor', 'daily_input', 'process', '次', 'integer', 0, 0, 0, 0, 120, '教学主管完成教学案例复盘'),
    ('teaching_supervisor_lesson_plan_final_review', '教案终审', 'teaching_supervisor', 'daily_input', 'process', '份', 'integer', 1, 0, 0, 0, 130, '教学主管每日完成教案终审'),
    ('teaching_supervisor_classroom_spot_check', '课堂抽查', 'teaching_supervisor', 'daily_input', 'process', '次', 'integer', 1, 0, 0, 0, 140, '教学主管每日完成课堂抽查'),
    ('supervisor_daily_report_submit', '日报提交', 'supervisor', 'daily_input', 'process', '次', 'integer', 1, 0, 0, 0, 10, '督导每日提交日报'),
    ('supervisor_douyin_post_check', '抖音发布检查', 'supervisor', 'daily_input', 'process', '次', 'integer', 1, 0, 0, 0, 20, '督导每日检查抖音发布'),
    ('supervisor_meituan_score_check', '美团经营分检查', 'supervisor', 'daily_input', 'process', '次', 'integer', 1, 0, 0, 0, 30, '督导每日检查美团经营分'),
    ('supervisor_live_execution_check', '直播执行检查', 'supervisor', 'daily_input', 'process', '次', 'integer', 1, 0, 0, 0, 40, '督导每日检查直播执行'),
    ('supervisor_lead_allocation_check', '线索分配检查', 'supervisor', 'daily_input', 'process', '次', 'integer', 1, 0, 0, 0, 50, '督导每日检查线索分配'),
    ('supervisor_work_log', '工作日志', 'supervisor', 'daily_input', 'process', '份', 'integer', 1, 0, 0, 0, 60, '督导每日完成工作日志')
ON DUPLICATE KEY UPDATE metric_name = VALUES(metric_name), role_code = VALUES(role_code), metric_group = VALUES(metric_group), metric_category = VALUES(metric_category), unit = VALUES(unit), value_type = VALUES(value_type), is_required = VALUES(is_required), is_system_calculated = VALUES(is_system_calculated), default_value = VALUES(default_value), min_value = VALUES(min_value), sort_order = VALUES(sort_order), description = VALUES(description), is_active = 1;

INSERT INTO workload_templates (template_code, template_name, role_code, is_active, version_no, minimum_positive_metrics, effective_from)
VALUES
    ('sales_daily_v4_revised', '销售日报模板 V4 修正版', 'sales', 1, 4, 4, '2026-08-01'),
    ('coach_daily_v4_revised', '教练日报模板 V4 修正版', 'coach', 1, 4, 4, '2026-08-01'),
    ('manager_daily_v4_revised', '店长日报模板 V4 修正版', 'manager', 1, 4, 6, '2026-08-01'),
    ('teaching_supervisor_daily_v4_revised', '教学主管日报模板 V4 修正版', 'teaching_supervisor', 1, 4, 4, '2026-08-01'),
    ('supervisor_daily_v4_revised', '督导日报模板 V4 修正版', 'supervisor', 1, 4, 6, '2026-08-01')
ON DUPLICATE KEY UPDATE template_name = VALUES(template_name), role_code = VALUES(role_code), minimum_positive_metrics = VALUES(minimum_positive_metrics), effective_from = VALUES(effective_from), is_active = 1;

INSERT IGNORE INTO workload_template_items (template_id, metric_id, is_visible, is_editable, sort_order)
SELECT template.id, metric.id, 1, 1, metric.sort_order
FROM workload_templates template
JOIN metric_definitions metric ON metric.role_code = template.role_code AND metric.metric_group = 'daily_input'
WHERE template.template_code IN ('sales_daily_v4_revised', 'coach_daily_v4_revised', 'manager_daily_v4_revised', 'teaching_supervisor_daily_v4_revised', 'supervisor_daily_v4_revised')
  AND metric.metric_code IN (
      'sales_actual_visit', 'sales_second_visit', 'sales_referral', 'sales_new_revenue', 'sales_moments', 'sales_store_poi_checkin', 'sales_live_ground',
      'coach_body_test', 'coach_motion_plan', 'coach_renew_count', 'coach_parent_comm', 'coach_moments', 'coach_store_poi_checkin', 'coach_store_favorite', 'coach_trial_delivery', 'coach_referral', 'coach_review_ugc', 'coach_live_ground',
      'manager_team_goal_tracking', 'manager_workload_check', 'manager_renewal_list_management', 'manager_blue_v_post', 'manager_standup_review', 'manager_operation_support',
      'teaching_supervisor_body_plan', 'teaching_supervisor_renewal', 'teaching_supervisor_parent_comm', 'teaching_supervisor_moments', 'teaching_supervisor_store_operation', 'teaching_supervisor_trial_delivery', 'teaching_supervisor_referral', 'teaching_supervisor_growth_action', 'teaching_supervisor_coach_mentoring_revised', 'teaching_supervisor_training_observation', 'teaching_supervisor_research_coaching', 'teaching_supervisor_case_review', 'teaching_supervisor_lesson_plan_final_review', 'teaching_supervisor_classroom_spot_check',
      'supervisor_daily_report_submit', 'supervisor_douyin_post_check', 'supervisor_meituan_score_check', 'supervisor_live_execution_check', 'supervisor_lead_allocation_check', 'supervisor_work_log'
  );

INSERT INTO workload_role_rule_versions (version_code, role_code, template_id, minimum_positive_metrics, requires_daily_report, effective_from, effective_to, status, description, created_by_staff_id)
SELECT seed.version_code, template.role_code, template.id, template.minimum_positive_metrics, 1, '2026-08-01', NULL, 'draft', 'V4.0 修正版日报项目规则草稿。', NULL
FROM (
    SELECT 'sales_daily_v4_revised' AS template_code, 'sales-v4-revised-draft' AS version_code
    UNION ALL SELECT 'coach_daily_v4_revised', 'coach-v4-revised-draft'
    UNION ALL SELECT 'manager_daily_v4_revised', 'manager-v4-revised-draft'
    UNION ALL SELECT 'teaching_supervisor_daily_v4_revised', 'teaching-supervisor-v4-revised-draft'
    UNION ALL SELECT 'supervisor_daily_v4_revised', 'supervisor-v4-revised-draft'
) seed
JOIN workload_templates template ON template.template_code = seed.template_code
ON DUPLICATE KEY UPDATE template_id = VALUES(template_id), minimum_positive_metrics = VALUES(minimum_positive_metrics), requires_daily_report = VALUES(requires_daily_report), description = VALUES(description);

INSERT INTO workload_role_metric_rules (rule_version_id, metric_code, metric_name_snapshot, unit_snapshot, value_type_snapshot, is_required, allow_zero, min_value, max_value, need_evidence, min_evidence_count, max_evidence_count, audit_mode, statistic_direction, target_value, sort_order)
SELECT version.id, metric.metric_code, metric.metric_name, metric.unit, metric.value_type, metric.is_required, CASE WHEN metric.is_required = 1 THEN 0 ELSE 1 END, metric.min_value, metric.max_value, 1, 1, 10, 'full', 'higher', 1, metric.sort_order
FROM workload_role_rule_versions version
JOIN metric_definitions metric ON metric.role_code = version.role_code AND metric.is_active = 1
WHERE version.version_code IN ('sales-v4-revised-draft', 'coach-v4-revised-draft', 'manager-v4-revised-draft', 'teaching-supervisor-v4-revised-draft', 'supervisor-v4-revised-draft')
  AND metric.metric_code IN (
      'sales_actual_visit', 'sales_second_visit', 'sales_referral', 'sales_new_revenue', 'sales_moments', 'sales_store_poi_checkin', 'sales_live_ground',
      'coach_body_test', 'coach_motion_plan', 'coach_renew_count', 'coach_parent_comm', 'coach_moments', 'coach_store_poi_checkin', 'coach_store_favorite', 'coach_trial_delivery', 'coach_referral', 'coach_review_ugc', 'coach_live_ground',
      'manager_team_goal_tracking', 'manager_workload_check', 'manager_renewal_list_management', 'manager_blue_v_post', 'manager_standup_review', 'manager_operation_support',
      'teaching_supervisor_body_plan', 'teaching_supervisor_renewal', 'teaching_supervisor_parent_comm', 'teaching_supervisor_moments', 'teaching_supervisor_store_operation', 'teaching_supervisor_trial_delivery', 'teaching_supervisor_referral', 'teaching_supervisor_growth_action', 'teaching_supervisor_coach_mentoring_revised', 'teaching_supervisor_training_observation', 'teaching_supervisor_research_coaching', 'teaching_supervisor_case_review', 'teaching_supervisor_lesson_plan_final_review', 'teaching_supervisor_classroom_spot_check',
      'supervisor_daily_report_submit', 'supervisor_douyin_post_check', 'supervisor_meituan_score_check', 'supervisor_live_execution_check', 'supervisor_lead_allocation_check', 'supervisor_work_log'
  )
ON DUPLICATE KEY UPDATE metric_name_snapshot = VALUES(metric_name_snapshot), unit_snapshot = VALUES(unit_snapshot), value_type_snapshot = VALUES(value_type_snapshot), is_required = VALUES(is_required), allow_zero = VALUES(allow_zero), min_value = VALUES(min_value), max_value = VALUES(max_value), need_evidence = VALUES(need_evidence), min_evidence_count = VALUES(min_evidence_count), max_evidence_count = VALUES(max_evidence_count), audit_mode = VALUES(audit_mode), statistic_direction = VALUES(statistic_direction), target_value = VALUES(target_value), sort_order = VALUES(sort_order);

INSERT INTO workload_conversion_rule_versions (version_code, role_code, source_role_rule_version_id, effective_from, effective_to, status, description, created_by_staff_id)
SELECT seed.version_code, seed.role_code, role_version.id, '2026-08-01', NULL, 'draft', seed.description, NULL
FROM (
    SELECT 'sales-v4-revised-draft' AS version_code, 'sales' AS role_code, 'V4.0 修正版销售动作池：7 类项目任选 4 点达成。' AS description
    UNION ALL SELECT 'coach-v4-revised-draft', 'coach', 'V4.0 修正版教练动作池：8 类项目任选 4 点达成。'
    UNION ALL SELECT 'manager-v4-revised-draft', 'manager', 'V4.0 修正版店长每日全做草稿。'
    UNION ALL SELECT 'teaching-supervisor-v4-revised-draft', 'teaching_supervisor', 'V4.0 修正版教学主管 12 类动作池任选 4 点，教案终审和课堂抽查每日必做。'
    UNION ALL SELECT 'supervisor-v4-revised-draft', 'supervisor', 'V4.0 修正版督导每日全做草稿。'
) seed
LEFT JOIN workload_role_rule_versions role_version ON role_version.version_code = seed.version_code
ON DUPLICATE KEY UPDATE source_role_rule_version_id = VALUES(source_role_rule_version_id), effective_from = VALUES(effective_from), effective_to = VALUES(effective_to), status = VALUES(status), description = VALUES(description);

INSERT INTO workload_conversion_rules (rule_version_id, rule_code, metric_codes_json, conversion_mode, threshold_value, points_per_match, daily_cap_points, tiers_json, evidence_types_json, requires_all_metrics, is_required_check)
SELECT version.id, seed.rule_code, seed.metric_codes_json, seed.conversion_mode, seed.threshold_value, seed.points_per_match, seed.daily_cap_points, NULL, seed.evidence_types_json, seed.requires_all_metrics, seed.is_required_check
FROM workload_conversion_rule_versions version
JOIN (
    SELECT 'sales' AS role_code, 'sales-visit' AS rule_code, '["sales_actual_visit"]' AS metric_codes_json, 'threshold' AS conversion_mode, 1 AS threshold_value, 1 AS points_per_match, NULL AS daily_cap_points, '["image","screenshot"]' AS evidence_types_json, 0 AS requires_all_metrics, 0 AS is_required_check
    UNION ALL SELECT 'sales', 'sales-second-visit', '["sales_second_visit"]', 'threshold', 1, 1, NULL, '["image","screenshot"]', 0, 0
    UNION ALL SELECT 'sales', 'sales-referral', '["sales_referral"]', 'threshold', 1, 1, NULL, '["image","screenshot"]', 0, 0
    UNION ALL SELECT 'sales', 'sales-deal-amount', '["sales_new_revenue"]', 'step', 4000, 1, NULL, '["image","screenshot"]', 0, 0
    UNION ALL SELECT 'sales', 'sales-moments', '["sales_moments"]', 'step', 3, 1, 1, '["image","screenshot"]', 0, 0
    UNION ALL SELECT 'sales', 'sales-store-poi', '["sales_store_poi_checkin"]', 'step', 3, 1, NULL, '["image","screenshot"]', 0, 0
    UNION ALL SELECT 'sales', 'sales-live-ground', '["sales_live_ground"]', 'threshold', 1, 1, NULL, '["image","screenshot"]', 0, 0
    UNION ALL SELECT 'coach', 'coach-body-plan', '["coach_body_test","coach_motion_plan"]', 'composite', 1, 1, 2, '["image","screenshot"]', 1, 0
    UNION ALL SELECT 'coach', 'coach-renewal', '["coach_renew_count"]', 'step', 1, 1, NULL, '["image","screenshot"]', 0, 0
    UNION ALL SELECT 'coach', 'coach-parent-communication', '["coach_parent_comm"]', 'step', 1, 1, NULL, '["image","screenshot"]', 0, 0
    UNION ALL SELECT 'coach', 'coach-moments', '["coach_moments"]', 'step', 3, 1, NULL, '["image","screenshot"]', 0, 0
    UNION ALL SELECT 'coach', 'coach-store-operation', '["coach_store_poi_checkin","coach_store_favorite"]', 'step', 2, 1, NULL, '["image","screenshot"]', 0, 0
    UNION ALL SELECT 'coach', 'coach-trial-delivery', '["coach_trial_delivery"]', 'threshold', 1, 1, NULL, '["image","screenshot"]', 0, 0
    UNION ALL SELECT 'coach', 'coach-referral', '["coach_referral"]', 'threshold', 1, 1, NULL, '["image","screenshot"]', 0, 0
    UNION ALL SELECT 'coach', 'coach-growth-action', '["coach_review_ugc","coach_live_ground"]', 'threshold', 1, 1, NULL, '["image","screenshot"]', 0, 0
    UNION ALL SELECT 'manager', 'manager-team-goal-tracking', '["manager_team_goal_tracking"]', 'required_check', NULL, NULL, NULL, '["image","screenshot","manager_confirmation"]', 0, 1
    UNION ALL SELECT 'manager', 'manager-workload-check', '["manager_workload_check"]', 'required_check', NULL, NULL, NULL, '["image","screenshot","manager_confirmation"]', 0, 1
    UNION ALL SELECT 'manager', 'manager-renewal-list', '["manager_renewal_list_management"]', 'required_check', NULL, NULL, NULL, '["image","screenshot","manager_confirmation"]', 0, 1
    UNION ALL SELECT 'manager', 'manager-blue-v-post', '["manager_blue_v_post"]', 'required_check', NULL, NULL, NULL, '["image","screenshot","manager_confirmation"]', 0, 1
    UNION ALL SELECT 'manager', 'manager-standup-review', '["manager_standup_review"]', 'required_check', NULL, NULL, NULL, '["image","screenshot","manager_confirmation"]', 0, 1
    UNION ALL SELECT 'manager', 'manager-operation-support', '["manager_operation_support"]', 'required_check', NULL, NULL, NULL, '["image","screenshot","manager_confirmation"]', 0, 1
    UNION ALL SELECT 'teaching_supervisor', 'teaching-body-plan', '["teaching_supervisor_body_plan"]', 'threshold', 1, 1, NULL, '["image","screenshot","manager_confirmation"]', 0, 0
    UNION ALL SELECT 'teaching_supervisor', 'teaching-renewal', '["teaching_supervisor_renewal"]', 'threshold', 1, 1, NULL, '["image","screenshot","manager_confirmation"]', 0, 0
    UNION ALL SELECT 'teaching_supervisor', 'teaching-parent-communication', '["teaching_supervisor_parent_comm"]', 'threshold', 1, 1, NULL, '["image","screenshot","manager_confirmation"]', 0, 0
    UNION ALL SELECT 'teaching_supervisor', 'teaching-moments', '["teaching_supervisor_moments"]', 'threshold', 1, 1, NULL, '["image","screenshot","manager_confirmation"]', 0, 0
    UNION ALL SELECT 'teaching_supervisor', 'teaching-store-operation', '["teaching_supervisor_store_operation"]', 'step', 2, 1, NULL, '["image","screenshot","manager_confirmation"]', 0, 0
    UNION ALL SELECT 'teaching_supervisor', 'teaching-trial-delivery', '["teaching_supervisor_trial_delivery"]', 'threshold', 1, 1, NULL, '["image","screenshot","manager_confirmation"]', 0, 0
    UNION ALL SELECT 'teaching_supervisor', 'teaching-referral', '["teaching_supervisor_referral"]', 'threshold', 1, 1, NULL, '["image","screenshot","manager_confirmation"]', 0, 0
    UNION ALL SELECT 'teaching_supervisor', 'teaching-growth-action', '["teaching_supervisor_growth_action"]', 'threshold', 1, 1, NULL, '["image","screenshot","manager_confirmation"]', 0, 0
    UNION ALL SELECT 'teaching_supervisor', 'teaching-coach-mentoring', '["teaching_supervisor_coach_mentoring_revised"]', 'threshold', 1, 1, NULL, '["image","screenshot","manager_confirmation"]', 0, 0
    UNION ALL SELECT 'teaching_supervisor', 'teaching-training-observation', '["teaching_supervisor_training_observation"]', 'threshold', 1, 1, NULL, '["image","screenshot","manager_confirmation"]', 0, 0
    UNION ALL SELECT 'teaching_supervisor', 'teaching-research-coaching', '["teaching_supervisor_research_coaching"]', 'threshold', 1, 1, NULL, '["image","screenshot","manager_confirmation"]', 0, 0
    UNION ALL SELECT 'teaching_supervisor', 'teaching-case-review', '["teaching_supervisor_case_review"]', 'threshold', 1, 1, NULL, '["image","screenshot","manager_confirmation"]', 0, 0
    UNION ALL SELECT 'teaching_supervisor', 'teaching-lesson-plan-final-review', '["teaching_supervisor_lesson_plan_final_review"]', 'required_check', NULL, NULL, NULL, '["image","screenshot","manager_confirmation"]', 0, 1
    UNION ALL SELECT 'teaching_supervisor', 'teaching-classroom-spot-check', '["teaching_supervisor_classroom_spot_check"]', 'required_check', NULL, NULL, NULL, '["image","screenshot","manager_confirmation"]', 0, 1
    UNION ALL SELECT 'supervisor', 'supervisor-daily-report-submit', '["supervisor_daily_report_submit"]', 'required_check', NULL, NULL, NULL, '["image","screenshot","manager_confirmation"]', 0, 1
    UNION ALL SELECT 'supervisor', 'supervisor-douyin-post-check', '["supervisor_douyin_post_check"]', 'required_check', NULL, NULL, NULL, '["image","screenshot","manager_confirmation"]', 0, 1
    UNION ALL SELECT 'supervisor', 'supervisor-meituan-score-check', '["supervisor_meituan_score_check"]', 'required_check', NULL, NULL, NULL, '["image","screenshot","manager_confirmation"]', 0, 1
    UNION ALL SELECT 'supervisor', 'supervisor-live-execution-check', '["supervisor_live_execution_check"]', 'required_check', NULL, NULL, NULL, '["image","screenshot","manager_confirmation"]', 0, 1
    UNION ALL SELECT 'supervisor', 'supervisor-lead-allocation-check', '["supervisor_lead_allocation_check"]', 'required_check', NULL, NULL, NULL, '["image","screenshot","manager_confirmation"]', 0, 1
    UNION ALL SELECT 'supervisor', 'supervisor-work-log', '["supervisor_work_log"]', 'required_check', NULL, NULL, NULL, '["image","screenshot","manager_confirmation"]', 0, 1
) seed ON seed.role_code = version.role_code
WHERE version.version_code IN ('sales-v4-revised-draft', 'coach-v4-revised-draft', 'manager-v4-revised-draft', 'teaching-supervisor-v4-revised-draft', 'supervisor-v4-revised-draft')
ON DUPLICATE KEY UPDATE metric_codes_json = VALUES(metric_codes_json), conversion_mode = VALUES(conversion_mode), threshold_value = VALUES(threshold_value), points_per_match = VALUES(points_per_match), daily_cap_points = VALUES(daily_cap_points), evidence_types_json = VALUES(evidence_types_json), requires_all_metrics = VALUES(requires_all_metrics), is_required_check = VALUES(is_required_check);
