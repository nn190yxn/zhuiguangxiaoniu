-- 追光小牛知识库治理脚本
-- 用途：补齐知识卡分类，并按标题/摘要关键词对现有知识卡做初步归类。
-- 注意：这是可选脚本，不会被小程序自动执行。上线前建议先备份 knowledge_categories / knowledge_items。

START TRANSACTION;

INSERT INTO knowledge_categories (name, code, type, description, icon, sort_order) VALUES
('体测评估', 'knowledge_assessment', 'knowledge_card', '体测、评估、身体素质、测评报告相关知识', '测', 201),
('感统训练', 'knowledge_sensory', 'knowledge_card', '感觉统合、专注力、前庭、本体、触觉相关知识', '感', 202),
('教学标准', 'knowledge_teaching', 'knowledge_card', '课程执行、课堂组织、教案、升班考核相关知识', '教', 203),
('家长沟通', 'knowledge_parent', 'knowledge_card', '家长沟通、反馈、续费、投诉处理相关知识', '沟', 204),
('门店运营', 'knowledge_operation', 'knowledge_card', '门店运营、安全、卫生、物料、流程标准相关知识', '店', 205),
('员工成长', 'knowledge_staff_growth', 'knowledge_card', '入职、培训、岗位、考核、晋升相关知识', '人', 206),
('品牌标准', 'knowledge_brand', 'knowledge_card', '品牌一致性、物料、线上运营相关知识', '品', 207),
('通用知识', 'knowledge_general', 'knowledge_card', '暂未归入专项分类的通用知识卡', '知', 299)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  type = VALUES(type),
  description = VALUES(description),
  icon = VALUES(icon),
  sort_order = VALUES(sort_order);

UPDATE knowledge_items k
JOIN knowledge_categories c ON c.code = 'knowledge_assessment'
LEFT JOIN knowledge_categories oldc ON oldc.id = k.category_id
SET k.category_id = c.id, k.subject = COALESCE(k.subject, 'fitness')
WHERE k.status = 1
  AND (oldc.type IS NULL OR oldc.type = 'knowledge_card')
  AND (
    k.title LIKE '%体测%' OR k.summary LIKE '%体测%' OR k.content LIKE '%体测%' OR
    k.title LIKE '%评估%' OR k.summary LIKE '%评估%' OR k.content LIKE '%评估%' OR
    k.title LIKE '%身体素质%' OR k.summary LIKE '%身体素质%' OR k.content LIKE '%身体素质%' OR
    k.title LIKE '%测评%' OR k.summary LIKE '%测评%' OR k.content LIKE '%测评%'
  );

UPDATE knowledge_items k
JOIN knowledge_categories c ON c.code = 'knowledge_sensory'
LEFT JOIN knowledge_categories oldc ON oldc.id = k.category_id
SET k.category_id = c.id, k.subject = COALESCE(k.subject, 'sensory')
WHERE k.status = 1
  AND (oldc.type IS NULL OR oldc.type = 'knowledge_card')
  AND (
    k.title LIKE '%感统%' OR k.summary LIKE '%感统%' OR k.content LIKE '%感统%' OR
    k.title LIKE '%感觉统合%' OR k.summary LIKE '%感觉统合%' OR k.content LIKE '%感觉统合%' OR
    k.title LIKE '%前庭%' OR k.summary LIKE '%前庭%' OR k.content LIKE '%前庭%' OR
    k.title LIKE '%本体%' OR k.summary LIKE '%本体%' OR k.content LIKE '%本体%' OR
    k.title LIKE '%触觉%' OR k.summary LIKE '%触觉%' OR k.content LIKE '%触觉%'
  );

UPDATE knowledge_items k
JOIN knowledge_categories c ON c.code = 'knowledge_teaching'
LEFT JOIN knowledge_categories oldc ON oldc.id = k.category_id
SET k.category_id = c.id
WHERE k.status = 1
  AND (oldc.type IS NULL OR oldc.type = 'knowledge_card')
  AND (
    k.title LIKE '%教学%' OR k.summary LIKE '%教学%' OR k.content LIKE '%教学%' OR
    k.title LIKE '%课程%' OR k.summary LIKE '%课程%' OR k.content LIKE '%课程%' OR
    k.title LIKE '%课堂%' OR k.summary LIKE '%课堂%' OR k.content LIKE '%课堂%' OR
    k.title LIKE '%教案%' OR k.summary LIKE '%教案%' OR k.content LIKE '%教案%' OR
    k.title LIKE '%升班%' OR k.summary LIKE '%升班%' OR k.content LIKE '%升班%'
  );

UPDATE knowledge_items k
JOIN knowledge_categories c ON c.code = 'knowledge_parent'
LEFT JOIN knowledge_categories oldc ON oldc.id = k.category_id
SET k.category_id = c.id
WHERE k.status = 1
  AND (oldc.type IS NULL OR oldc.type = 'knowledge_card')
  AND (
    k.title LIKE '%家长%' OR k.summary LIKE '%家长%' OR k.content LIKE '%家长%' OR
    k.title LIKE '%沟通%' OR k.summary LIKE '%沟通%' OR k.content LIKE '%沟通%' OR
    k.title LIKE '%反馈%' OR k.summary LIKE '%反馈%' OR k.content LIKE '%反馈%' OR
    k.title LIKE '%续费%' OR k.summary LIKE '%续费%' OR k.content LIKE '%续费%' OR
    k.title LIKE '%投诉%' OR k.summary LIKE '%投诉%' OR k.content LIKE '%投诉%'
  );

UPDATE knowledge_items k
JOIN knowledge_categories c ON c.code = 'knowledge_operation'
LEFT JOIN knowledge_categories oldc ON oldc.id = k.category_id
SET k.category_id = c.id
WHERE k.status = 1
  AND (oldc.type IS NULL OR oldc.type = 'knowledge_card')
  AND (
    k.title LIKE '%门店%' OR k.summary LIKE '%门店%' OR k.content LIKE '%门店%' OR
    k.title LIKE '%运营%' OR k.summary LIKE '%运营%' OR k.content LIKE '%运营%' OR
    k.title LIKE '%安全%' OR k.summary LIKE '%安全%' OR k.content LIKE '%安全%' OR
    k.title LIKE '%卫生%' OR k.summary LIKE '%卫生%' OR k.content LIKE '%卫生%' OR
    k.title LIKE '%物料%' OR k.summary LIKE '%物料%' OR k.content LIKE '%物料%'
  );

UPDATE knowledge_items k
JOIN knowledge_categories c ON c.code = 'knowledge_staff_growth'
LEFT JOIN knowledge_categories oldc ON oldc.id = k.category_id
SET k.category_id = c.id
WHERE k.status = 1
  AND (oldc.type IS NULL OR oldc.type = 'knowledge_card')
  AND (
    k.title LIKE '%员工%' OR k.summary LIKE '%员工%' OR k.content LIKE '%员工%' OR
    k.title LIKE '%入职%' OR k.summary LIKE '%入职%' OR k.content LIKE '%入职%' OR
    k.title LIKE '%培训%' OR k.summary LIKE '%培训%' OR k.content LIKE '%培训%' OR
    k.title LIKE '%岗位%' OR k.summary LIKE '%岗位%' OR k.content LIKE '%岗位%' OR
    k.title LIKE '%晋升%' OR k.summary LIKE '%晋升%' OR k.content LIKE '%晋升%'
  );

UPDATE knowledge_items k
JOIN knowledge_categories c ON c.code = 'knowledge_brand'
LEFT JOIN knowledge_categories oldc ON oldc.id = k.category_id
SET k.category_id = c.id
WHERE k.status = 1
  AND (oldc.type IS NULL OR oldc.type = 'knowledge_card')
  AND (
    k.title LIKE '%品牌%' OR k.summary LIKE '%品牌%' OR k.content LIKE '%品牌%' OR
    k.title LIKE '%线上%' OR k.summary LIKE '%线上%' OR k.content LIKE '%线上%' OR
    k.title LIKE '%素材%' OR k.summary LIKE '%素材%' OR k.content LIKE '%素材%'
  );

UPDATE knowledge_items k
JOIN knowledge_categories c ON c.code = 'knowledge_general'
LEFT JOIN knowledge_categories oldc ON oldc.id = k.category_id
SET k.category_id = c.id
WHERE k.status = 1
  AND (
    k.category_id IS NULL
    OR oldc.id IS NULL
    OR oldc.code IN ('knowledge', 'knowledge_card', 'subject_standards')
  );

COMMIT;
