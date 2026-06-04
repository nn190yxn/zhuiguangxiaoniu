-- 追光小牛线上知识库分类治理记录
-- 执行日期：2026-06-04
-- 备份文件：
--   /www/backup/knowledge_governance/knowledge_categories_items_20260604_005011_notablespaces.sql
-- 说明：
--   1. 本脚本记录本次线上治理意图，实际执行时因远程 shell 编码问题，最终分类中文修复使用了十六进制 UTF-8 写入。
--   2. 本次未删除知识内容，未删除分类，只调整分类名称、说明、排序，并移动少量条目归属。

START TRANSACTION;

INSERT INTO knowledge_categories (name, code, type, description, icon, sort_order, status) VALUES
('品牌课程与基础知识', 'brand_course_basics', 'knowledge_card', '品牌故事、HLGP理念、课程体系与儿童体适能基础知识', '课', 9, 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  type = VALUES(type),
  description = VALUES(description),
  icon = VALUES(icon),
  sort_order = VALUES(sort_order),
  status = VALUES(status);

UPDATE knowledge_categories
SET name = '销售接待知识',
    description = '销售七步曲、接待流程、消费力判断、体验课转化与专业表达',
    icon = '销',
    sort_order = 30,
    status = 1
WHERE code = 'card_sales';

UPDATE knowledge_categories
SET name = '教练教学方法',
    description = '儿童青少年体适能教学方法、动作观察、游戏化课程与教练专业成长',
    icon = '教',
    sort_order = 203,
    status = 1
WHERE code = 'subject_skill';

UPDATE knowledge_categories
SET name = '力量基础动作',
    description = '可直接用于课堂的力量类基础动作',
    icon = '力',
    sort_order = 10,
    status = 1
WHERE code = 'action_force';

UPDATE knowledge_categories
SET name = '力量训练方案',
    description = '力量训练教案、动作体系、周期设计与专项训练方案',
    icon = '方',
    sort_order = 101,
    status = 1
WHERE code = 'action_strength';

UPDATE knowledge_categories
SET name = '平衡协调基础动作',
    description = '可直接用于课堂的平衡协调类基础动作',
    icon = '平',
    sort_order = 13,
    status = 1
WHERE code = 'action_balance';

UPDATE knowledge_categories
SET name = '平衡协调训练方案',
    description = '平衡、协调、灵敏相关训练方案与机制说明',
    icon = '协',
    sort_order = 104,
    status = 1
WHERE code = 'action_balance_coordination';

UPDATE knowledge_items
SET category_id = (SELECT id FROM knowledge_categories WHERE code = 'brand_course_basics' LIMIT 1)
WHERE id IN (1, 3, 4, 24)
  AND status = 1;

UPDATE knowledge_items
SET category_id = (SELECT id FROM knowledge_categories WHERE code = 'card_sales' LIMIT 1)
WHERE id IN (6, 7, 12, 14, 19)
  AND status = 1;

UPDATE knowledge_items
SET category_id = (SELECT id FROM knowledge_categories WHERE code = 'professional_fitness' LIMIT 1)
WHERE id IN (16, 17)
  AND status = 1;

COMMIT;
