CREATE TABLE IF NOT EXISTS metric_definitions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  metric_code VARCHAR(64) NOT NULL,
  metric_name VARCHAR(128) NOT NULL,
  role_code VARCHAR(32) NOT NULL,
  metric_group VARCHAR(32) NOT NULL,
  metric_category VARCHAR(32) NOT NULL,
  unit VARCHAR(32) NOT NULL DEFAULT 'count',
  value_type VARCHAR(16) NOT NULL DEFAULT 'number',
  is_required TINYINT(1) NOT NULL DEFAULT 0,
  is_system_calculated TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  default_value DECIMAL(18,2) DEFAULT NULL,
  min_value DECIMAL(18,2) DEFAULT NULL,
  max_value DECIMAL(18,2) DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  description VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_metric_code (metric_code),
  KEY idx_role_group (role_code, metric_group, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS workload_templates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  template_code VARCHAR(64) NOT NULL,
  template_name VARCHAR(128) NOT NULL,
  role_code VARCHAR(32) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  version_no INT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_template_code (template_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS workload_template_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  template_id BIGINT UNSIGNED NOT NULL,
  metric_id BIGINT UNSIGNED NOT NULL,
  is_visible TINYINT(1) NOT NULL DEFAULT 1,
  is_editable TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uk_template_metric (template_id, metric_id),
  KEY idx_template_sort (template_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS workload_daily_reports (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  report_date DATE NOT NULL,
  store_id BIGINT UNSIGNED NOT NULL,
  staff_id BIGINT UNSIGNED NOT NULL,
  role_code VARCHAR(32) NOT NULL,
  template_id BIGINT UNSIGNED DEFAULT NULL,
  submit_status VARCHAR(16) NOT NULL DEFAULT 'draft',
  source VARCHAR(16) NOT NULL DEFAULT 'h5',
  remarks VARCHAR(255) NOT NULL DEFAULT '',
  submitted_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_report_unique (report_date, store_id, staff_id, role_code),
  KEY idx_store_date (store_id, report_date),
  KEY idx_staff_date (staff_id, report_date),
  KEY idx_role_date (role_code, report_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS workload_daily_report_values (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  report_id BIGINT UNSIGNED NOT NULL,
  metric_id BIGINT UNSIGNED NOT NULL,
  numeric_value DECIMAL(18,2) DEFAULT NULL,
  text_value VARCHAR(255) DEFAULT NULL,
  json_value JSON DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_report_metric (report_id, metric_id),
  KEY idx_metric (metric_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS workload_daily_aggregates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  stat_date DATE NOT NULL,
  stat_granularity VARCHAR(16) NOT NULL,
  store_id BIGINT UNSIGNED DEFAULT NULL,
  staff_id BIGINT UNSIGNED DEFAULT NULL,
  role_code VARCHAR(32) NOT NULL,
  metric_code VARCHAR(64) NOT NULL,
  metric_value DECIMAL(18,4) NOT NULL DEFAULT 0,
  calculated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_aggregate_unique (stat_date, stat_granularity, store_id, staff_id, role_code, metric_code),
  KEY idx_metric_date (metric_code, stat_date),
  KEY idx_store_role_date (store_id, role_code, stat_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO metric_definitions (
  metric_code, metric_name, role_code, metric_group, metric_category, unit, value_type, is_required, is_system_calculated, default_value, min_value, sort_order, description
) VALUES
('sales_resources', '新增资源数', 'sales', 'daily_input', 'behavior', 'count', 'number', 1, 0, 0, 0, 10, '销售当天新增的有效资源数'),
('sales_calls', '外呼数', 'sales', 'daily_input', 'behavior', 'count', 'number', 0, 0, 0, 0, 20, '销售当天外呼次数'),
('sales_wechat_reach', '微信触达数', 'sales', 'daily_input', 'behavior', 'count', 'number', 0, 0, 0, 0, 30, '销售当天微信触达人次'),
('sales_plan_visit', '计划邀约数', 'sales', 'daily_input', 'process', 'count', 'number', 1, 0, 0, 0, 40, '销售当天计划邀约到店人数'),
('sales_actual_visit', '实际邀约数', 'sales', 'daily_input', 'process', 'count', 'number', 1, 0, 0, 0, 50, '销售当天实际完成邀约人数'),
('sales_plan_arrive', '计划到店数', 'sales', 'daily_input', 'process', 'count', 'number', 0, 0, 0, 0, 60, '销售当天计划到店人数'),
('sales_actual_arrive', '实际到店数', 'sales', 'daily_input', 'process', 'count', 'number', 1, 0, 0, 0, 70, '销售当天实际到店人数'),
('sales_deal_count', '成交人数', 'sales', 'daily_input', 'result', 'count', 'number', 1, 0, 0, 0, 80, '销售当天成交人数'),
('sales_new_revenue', '新签金额', 'sales', 'daily_input', 'result', 'yuan', 'number', 0, 0, 0, 0, 90, '销售当天新签金额'),
('sales_renew_revenue', '续费金额', 'sales', 'daily_input', 'result', 'yuan', 'number', 0, 0, 0, 0, 100, '销售当天续费金额'),
('coach_plan_hours', '计划耗课节数', 'coach', 'daily_input', 'process', 'class', 'number', 1, 0, 0, 0, 10, '教练当天计划耗课节数'),
('coach_actual_hours', '实际耗课节数', 'coach', 'daily_input', 'process', 'class', 'number', 1, 0, 0, 0, 20, '教练当天实际耗课节数'),
('coach_plan_comm', '计划沟通人数', 'coach', 'daily_input', 'process', 'count', 'number', 0, 0, 0, 0, 30, '教练当天计划家长沟通人数'),
('coach_actual_comm', '实际沟通人数', 'coach', 'daily_input', 'behavior', 'count', 'number', 1, 0, 0, 0, 40, '教练当天实际沟通家长人数'),
('coach_body_test', '体测人数', 'coach', 'daily_input', 'behavior', 'count', 'number', 0, 0, 0, 0, 50, '教练当天完成体测人数'),
('coach_camp_recommend', '暑期营推荐人数', 'coach', 'daily_input', 'result', 'count', 'number', 0, 0, 0, 0, 60, '教练当天暑期营推荐人数'),
('coach_renew_count', '续费单数', 'coach', 'daily_input', 'result', 'count', 'number', 0, 0, 0, 0, 70, '教练当天完成续费单数'),
('coach_renew_revenue', '续费金额', 'coach', 'daily_input', 'result', 'yuan', 'number', 0, 0, 0, 0, 80, '教练当天续费金额'),
('sales_visit_completion_rate', '邀约完成率', 'sales', 'aggregate', 'derived', 'ratio', 'number', 0, 1, 0, 0, 1000, '系统计算：实际邀约数 / 计划邀约数'),
('sales_arrive_rate', '到店率', 'sales', 'aggregate', 'derived', 'ratio', 'number', 0, 1, 0, 0, 1010, '系统计算：实际到店数 / 实际邀约数'),
('sales_deal_rate', '成交率', 'sales', 'aggregate', 'derived', 'ratio', 'number', 0, 1, 0, 0, 1020, '系统计算：成交人数 / 实际到店数'),
('coach_hours_completion_rate', '耗课完成率', 'coach', 'aggregate', 'derived', 'ratio', 'number', 0, 1, 0, 0, 1030, '系统计算：实际耗课节数 / 计划耗课节数'),
('coach_comm_completion_rate', '沟通完成率', 'coach', 'aggregate', 'derived', 'ratio', 'number', 0, 1, 0, 0, 1040, '系统计算：实际沟通人数 / 计划沟通人数');

INSERT INTO workload_templates (template_code, template_name, role_code, is_active, version_no) VALUES
('sales_daily_v1', '销售日报模板 V1', 'sales', 1, 1),
('coach_daily_v1', '教练日报模板 V1', 'coach', 1, 1);
