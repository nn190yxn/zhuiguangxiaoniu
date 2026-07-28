-- Seed store-level offline workload actions and evidence requirements.

SET NAMES utf8mb4;

INSERT INTO metric_definitions (metric_code, metric_name, role_code, metric_group, metric_category, unit, value_type, is_required, is_system_calculated, default_value, min_value, sort_order, description)
VALUES
    ('sales_store_poi_checkin', '门店点亮', 'sales', 'daily_input', 'behavior', '张', 'integer', 0, 0, 0, 0, 98, '线下核销时完成 POI 打卡点亮，需上传拍照或截图凭证'),
    ('coach_store_poi_checkin', '门店点亮', 'coach', 'daily_input', 'behavior', '张', 'integer', 0, 0, 0, 0, 115, '线下核销时完成 POI 打卡点亮，需上传拍照或截图凭证'),
    ('manager_store_poi_checkin', '门店点亮', 'manager', 'daily_input', 'behavior', '张', 'integer', 1, 0, 0, 0, 10, '门店每日至少 5 个点亮，教练或销售上传凭证可计入店长门店工作量'),
    ('manager_store_favorite', '收藏门店', 'manager', 'daily_input', 'behavior', '张', 'integer', 1, 0, 0, 0, 20, '门店每日至少 5 个收藏门店截图或照片'),
    ('manager_nine_image_review', '9图好评', 'manager', 'daily_input', 'result', '条', 'integer', 1, 0, 0, 0, 30, '门店每日至少 1 条 100 字、9 图和视频好评凭证'),
    ('manager_three_image_review', '3图好评', 'manager', 'daily_input', 'result', '条', 'integer', 1, 0, 0, 0, 40, '门店每日至少 1 条 45 字和 3 图好评凭证'),
    ('manager_online_order_count', '上翻单数', 'manager', 'daily_input', 'result', '单', 'integer', 1, 0, 0, 0, 50, '线下转线上付款每日不少于 3 单，需上传订单或核销截图'),
    ('manager_online_order_amount', '上翻金额', 'manager', 'daily_input', 'result', '元', 'currency', 1, 0, 0, 0, 60, '线下转线上付款每日不少于 500 元，需上传核销截图返还财务'),
    ('manager_video_post', '视频号播放', 'manager', 'daily_input', 'process', '条', 'integer', 1, 0, 0, 0, 70, '门店每日拍摄 1 条视频号内容，需上传拍摄或播放截图')
ON DUPLICATE KEY UPDATE
    metric_name = VALUES(metric_name),
    role_code = VALUES(role_code),
    metric_group = VALUES(metric_group),
    metric_category = VALUES(metric_category),
    unit = VALUES(unit),
    value_type = VALUES(value_type),
    is_required = VALUES(is_required),
    is_system_calculated = VALUES(is_system_calculated),
    default_value = VALUES(default_value),
    min_value = VALUES(min_value),
    sort_order = VALUES(sort_order),
    description = VALUES(description),
    is_active = 1;

INSERT INTO workload_templates (template_code, template_name, role_code, is_active, version_no, minimum_positive_metrics, effective_from)
VALUES ('manager_daily_v4_store_offline', '店长每日线下运营动作模板 V4', 'manager', 1, 4, 4, '2026-07-28')
ON DUPLICATE KEY UPDATE
    template_name = VALUES(template_name),
    role_code = VALUES(role_code),
    is_active = 1,
    minimum_positive_metrics = VALUES(minimum_positive_metrics),
    effective_from = VALUES(effective_from);

INSERT IGNORE INTO workload_template_items (template_id, metric_id, is_visible, is_editable, sort_order)
SELECT template.id, metric.id, 1, 1, metric.sort_order
FROM workload_templates template
JOIN metric_definitions metric ON metric.role_code = template.role_code AND metric.metric_group = 'daily_input'
WHERE template.template_code = 'manager_daily_v4_store_offline';

INSERT IGNORE INTO workload_template_items (template_id, metric_id, is_visible, is_editable, sort_order)
SELECT template.id, metric.id, 1, 1, metric.sort_order
FROM workload_templates template
JOIN metric_definitions metric ON metric.role_code = template.role_code AND metric.metric_code IN ('sales_store_poi_checkin', 'coach_store_poi_checkin')
WHERE template.role_code IN ('sales', 'coach') AND template.is_active = 1;

INSERT INTO workload_role_rule_versions (version_code, role_code, template_id, minimum_positive_metrics, requires_daily_report, effective_from, effective_to, status, description, created_by_staff_id)
SELECT 'manager-store-offline-v4', 'manager', template.id, 4, 1, '2026-07-28', NULL, 'active', '店长每日线下运营动作以门店为单位完成，所有线下动作均需拍照或截图凭证。', NULL
FROM workload_templates template
WHERE template.template_code = 'manager_daily_v4_store_offline'
ON DUPLICATE KEY UPDATE
    template_id = VALUES(template_id),
    minimum_positive_metrics = VALUES(minimum_positive_metrics),
    requires_daily_report = VALUES(requires_daily_report),
    description = VALUES(description);

UPDATE workload_role_rule_versions version
JOIN workload_templates template ON template.template_code = 'manager_daily_v4_store_offline'
SET version.template_id = COALESCE(version.template_id, template.id),
    version.description = CASE
        WHEN TRIM(version.description) = '' THEN '店长每日线下运营动作以门店为单位完成，所有线下动作均需拍照或截图凭证。'
        ELSE version.description
    END
WHERE version.role_code = 'manager' AND version.status IN ('active', 'scheduled');

UPDATE workload_role_rule_versions
SET description = CASE
    WHEN description LIKE '%4000%' THEN description
    WHEN TRIM(description) = '' THEN '教练工作量说明：销售相关产出也计算工作量，4000 元为销售档位口径。'
    ELSE CONCAT(description, '；教练工作量说明：销售相关产出也计算工作量，4000 元为销售档位口径。')
END
WHERE role_code = 'coach' AND status IN ('active', 'scheduled');

INSERT INTO workload_role_metric_rules (
    rule_version_id,
    metric_code,
    metric_name_snapshot,
    unit_snapshot,
    value_type_snapshot,
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
    version.id,
    metric.metric_code,
    metric.metric_name,
    metric.unit,
    metric.value_type,
    metric.is_required,
    1,
    metric.min_value,
    metric.max_value,
    1,
    1,
    10,
    'evidence',
    'higher',
    CASE
        WHEN metric.metric_code = 'manager_store_poi_checkin' THEN 5
        WHEN metric.metric_code = 'manager_store_favorite' THEN 5
        WHEN metric.metric_code IN ('manager_nine_image_review', 'manager_three_image_review', 'manager_video_post') THEN 1
        WHEN metric.metric_code = 'manager_online_order_count' THEN 3
        WHEN metric.metric_code = 'manager_online_order_amount' THEN 500
        ELSE NULL
    END,
    metric.sort_order
FROM workload_role_rule_versions version
JOIN metric_definitions metric ON metric.role_code = version.role_code
WHERE version.status IN ('active', 'scheduled')
  AND metric.metric_code IN (
      'sales_store_poi_checkin',
      'coach_store_poi_checkin',
      'manager_store_poi_checkin',
      'manager_store_favorite',
      'manager_nine_image_review',
      'manager_three_image_review',
      'manager_online_order_count',
      'manager_online_order_amount',
      'manager_video_post'
  )
ON DUPLICATE KEY UPDATE
    metric_name_snapshot = VALUES(metric_name_snapshot),
    unit_snapshot = VALUES(unit_snapshot),
    value_type_snapshot = VALUES(value_type_snapshot),
    is_required = VALUES(is_required),
    allow_zero = VALUES(allow_zero),
    min_value = VALUES(min_value),
    max_value = VALUES(max_value),
    need_evidence = VALUES(need_evidence),
    min_evidence_count = VALUES(min_evidence_count),
    max_evidence_count = VALUES(max_evidence_count),
    audit_mode = VALUES(audit_mode),
    statistic_direction = VALUES(statistic_direction),
    target_value = VALUES(target_value),
    sort_order = VALUES(sort_order);
