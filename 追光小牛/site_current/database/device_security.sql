-- ============================================
-- 设备登录记录表 - 用于安全监控和设备绑定
-- ============================================

CREATE TABLE IF NOT EXISTS `device_logins` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `staff_id` INT UNSIGNED NOT NULL COMMENT '员工ID',
  `device_id` VARCHAR(255) NOT NULL COMMENT '微信匿名设备标识',
  `device_fingerprint` VARCHAR(64) NOT NULL COMMENT '设备指纹（MD5组合）',
  `device_name` VARCHAR(100) DEFAULT NULL COMMENT '设备名称',
  `device_model` VARCHAR(100) DEFAULT NULL COMMENT '设备型号',
  `os_version` VARCHAR(50) DEFAULT NULL COMMENT '操作系统版本',
  `app_version` VARCHAR(20) DEFAULT NULL COMMENT '小程序版本',
  `screen_width` INT DEFAULT 0 COMMENT '屏幕宽度',
  `screen_height` INT DEFAULT 0 COMMENT '屏幕高度',
  `login_count` INT DEFAULT 1 COMMENT '登录次数',
  `is_trusted` TINYINT DEFAULT 0 COMMENT '是否信任设备: 0否 1是',
  `is_active` TINYINT DEFAULT 1 COMMENT '是否活跃',
  `first_login` DATETIME DEFAULT NULL COMMENT '首次登录时间',
  `last_login` DATETIME DEFAULT NULL COMMENT '最后登录时间',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_staff` (`staff_id`),
  KEY `idx_fingerprint` (`device_fingerprint`),
  KEY `idx_last_login` (`last_login`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='设备登录记录表';

-- ============================================
-- 管理员查看所有设备记录（用于安全审计）
-- ============================================

-- 视图：设备异常预警（同一账号多设备）
CREATE OR REPLACE VIEW `v_device_security_alert` AS
SELECT
  dl.staff_id,
  s.name as staff_name,
  s.employee_no,
  st.name as store_name,
  COUNT(DISTINCT dl.device_fingerprint) as device_count,
  GROUP_CONCAT(DISTINCT dl.device_model SEPARATOR ', ') as device_models,
  MAX(dl.last_login) as last_login,
  dl.login_count
FROM device_logins dl
JOIN staffs s ON dl.staff_id = s.id
JOIN stores st ON s.store_id = st.id
GROUP BY dl.staff_id
HAVING COUNT(DISTINCT dl.device_fingerprint) > 3;

-- 视图：设备使用统计
CREATE OR REPLACE VIEW `v_device_usage_stats` AS
SELECT
  dl.staff_id,
  s.name as staff_name,
  s.role,
  st.name as store_name,
  COUNT(DISTINCT dl.device_fingerprint) as unique_devices,
  SUM(dl.login_count) as total_logins,
  MAX(dl.last_login) as last_login,
  SUM(CASE WHEN dl.is_trusted = 1 THEN 1 ELSE 0 END) as trusted_count
FROM device_logins dl
JOIN staffs s ON dl.staff_id = s.id
JOIN stores st ON s.store_id = st.id
GROUP BY dl.staff_id;