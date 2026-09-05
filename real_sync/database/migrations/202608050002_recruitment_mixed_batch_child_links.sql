SET NAMES utf8mb4;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_resume_batches')
    AND NOT EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_resume_batches' AND COLUMN_NAME = 'parent_batch_id'),
    'ALTER TABLE recruitment_resume_batches ADD COLUMN parent_batch_id BIGINT UNSIGNED NULL AFTER batch_mode, ADD KEY idx_recruitment_resume_batch_parent_requirement (parent_batch_id, requirement_id)',
    'SELECT 1'
);
PREPARE stmt FROM @migration_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
