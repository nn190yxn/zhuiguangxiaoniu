SET NAMES utf8mb4;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_resume_batches')
    AND NOT EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_resume_batches' AND COLUMN_NAME = 'batch_mode'),
    'ALTER TABLE recruitment_resume_batches ADD COLUMN batch_mode ENUM(''single'', ''mixed'') NOT NULL DEFAULT ''single'' AFTER batch_no',
    'SELECT 1'
);
PREPARE stmt FROM @migration_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_resume_batches' AND COLUMN_NAME = 'requirement_id' AND IS_NULLABLE = 'NO'),
    'ALTER TABLE recruitment_resume_batches MODIFY COLUMN requirement_id BIGINT UNSIGNED NULL',
    'SELECT 1'
);
PREPARE stmt FROM @migration_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_resume_batches' AND COLUMN_NAME = 'rule_version_id' AND IS_NULLABLE = 'NO'),
    'ALTER TABLE recruitment_resume_batches MODIFY COLUMN rule_version_id BIGINT UNSIGNED NULL',
    'SELECT 1'
);
PREPARE stmt FROM @migration_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_processing_versions' AND COLUMN_NAME = 'requirement_id' AND IS_NULLABLE = 'NO'),
    'ALTER TABLE recruitment_processing_versions MODIFY COLUMN requirement_id BIGINT UNSIGNED NULL',
    'SELECT 1'
);
PREPARE stmt FROM @migration_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_processing_versions' AND COLUMN_NAME = 'rule_version_id' AND IS_NULLABLE = 'NO'),
    'ALTER TABLE recruitment_processing_versions MODIFY COLUMN rule_version_id BIGINT UNSIGNED NULL',
    'SELECT 1'
);
PREPARE stmt FROM @migration_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_processing_versions')
    AND NOT EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_processing_versions' AND COLUMN_NAME = 'position_routing_status'),
    'ALTER TABLE recruitment_processing_versions ADD COLUMN position_routing_status ENUM(''not_required'', ''pending'', ''routed'', ''unresolved'') NOT NULL DEFAULT ''not_required''',
    'SELECT 1'
);
PREPARE stmt FROM @migration_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_processing_versions')
    AND NOT EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_processing_versions' AND COLUMN_NAME = 'position_routing_summary_json'),
    'ALTER TABLE recruitment_processing_versions ADD COLUMN position_routing_summary_json LONGTEXT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @migration_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_resume_batches' AND COLUMN_NAME = 'batch_mode'),
    'UPDATE recruitment_resume_batches SET batch_mode = ''single'' WHERE batch_mode IS NULL OR batch_mode = ''''',
    'SELECT 1'
);
PREPARE stmt FROM @migration_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
