SET NAMES utf8mb4;

SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lesson_versions' AND INDEX_NAME = 'uk_lesson_version_submission_pair'), 'SELECT 1', 'ALTER TABLE lesson_versions ADD UNIQUE KEY uk_lesson_version_submission_pair (submission_id, id)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'knowledge_item_versions' AND INDEX_NAME = 'uk_knowledge_item_version_pair'), 'SELECT 1', 'ALTER TABLE knowledge_item_versions ADD UNIQUE KEY uk_knowledge_item_version_pair (knowledge_item_id, version_id)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE lesson_suggestions MODIFY COLUMN knowledge_item_id INT UNSIGNED NULL;
SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lesson_suggestions' AND COLUMN_NAME = 'knowledge_version_id'), 'SELECT 1', 'ALTER TABLE lesson_suggestions ADD COLUMN knowledge_version_id BIGINT UNSIGNED NULL AFTER knowledge_item_id');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
UPDATE lesson_suggestions suggestion
JOIN knowledge_items item ON item.id = suggestion.knowledge_item_id
SET suggestion.knowledge_version_id = item.current_version_id
WHERE suggestion.source_type = 'knowledge_card' AND suggestion.knowledge_version_id IS NULL;
SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lesson_suggestions' AND INDEX_NAME = 'idx_lesson_suggestions_knowledge_version'), 'SELECT 1', 'ALTER TABLE lesson_suggestions ADD KEY idx_lesson_suggestions_knowledge_version (knowledge_item_id, knowledge_version_id)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_lesson_submissions_current_version_pair'), 'SELECT 1', 'ALTER TABLE lesson_submissions ADD CONSTRAINT fk_lesson_submissions_current_version_pair FOREIGN KEY (id, current_version_id) REFERENCES lesson_versions (submission_id, id) ON DELETE RESTRICT');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_lesson_submissions_approved_version_pair'), 'SELECT 1', 'ALTER TABLE lesson_submissions ADD CONSTRAINT fk_lesson_submissions_approved_version_pair FOREIGN KEY (id, approved_version_id) REFERENCES lesson_versions (submission_id, id) ON DELETE RESTRICT');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_lesson_suggestions_version_pair'), 'SELECT 1', 'ALTER TABLE lesson_suggestions ADD CONSTRAINT fk_lesson_suggestions_version_pair FOREIGN KEY (submission_id, version_id) REFERENCES lesson_versions (submission_id, id) ON DELETE RESTRICT');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_lesson_suggestions_knowledge_version_pair'), 'SELECT 1', 'ALTER TABLE lesson_suggestions ADD CONSTRAINT fk_lesson_suggestions_knowledge_version_pair FOREIGN KEY (knowledge_item_id, knowledge_version_id) REFERENCES knowledge_item_versions (knowledge_item_id, version_id) ON DELETE RESTRICT');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_lesson_review_tasks_version_pair'), 'SELECT 1', 'ALTER TABLE lesson_review_tasks ADD CONSTRAINT fk_lesson_review_tasks_version_pair FOREIGN KEY (submission_id, version_id) REFERENCES lesson_versions (submission_id, id) ON DELETE RESTRICT');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_lesson_exports_version_pair'), 'SELECT 1', 'ALTER TABLE lesson_exports ADD CONSTRAINT fk_lesson_exports_version_pair FOREIGN KEY (submission_id, version_id) REFERENCES lesson_versions (submission_id, id) ON DELETE RESTRICT');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_lesson_audit_logs_version_pair'), 'SELECT 1', 'ALTER TABLE lesson_audit_logs ADD CONSTRAINT fk_lesson_audit_logs_version_pair FOREIGN KEY (submission_id, version_id) REFERENCES lesson_versions (submission_id, id) ON DELETE RESTRICT');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
