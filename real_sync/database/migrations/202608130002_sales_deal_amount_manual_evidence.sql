-- Add the v4 sales deal amount input without changing historical sales metrics.

SET NAMES utf8mb4;

INSERT INTO metric_definitions (
    metric_code, metric_name, role_code, metric_group, metric_category, unit,
    value_type, is_required, is_system_calculated, is_active, default_value,
    min_value, max_value, sort_order, description
) VALUES (
    'sales_deal_amount', '成交金额', 'sales', 'daily_input', 'result', 'yuan',
    'number', 0, 0, 1, 0.00, 0.00, NULL, 75,
    '填写当天全部成交总金额，并上传成交系统截图；满 4000 元计 2 点工作量。'
) ON DUPLICATE KEY UPDATE
    metric_name = VALUES(metric_name),
    role_code = VALUES(role_code),
    metric_group = VALUES(metric_group),
    metric_category = VALUES(metric_category),
    unit = VALUES(unit),
    value_type = VALUES(value_type),
    is_active = 1,
    min_value = VALUES(min_value),
    max_value = VALUES(max_value),
    sort_order = VALUES(sort_order),
    description = VALUES(description);

INSERT IGNORE INTO workload_template_items (template_id, metric_id, is_visible, is_editable, sort_order)
SELECT template.id, metric.id, 1, 1, 75
FROM workload_templates template
INNER JOIN metric_definitions metric
    ON metric.metric_code = 'sales_deal_amount'
WHERE template.role_code = 'sales';

-- Close the old sales rule interval and clone it for the new daily input.
UPDATE workload_role_rule_versions
SET effective_to = '2026-08-12',
    updated_at = NOW()
WHERE role_code = 'sales'
  AND version_code <> 'sales-v4-deal-amount-manual'
  AND status IN ('active', 'scheduled')
  AND effective_from < '2026-08-13'
  AND (effective_to IS NULL OR effective_to >= '2026-08-13');

INSERT INTO workload_role_rule_versions (
    version_code, role_code, template_id, minimum_positive_metrics, requires_daily_report,
    effective_from, effective_to, status, description, created_by_staff_id
)
SELECT
    'sales-v4-deal-amount-manual', 'sales', source.template_id, source.minimum_positive_metrics, source.requires_daily_report,
    '2026-08-13', NULL, 'active',
    CONCAT(source.description, '；新增成交金额手工填报：填写当天全部成交总金额并上传成交系统截图，审核通过后生效。'),
    source.created_by_staff_id
FROM workload_role_rule_versions source
WHERE source.role_code = 'sales'
  AND source.version_code <> 'sales-v4-deal-amount-manual'
  AND source.effective_to = '2026-08-12'
ORDER BY source.effective_from DESC, source.id DESC
LIMIT 1
ON DUPLICATE KEY UPDATE
    template_id = VALUES(template_id),
    minimum_positive_metrics = VALUES(minimum_positive_metrics),
    requires_daily_report = VALUES(requires_daily_report),
    effective_from = VALUES(effective_from),
    effective_to = VALUES(effective_to),
    status = VALUES(status),
    description = VALUES(description);

INSERT INTO workload_role_metric_rules (
    rule_version_id, metric_code, metric_name_snapshot, unit_snapshot, value_type_snapshot,
    is_required, allow_zero, min_value, max_value, need_evidence, min_evidence_count,
    max_evidence_count, audit_mode, statistic_direction, target_value, sort_order
)
SELECT
    target.id, source_rule.metric_code, source_rule.metric_name_snapshot, source_rule.unit_snapshot, source_rule.value_type_snapshot,
    source_rule.is_required, source_rule.allow_zero, source_rule.min_value, source_rule.max_value, source_rule.need_evidence,
    source_rule.min_evidence_count, source_rule.max_evidence_count, source_rule.audit_mode, source_rule.statistic_direction,
    source_rule.target_value, source_rule.sort_order
FROM workload_role_rule_versions target
INNER JOIN workload_role_rule_versions source
    ON source.role_code = 'sales'
    AND source.version_code <> 'sales-v4-deal-amount-manual'
    AND source.effective_to = '2026-08-12'
INNER JOIN workload_role_metric_rules source_rule ON source_rule.rule_version_id = source.id
WHERE target.version_code = 'sales-v4-deal-amount-manual'
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

INSERT INTO workload_role_metric_rules (
    rule_version_id, metric_code, metric_name_snapshot, unit_snapshot, value_type_snapshot,
    is_required, allow_zero, min_value, max_value, need_evidence, min_evidence_count,
    max_evidence_count, audit_mode, statistic_direction, target_value, sort_order
)
SELECT
    version.id, 'sales_deal_amount', '成交金额', 'yuan', 'number',
    0, 1, 0.00, NULL, 1, 1, 10, 'full', 'higher', 4000.00, 75
FROM workload_role_rule_versions version
WHERE version.version_code = 'sales-v4-deal-amount-manual'
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

UPDATE workload_conversion_rule_versions
SET effective_to = '2026-08-12',
    updated_at = NOW()
WHERE role_code = 'sales'
  AND version_code <> 'sales-v4-deal-amount-manual'
  AND status IN ('active', 'scheduled')
  AND effective_from < '2026-08-13'
  AND (effective_to IS NULL OR effective_to >= '2026-08-13');

INSERT INTO workload_conversion_rule_versions (
    version_code, role_code, source_role_rule_version_id, effective_from, effective_to, status, description
)
SELECT
    'sales-v4-deal-amount-manual', 'sales', role_version.id, '2026-08-13', NULL, 'active',
    '销售工作量换算：成交金额大于 0 元计 1 点，满 4000 元计 2 点，凭成交系统截图审核。'
FROM workload_role_rule_versions role_version
WHERE role_version.version_code = 'sales-v4-deal-amount-manual'
ON DUPLICATE KEY UPDATE
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
    target.id, source_rule.rule_code, source_rule.metric_codes_json, source_rule.conversion_mode, source_rule.threshold_value,
    source_rule.points_per_match, source_rule.daily_cap_points, source_rule.tiers_json, source_rule.evidence_types_json,
    source_rule.requires_all_metrics, source_rule.is_required_check
FROM workload_conversion_rule_versions target
INNER JOIN workload_conversion_rule_versions source
    ON source.role_code = 'sales'
    AND source.version_code <> 'sales-v4-deal-amount-manual'
    AND source.effective_to = '2026-08-12'
INNER JOIN workload_conversion_rules source_rule ON source_rule.rule_version_id = source.id
WHERE target.version_code = 'sales-v4-deal-amount-manual'
ON DUPLICATE KEY UPDATE
    metric_codes_json = VALUES(metric_codes_json),
    conversion_mode = VALUES(conversion_mode),
    threshold_value = VALUES(threshold_value),
    points_per_match = VALUES(points_per_match),
    daily_cap_points = VALUES(daily_cap_points),
    tiers_json = VALUES(tiers_json),
    evidence_types_json = VALUES(evidence_types_json),
    requires_all_metrics = VALUES(requires_all_metrics),
    is_required_check = VALUES(is_required_check),
    updated_at = CURRENT_TIMESTAMP;

INSERT INTO workload_conversion_rules (
    rule_version_id, rule_code, metric_codes_json, conversion_mode, threshold_value,
    points_per_match, daily_cap_points, tiers_json, evidence_types_json, requires_all_metrics, is_required_check
)
SELECT
    conversion_version.id, 'sales-deal-amount-tier', '["sales_deal_amount"]', 'tier', NULL,
    NULL, 2.00,
    '[{"min":0.01,"max":3999.99,"points":1,"priority":1},{"min":4000,"points":2,"priority":2}]',
    '["image","screenshot"]', 0, 0
FROM workload_conversion_rule_versions conversion_version
WHERE conversion_version.version_code = 'sales-v4-deal-amount-manual'
ON DUPLICATE KEY UPDATE
    metric_codes_json = VALUES(metric_codes_json),
    conversion_mode = VALUES(conversion_mode),
    threshold_value = VALUES(threshold_value),
    points_per_match = VALUES(points_per_match),
    daily_cap_points = VALUES(daily_cap_points),
    tiers_json = VALUES(tiers_json),
    evidence_types_json = VALUES(evidence_types_json),
    requires_all_metrics = VALUES(requires_all_metrics),
    is_required_check = VALUES(is_required_check),
    updated_at = CURRENT_TIMESTAMP;
