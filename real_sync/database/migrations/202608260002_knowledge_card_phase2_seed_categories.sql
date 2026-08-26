-- 202608260002 二期知识卡导入分类种子（幂等，无破坏性操作）
-- 说明：knowledge_items.category_id 为非空外键，二期 1417 张隔离卡在人工按 8 大专业领域
-- 归类前统一挂到此过渡分类；发布/归类由后续运营后台任务完成。
SET NAMES utf8mb4;

INSERT IGNORE INTO `knowledge_categories`
    (`name`, `code`, `type`, `description`, `sort_order`, `status`)
VALUES
    ('二期知识卡（待归类）', 'phase2_import', 'knowledge_card',
     '二期1417张教练与训练知识卡隔离导入时的过渡分类；待按8大专业领域人工归类后调整。',
     90, 1);
