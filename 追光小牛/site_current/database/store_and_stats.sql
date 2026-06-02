-- ============================================
-- 追光小牛员工内网系统 - 门店员工和统计功能
-- 创建时间: 2026-04-27
-- ============================================

-- ============================================
-- 第一部分：门店管理表
-- ============================================

CREATE TABLE IF NOT EXISTS `stores` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL COMMENT '门店名称',
  `code` VARCHAR(50) NOT NULL COMMENT '门店编码',
  `province` VARCHAR(50) DEFAULT NULL COMMENT '省份',
  `city` VARCHAR(50) DEFAULT NULL COMMENT '城市',
  `district` VARCHAR(50) DEFAULT NULL COMMENT '区县',
  `address` VARCHAR(255) DEFAULT NULL COMMENT '详细地址',
  `manager_name` VARCHAR(50) DEFAULT NULL COMMENT '店长姓名',
  `manager_phone` VARCHAR(20) DEFAULT NULL COMMENT '店长电话',
  `contact` VARCHAR(100) DEFAULT NULL COMMENT '联系人',
  `phone` VARCHAR(20) DEFAULT NULL COMMENT '联系电话',
  `status` TINYINT DEFAULT 1 COMMENT '状态: 0禁用 1启用',
  `sort_order` INT DEFAULT 0 COMMENT '排序',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='门店表';

-- ============================================
-- 第二部分：员工管理表
-- ============================================

CREATE TABLE IF NOT EXISTS `staffs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_id` INT UNSIGNED NOT NULL COMMENT '所属门店ID',
  `user_id` INT UNSIGNED DEFAULT NULL COMMENT '关联用户ID(可选)',
  `employee_no` VARCHAR(50) NOT NULL COMMENT '员工工号',
  `name` VARCHAR(50) NOT NULL COMMENT '员工姓名',
  `avatar` VARCHAR(255) DEFAULT NULL COMMENT '头像URL',
  `role` VARCHAR(20) NOT NULL COMMENT '角色: sales销售 coach教练 manager店长',
  `job_title` VARCHAR(50) DEFAULT NULL COMMENT '职位名称',
  `phone` VARCHAR(20) DEFAULT NULL COMMENT '手机号',
  `id_card` VARCHAR(20) DEFAULT NULL COMMENT '身份证号',
  `entry_date` DATE DEFAULT NULL COMMENT '入职日期',
  `stage` VARCHAR(20) DEFAULT 'intern' COMMENT '当前阶段: intern实习 probation转正 advanced进阶',
  `status` TINYINT DEFAULT 1 COMMENT '状态: 0离职 1在职 2休假',
  `remark` TEXT DEFAULT NULL COMMENT '备注',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_employee_no` (`employee_no`),
  KEY `idx_store` (`store_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_role` (`role`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='员工表';

-- ============================================
-- 第三部分：学习统计表（按月汇总）
-- ============================================

CREATE TABLE IF NOT EXISTS `monthly_statistics` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `staff_id` INT UNSIGNED NOT NULL COMMENT '员工ID',
  `year` SMALLINT NOT NULL COMMENT '统计年份',
  `month` TINYINT NOT NULL COMMENT '统计月份',
  `store_id` INT UNSIGNED NOT NULL COMMENT '门店ID(冗余)',
  `store_name` VARCHAR(100) DEFAULT NULL COMMENT '门店名称(冗余)',
  `staff_name` VARCHAR(50) DEFAULT NULL COMMENT '员工姓名(冗余)',
  `role` VARCHAR(20) DEFAULT NULL COMMENT '角色(冗余)',
  `stage` VARCHAR(20) DEFAULT NULL COMMENT '阶段(冗余)',
  -- 学习统计
  `courses_started` INT DEFAULT 0 COMMENT '开始课程数',
  `courses_completed` INT DEFAULT 0 COMMENT '完成课程数',
  `knowledge_cards_learned` INT DEFAULT 0 COMMENT '学习知识卡数',
  `knowledge_cards_completed` INT DEFAULT 0 COMMENT '完成知识卡数',
  `total_learning_time` INT DEFAULT 0 COMMENT '累计学习时长(分钟)',
  -- 演练统计
  `drills_started` INT DEFAULT 0 COMMENT '开始演练数',
  `drills_completed` INT DEFAULT 0 COMMENT '完成演练数',
  `drill_best_score` INT DEFAULT 0 COMMENT '演练最高分',
  -- 考核统计
  `exams_taken` INT DEFAULT 0 COMMENT '参加考试数',
  `exams_passed` INT DEFAULT 0 COMMENT '通过考试数',
  `exam_avg_score` DECIMAL(5,1) DEFAULT 0 COMMENT '考试平均分',
  -- 通关统计
  `stages_passed` INT DEFAULT 0 COMMENT '通关阶段数',
  `current_stage_id` INT UNSIGNED DEFAULT NULL COMMENT '当前阶段ID',
  `pass_rate` DECIMAL(5,2) DEFAULT 0 COMMENT '通关率(%)',
  -- 积分统计
  `points_earned` INT DEFAULT 0 COMMENT '获得积分',
  `points_spent` INT DEFAULT 0 COMMENT '消耗积分',
  `points_balance` INT DEFAULT 0 COMMENT '当前积分',
  -- 考勤统计
  `checkin_days` INT DEFAULT 0 COMMENT '签到天数',
  `checkin_consecutive` INT DEFAULT 0 COMMENT '连续签到天数',
  -- 其他
  `data_updated_at` DATETIME DEFAULT NULL COMMENT '数据更新时间',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_staff_year_month` (`staff_id`, `year`, `month`),
  KEY `idx_store` (`store_id`),
  KEY `idx_year_month` (`year`, `month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='月度学习统计表';

-- ============================================
-- 第四部分：初始化门店数据
-- ============================================

INSERT INTO `stores` (`name`, `code`, `province`, `city`, `address`, `manager_name`, `status`, `sort_order`) VALUES
('追光小牛成都旗舰店', 'CD001', '四川', '成都', '武侯区天府大道中段666号', '张明', 1, 1),
('追光小牛重庆解放碑店', 'CQ002', '重庆', '重庆', '渝中区解放碑步行街88号', '李华', 1, 2),
('追光小牛西安钟楼店', 'XA003', '陕西', '西安', '碑林区钟楼附近', '王芳', 1, 3);

-- ============================================
-- 第五部分：初始化员工数据
-- ============================================

INSERT INTO `staffs` (`store_id`, `employee_no`, `name`, `role`, `job_title`, `stage`, `entry_date`, `status`) VALUES
-- 成都店
(1, 'CD00101', '张三', 'manager', '店长', 'advanced', '2024-01-15', 1),
(1, 'CD00102', '李四', 'coach', '高级教练', 'probation', '2024-06-01', 1),
(1, 'CD00103', '王五', 'sales', '课程顾问', 'intern', '2025-01-10', 1),
(1, 'CD00104', '赵六', 'sales', '课程顾问', 'probation', '2024-09-01', 1),
-- 重庆店
(2, 'CQ00201', '孙琪', 'manager', '店长', 'advanced', '2023-11-20', 1),
(2, 'CQ00202', '周八', 'coach', '教练', 'intern', '2025-02-15', 1),
(2, 'CQ00203', '吴九', 'sales', '课程顾问', 'probation', '2024-08-01', 1),
-- 西安店
(3, 'XA00301', '郑十', 'manager', '店长', 'advanced', '2024-02-01', 1),
(3, 'XA00302', '刘十一', 'coach', '高级教练', 'probation', '2024-05-15', 1),
(3, 'XA00303', '陈十二', 'sales', '课程顾问', 'intern', '2025-03-01', 1);

-- ============================================
-- 第六部分：视图 - 门店统计概览
-- ============================================

CREATE OR REPLACE VIEW `v_store_statistics` AS
SELECT
  ms.store_id,
  ms.store_name,
  ms.year,
  ms.month,
  COUNT(DISTINCT ms.staff_id) as staff_count,
  SUM(ms.courses_completed) as total_courses_completed,
  SUM(ms.knowledge_cards_completed) as total_knowledge_completed,
  SUM(ms.drills_completed) as total_drills_completed,
  SUM(ms.exams_passed) as total_exams_passed,
  AVG(ms.pass_rate) as avg_pass_rate,
  SUM(ms.points_earned) as total_points_earned,
  SUM(ms.checkin_days) as total_checkin_days,
  AVG(ms.total_learning_time) as avg_learning_time
FROM monthly_statistics ms
GROUP BY ms.store_id, ms.store_name, ms.year, ms.month;

-- ============================================
-- 第七部分：视图 - 员工学习进度
-- ============================================

CREATE OR REPLACE VIEW `v_employee_learning_progress` AS
SELECT
  s.id as staff_id,
  s.employee_no,
  s.name as staff_name,
  s.role,
  s.stage,
  s.store_id,
  st.name as store_name,
  COALESCE(ms.courses_completed, 0) as courses_completed,
  COALESCE(ms.knowledge_cards_completed, 0) as knowledge_cards_completed,
  COALESCE(ms.drills_completed, 0) as drills_completed,
  COALESCE(ms.exam_avg_score, 0) as exam_avg_score,
  COALESCE(ms.pass_rate, 0) as pass_rate,
  COALESCE(ms.points_balance, 0) as points_balance,
  COALESCE(ms.checkin_consecutive, 0) as checkin_consecutive,
  s.entry_date,
  TIMESTAMPDIFF(MONTH, s.entry_date, NOW()) as working_months
FROM staffs s
LEFT JOIN stores st ON s.store_id = st.id
LEFT JOIN monthly_statistics ms ON s.id = ms.staff_id AND ms.year = YEAR(NOW()) AND ms.month = MONTH(NOW())
WHERE s.status = 1;