-- 202609060003 二期知识卡主题分类确认（按已发布领域映射落库，保留审计记录）
SET NAMES utf8mb4;

INSERT IGNORE INTO knowledge_categories (name, code, type, description, sort_order, status) VALUES
    ('儿童发展', 'professional_child_development', 'knowledge_card', '儿童发展主题知识卡', 10, 1),
    ('运动与体能', 'professional_fitness', 'knowledge_card', '运动与体能主题知识卡', 20, 1),
    ('感统', 'professional_sensory', 'knowledge_card', '感统主题知识卡', 30, 1),
    ('动作与游戏', 'professional_action_game', 'knowledge_card', '动作与游戏主题知识卡', 40, 1),
    ('教学法', 'professional_teaching', 'knowledge_card', '教学法主题知识卡', 50, 1),
    ('体测与评估', 'professional_assessment', 'knowledge_card', '体测与评估主题知识卡', 60, 1),
    ('安全', 'professional_safety', 'knowledge_card', '安全主题知识卡', 70, 1),
    ('教练成长', 'professional_coach_growth', 'knowledge_card', '教练成长主题知识卡', 80, 1);

INSERT INTO knowledge_audit_logs
    (batch_id, actor_user_id, actor_staff_id, action, target_type, target_id, before_json, after_json, metadata_json)
SELECT k.source_batch_id, NULL, NULL, 'taxonomy_confirmed', 'knowledge_item', CAST(k.id AS CHAR),
       JSON_OBJECT('category_code', old.code),
       JSON_OBJECT('category_code', target.code, 'mapping_version', 'taxonomy-2026-09-04-v1'),
        JSON_OBJECT('reason', '按已发布 knowledge_taxonomy_mapping.v1 的 domain_code 确认主专业主题', 'source_domain_code', k.domain_code, 'content_type', k.content_type)
FROM knowledge_items k
INNER JOIN knowledge_categories old ON old.id = k.category_id AND old.code = 'phase2_import'
INNER JOIN knowledge_categories target ON target.code = CASE k.domain_code
    WHEN 'child_development' THEN 'professional_child_development'
    WHEN 'sensory_integration' THEN 'professional_sensory'
    WHEN 'physical_qualities' THEN 'professional_fitness'
    WHEN 'course_skills' THEN 'professional_action_game'
    WHEN 'assessment' THEN 'professional_assessment'
    WHEN 'ace_teaching' THEN 'professional_teaching'
    WHEN 'teaching_practice' THEN 'professional_teaching'
    WHEN 'safety_first_aid' THEN 'professional_safety'
    ELSE ''
END
WHERE NOT EXISTS (
    SELECT 1 FROM knowledge_audit_logs existing
    WHERE BINARY existing.action = BINARY 'taxonomy_confirmed'
      AND BINARY existing.target_type = BINARY 'knowledge_item'
      AND BINARY existing.target_id = BINARY CAST(k.id AS CHAR)
      AND BINARY JSON_UNQUOTE(JSON_EXTRACT(existing.after_json, '$.mapping_version')) = BINARY 'taxonomy-2026-09-04-v1'
);

UPDATE knowledge_items k
INNER JOIN knowledge_categories old ON old.id = k.category_id AND old.code = 'phase2_import'
INNER JOIN knowledge_categories target ON target.code = CASE k.domain_code
    WHEN 'child_development' THEN 'professional_child_development'
    WHEN 'sensory_integration' THEN 'professional_sensory'
    WHEN 'physical_qualities' THEN 'professional_fitness'
    WHEN 'course_skills' THEN 'professional_action_game'
    WHEN 'assessment' THEN 'professional_assessment'
    WHEN 'ace_teaching' THEN 'professional_teaching'
    WHEN 'teaching_practice' THEN 'professional_teaching'
    WHEN 'safety_first_aid' THEN 'professional_safety'
    ELSE ''
END
SET k.category_id = target.id;
