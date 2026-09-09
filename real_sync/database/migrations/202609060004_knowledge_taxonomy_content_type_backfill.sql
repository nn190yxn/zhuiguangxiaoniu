-- 202609060004 按生产 content_type 补齐二期知识卡主题分类
SET NAMES utf8mb4;

INSERT INTO knowledge_audit_logs
    (batch_id, actor_user_id, actor_staff_id, action, target_type, target_id, before_json, after_json, metadata_json)
SELECT k.source_batch_id, NULL, NULL, 'taxonomy_confirmed', 'knowledge_item', CAST(k.id AS CHAR),
       JSON_OBJECT('category_code', old.code),
       JSON_OBJECT('category_code', target.code, 'mapping_version', 'taxonomy-2026-09-04-v1'),
       JSON_OBJECT('reason', '按生产 content_type 与已发布 taxonomy 映射确认主专业主题', 'source_domain_code', k.domain_code, 'content_type', k.content_type)
FROM knowledge_items k
INNER JOIN knowledge_categories old ON old.id = k.category_id AND old.code = 'phase2_import'
INNER JOIN knowledge_categories target ON target.code = CASE k.content_type
    WHEN 'action' THEN 'professional_action_game'
    WHEN 'game' THEN 'professional_action_game'
    WHEN 'coach_growth' THEN 'professional_coach_growth'
    WHEN 'training_plan' THEN 'professional_teaching'
    WHEN 'teaching_knowledge' THEN 'professional_teaching'
    WHEN 'teaching_organization' THEN 'professional_teaching'
    WHEN 'safety' THEN 'professional_safety'
    WHEN 'assessment' THEN 'professional_assessment'
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
INNER JOIN knowledge_categories target ON target.code = CASE k.content_type
    WHEN 'action' THEN 'professional_action_game'
    WHEN 'game' THEN 'professional_action_game'
    WHEN 'coach_growth' THEN 'professional_coach_growth'
    WHEN 'training_plan' THEN 'professional_teaching'
    WHEN 'teaching_knowledge' THEN 'professional_teaching'
    WHEN 'teaching_organization' THEN 'professional_teaching'
    WHEN 'safety' THEN 'professional_safety'
    WHEN 'assessment' THEN 'professional_assessment'
    ELSE ''
END
SET k.category_id = target.id;
