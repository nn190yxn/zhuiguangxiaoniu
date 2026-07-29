-- Controlled AI call metadata for sales-drill customer turns and evaluations.
SET @migration_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_evaluations' AND COLUMN_NAME = 'provider'), 'SELECT 1', 'ALTER TABLE drill_evaluations ADD COLUMN provider VARCHAR(64) NULL AFTER suggestions_json');
PREPARE migration_statement FROM @migration_sql; EXECUTE migration_statement; DEALLOCATE PREPARE migration_statement;
SET @migration_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_evaluations' AND COLUMN_NAME = 'model'), 'SELECT 1', 'ALTER TABLE drill_evaluations ADD COLUMN model VARCHAR(128) NULL AFTER provider');
PREPARE migration_statement FROM @migration_sql; EXECUTE migration_statement; DEALLOCATE PREPARE migration_statement;
SET @migration_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_evaluations' AND COLUMN_NAME = 'prompt_version'), 'SELECT 1', 'ALTER TABLE drill_evaluations ADD COLUMN prompt_version VARCHAR(64) NULL AFTER model');
PREPARE migration_statement FROM @migration_sql; EXECUTE migration_statement; DEALLOCATE PREPARE migration_statement;
SET @migration_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_evaluations' AND COLUMN_NAME = 'duration_ms'), 'SELECT 1', 'ALTER TABLE drill_evaluations ADD COLUMN duration_ms INT UNSIGNED NULL AFTER prompt_version');
PREPARE migration_statement FROM @migration_sql; EXECUTE migration_statement; DEALLOCATE PREPARE migration_statement;
