SET NAMES utf8mb4;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_resume_file_sources')
    AND NOT EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_resume_file_sources' AND COLUMN_NAME = 'container_original_name'),
    'ALTER TABLE recruitment_resume_file_sources ADD COLUMN container_original_name VARCHAR(255) NULL AFTER original_name',
    'SELECT 1'
);
PREPARE stmt FROM @migration_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_resume_file_sources')
    AND NOT EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_resume_file_sources' AND COLUMN_NAME = 'archive_relative_path'),
    'ALTER TABLE recruitment_resume_file_sources ADD COLUMN archive_relative_path VARCHAR(1024) NULL AFTER container_original_name',
    'SELECT 1'
);
PREPARE stmt FROM @migration_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_resume_file_sources')
    AND NOT EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_resume_file_sources' AND COLUMN_NAME = 'archive_entry_sha256'),
    'ALTER TABLE recruitment_resume_file_sources ADD COLUMN archive_entry_sha256 CHAR(64) NULL AFTER archive_relative_path',
    'SELECT 1'
);
PREPARE stmt FROM @migration_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_resume_file_sources')
    AND NOT EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_resume_file_sources' AND INDEX_NAME = 'idx_recruitment_file_sources_archive'),
    'ALTER TABLE recruitment_resume_file_sources ADD KEY idx_recruitment_file_sources_archive (batch_id, container_original_name(191), archive_relative_path(191))',
    'SELECT 1'
);
PREPARE stmt FROM @migration_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
