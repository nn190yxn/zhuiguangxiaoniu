-- ============================================
-- 知识库扩展字段 - 用于增强筛选功能
-- 添加科目、年龄段、训练类别字段
-- 执行时间: 2026-04-27
-- ============================================

-- 为 knowledge_items 表添加扩展字段
ALTER TABLE `knowledge_items`
ADD COLUMN `subject` VARCHAR(50) DEFAULT NULL COMMENT '科目: fitness体能 sensory感统 skill技能' AFTER `media_type`,
ADD COLUMN `age_group` VARCHAR(50) DEFAULT NULL COMMENT '适应年龄段: 3-6 7-12 13-18 成人' AFTER `subject`,
ADD COLUMN `training_type` VARCHAR(50) DEFAULT NULL COMMENT '训练类别: strength力量 cardio心肺 flexibility柔韧 balance平衡 coordination协调' AFTER `age_group`;

-- 添加索引以提高筛选查询性能
ALTER TABLE `knowledge_items`
ADD INDEX `idx_subject` (`subject`),
ADD INDEX `idx_age_group` (`age_group`),
ADD INDEX `idx_training_type` (`training_type`);

-- ============================================
-- 初始化数据：知识库分类扩展
-- ============================================

-- 更新现有分类，添加 type 字段的详细分类
-- 动作库分类（按训练类别）
INSERT INTO `knowledge_categories` (`name`, `code`, `type`, `description`, `icon`, `sort_order`) VALUES
-- 力量训练
('力量训练', 'action_strength', 'action', '力量训练动作库', '💪', 101),
-- 心肺训练
('心肺训练', 'action_cardio', 'action', '心肺功能训练动作', '❤️', 102),
-- 柔韧性训练
('柔韧性训练', 'action_flexibility', 'action', '拉伸和柔韧性训练', '🧘', 103),
-- 平衡协调训练
('平衡协调训练', 'action_balance_coordination', 'action', '平衡和协调性训练', '⚖️', 104)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- 通用知识分类（按科目）
INSERT INTO `knowledge_categories` (`name`, `code`, `type`, `description`, `icon`, `sort_order`) VALUES
-- 体能
('体测七大身体素质', 'subject_fitness', 'knowledge_card', '体测评估相关专业知识', '🏃', 201),
-- 感统
('感统知识', 'subject_sensory', 'knowledge_card', '感觉统合训练知识', '🧠', 202),
-- 技能
('技能知识', 'subject_skill', 'knowledge_card', '专项技能知识', '🎯', 203),
-- 工作标准
('工作标准', 'subject_standards', 'knowledge_card', '服务标准、操作规范、安全规范', '📋', 204)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- 话术库分类（保留现有，可按场景再分）
-- 销售话术
INSERT INTO `knowledge_categories` (`name`, `code`, `type`, `description`, `icon`, `sort_order`) VALUES
('课程销售话术', 'script_course_sales', 'script', '课程产品销售话术', '💰', 301),
('体验课销售话术', 'script_trial_sales', 'script', '体验课邀约和转化话术', '🎁', 302),
('服务话术', 'script_service', 'script', '客户服务话术', '🤝', 303),
('带教话术', 'script_coaching', 'script', '教练带教话术', '👨‍🏫', 304)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- ============================================
-- 初始化数据：年龄段枚举值说明
-- ============================================
-- 3-6岁: 幼儿阶段
-- 7-12岁: 少儿阶段
-- 13-18岁: 青少年阶段
-- 成人: 18岁以上成人

-- ============================================
-- 示例：更新现有知识库内容的扩展字段
-- ============================================

-- 假设需要更新ID为1的知识条目的扩展字段：
-- UPDATE knowledge_items SET
--   subject = 'fitness',
--   age_group = '7-12',
--   training_type = 'strength'
-- WHERE id = 1;