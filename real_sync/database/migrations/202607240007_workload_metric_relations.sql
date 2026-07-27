-- Versioned workload metric relationships for funnels and plan completion.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS workload_metric_relation_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    version_code VARCHAR(64) NOT NULL,
    effective_from DATE NOT NULL,
    effective_to DATE NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'active',
    description VARCHAR(500) NOT NULL DEFAULT '',
    created_by_staff_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_workload_metric_relation_versions_code (version_code),
    KEY idx_workload_metric_relation_versions_effective (effective_from, effective_to, status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS workload_metric_relations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    relation_version_id BIGINT UNSIGNED NOT NULL,
    relation_code VARCHAR(64) NOT NULL,
    relation_name VARCHAR(100) NOT NULL,
    relation_group VARCHAR(32) NOT NULL,
    role_code VARCHAR(32) NOT NULL,
    numerator_metric_code VARCHAR(64) NOT NULL,
    denominator_metric_code VARCHAR(64) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_workload_metric_relation (relation_version_id, relation_code),
    KEY idx_workload_metric_relations_group (relation_version_id, relation_group, role_code, enabled, sort_order),
    KEY idx_workload_metric_relations_metrics (numerator_metric_code, denominator_metric_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO workload_metric_relation_versions (
    version_code,
    effective_from,
    effective_to,
    status,
    description
) VALUES (
    'workload-relations-v1',
    '1970-01-01',
    NULL,
    'active',
    'Initial sales funnel and coach plan completion relationships'
) ON DUPLICATE KEY UPDATE version_code = VALUES(version_code);

INSERT INTO workload_metric_relations (
    relation_version_id,
    relation_code,
    relation_name,
    relation_group,
    role_code,
    numerator_metric_code,
    denominator_metric_code,
    enabled,
    sort_order
)
SELECT relation_version.id, seed.relation_code, seed.relation_name, seed.relation_group,
       seed.role_code, seed.numerator_metric_code, seed.denominator_metric_code, 1, seed.sort_order
FROM workload_metric_relation_versions relation_version
JOIN (
    SELECT 'sales_invitation_rate' AS relation_code, '资源邀约转化率' AS relation_name,
           'sales_funnel' AS relation_group, 'sales' AS role_code,
           'sales_actual_visit' AS numerator_metric_code, 'sales_resources' AS denominator_metric_code,
           10 AS sort_order
    UNION ALL SELECT 'sales_arrival_rate', '邀约到店转化率', 'sales_funnel', 'sales',
           'sales_actual_arrive', 'sales_actual_visit', 20
    UNION ALL SELECT 'sales_deal_rate', '到店成交转化率', 'sales_funnel', 'sales',
           'sales_deal_count', 'sales_actual_arrive', 30
    UNION ALL SELECT 'coach_lesson_completion_rate', '耗课计划完成率', 'coach_plan_completion', 'coach',
           'coach_actual_hours', 'coach_plan_hours', 10
    UNION ALL SELECT 'coach_communication_completion_rate', '沟通计划完成率', 'coach_plan_completion', 'coach',
           'coach_actual_comm', 'coach_plan_comm', 20
) seed
WHERE relation_version.version_code = 'workload-relations-v1'
ON DUPLICATE KEY UPDATE
    relation_name = VALUES(relation_name),
    relation_group = VALUES(relation_group),
    role_code = VALUES(role_code),
    numerator_metric_code = VALUES(numerator_metric_code),
    denominator_metric_code = VALUES(denominator_metric_code),
    enabled = VALUES(enabled),
    sort_order = VALUES(sort_order);
