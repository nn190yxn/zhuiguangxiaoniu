SET NAMES utf8mb4;

SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'policies' AND COLUMN_NAME = 'publication_status'), 'SELECT 1', 'ALTER TABLE policies ADD COLUMN publication_status VARCHAR(16) NOT NULL DEFAULT ''published''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'policies' AND COLUMN_NAME = 'status'), 'SELECT 1', 'ALTER TABLE policies ADD COLUMN status TINYINT NOT NULL DEFAULT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'policies' AND COLUMN_NAME = 'target_roles'), 'SELECT 1', 'ALTER TABLE policies ADD COLUMN target_roles JSON NULL');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'policies' AND INDEX_NAME = 'idx_policies_publication'), 'SELECT 1', 'ALTER TABLE policies ADD KEY idx_policies_publication (publication_status, status, updated_at)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'knowledge_item_versions' AND INDEX_NAME = 'uk_knowledge_version_item_pair'), 'SELECT 1', 'ALTER TABLE knowledge_item_versions ADD UNIQUE KEY uk_knowledge_version_item_pair (version_id, knowledge_item_id)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'knowledge_items' AND INDEX_NAME = 'uk_knowledge_item_current_pair'), 'SELECT 1', 'ALTER TABLE knowledge_items ADD UNIQUE KEY uk_knowledge_item_current_pair (id, current_version_id)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'knowledge_items' AND CONSTRAINT_NAME = 'fk_knowledge_items_current_version'), 'ALTER TABLE knowledge_items DROP FOREIGN KEY fk_knowledge_items_current_version', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'knowledge_items' AND CONSTRAINT_NAME = 'fk_knowledge_items_current_version_pair'), 'SELECT 1', 'ALTER TABLE knowledge_items ADD CONSTRAINT fk_knowledge_items_current_version_pair FOREIGN KEY (current_version_id, id) REFERENCES knowledge_item_versions (version_id, knowledge_item_id) ON DELETE RESTRICT');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
