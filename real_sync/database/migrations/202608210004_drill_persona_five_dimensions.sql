-- Seed the five persona dimensions used by free sales drills.
INSERT INTO `drill_persona_dimensions`
    (`domain_id`, `dimension_code`, `dimension_name`, `value_code`, `name`, `description`, `sort_order`, `status`, `source_type`, `source_ref`)
SELECT
    domain_row.`id`,
    seed.`dimension_code`,
    CASE seed.`dimension_code`
        WHEN 'age_band' THEN '孩子年龄'
        WHEN 'primary_need' THEN '核心诉求'
        WHEN 'communication_style' THEN '沟通风格'
        WHEN 'current_status' THEN '当前状态'
        WHEN 'course_tag' THEN '课程类型'
    END,
    seed.`value_code`,
    seed.`value_label`,
    seed.`description`,
    seed.`sort_order`,
    'active',
    'content_package',
    'sales-drill-persona-v1'
FROM `drill_training_domains` AS domain_row
JOIN (
    SELECT 'age_band' AS `dimension_code`, 'preschool' AS `value_code`, '学龄前' AS `value_label`, '孩子尚未进入小学阶段。' AS `description`, 100 AS `sort_order`
    UNION ALL SELECT 'age_band', 'primary', '小学阶段', '孩子处于小学阶段。', 110
    UNION ALL SELECT 'age_band', 'middle_school', '初中阶段', '孩子处于初中阶段。', 120
    UNION ALL SELECT 'age_band', 'high_school', '高中阶段', '孩子处于高中阶段。', 130
    UNION ALL SELECT 'primary_need', 'fitness', '体能提升', '重点关注体能、耐力和运动习惯。', 200
    UNION ALL SELECT 'primary_need', 'height', '身高促进', '重点关注身高发育和科学运动。', 210
    UNION ALL SELECT 'primary_need', 'confidence', '自信培养', '重点关注自信、表达和参与意愿。', 220
    UNION ALL SELECT 'primary_need', 'exam', '升学备考', '重点关注体育考试和阶段性成绩。', 230
    UNION ALL SELECT 'communication_style', 'rational', '理性分析型', '重视事实、方案依据和投入产出。', 300
    UNION ALL SELECT 'communication_style', 'direct', '直接高效型', '关注结论、效率和明确行动。', 310
    UNION ALL SELECT 'communication_style', 'cautious', '谨慎观望型', '需要充分信息和风险说明后再决策。', 320
    UNION ALL SELECT 'communication_style', 'emotional', '情感共鸣型', '重视孩子感受、服务温度和信任关系。', 330
    UNION ALL SELECT 'current_status', 'first_contact', '首次了解', '首次接触课程，正在建立基本认知。', 400
    UNION ALL SELECT 'current_status', 'comparing', '对比选择', '正在比较多个机构或课程方案。', 410
    UNION ALL SELECT 'current_status', 'experienced', '体验后评估', '已参加体验，正在评估效果和服务。', 420
    UNION ALL SELECT 'current_status', 'renewal', '续费考虑', '已有训练经历，正在考虑续费或升级。', 430
    UNION ALL SELECT 'course_tag', 'fitness', '体适能课程', '适合综合体能和运动基础训练。', 500
    UNION ALL SELECT 'course_tag', 'height', '身高促进课程', '适合围绕生长发育目标制定训练方案。', 510
    UNION ALL SELECT 'course_tag', 'exam', '体育升学课程', '适合体育考试和专项成绩提升。', 520
) AS seed ON 1 = 1
WHERE domain_row.`domain_code` = 'new_signing'
ON DUPLICATE KEY UPDATE
    `dimension_name` = VALUES(`dimension_name`),
    `name` = VALUES(`name`),
    `description` = VALUES(`description`),
    `sort_order` = VALUES(`sort_order`),
    `status` = VALUES(`status`),
    `source_type` = VALUES(`source_type`),
    `source_ref` = VALUES(`source_ref`);
