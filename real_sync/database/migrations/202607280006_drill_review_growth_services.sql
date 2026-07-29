-- Review, coaching, certification, and growth service snapshots.
SET @migration_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_review_tasks' AND COLUMN_NAME = 'review_snapshot_json'), 'SELECT 1', 'ALTER TABLE drill_review_tasks ADD COLUMN review_snapshot_json JSON NULL AFTER adjustment_reason');
PREPARE migration_statement FROM @migration_sql; EXECUTE migration_statement; DEALLOCATE PREPARE migration_statement;
SET @migration_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_coaching_tasks' AND COLUMN_NAME = 'coaching_record_json'), 'SELECT 1', 'ALTER TABLE drill_coaching_tasks ADD COLUMN coaching_record_json JSON NULL AFTER notes');
PREPARE migration_statement FROM @migration_sql; EXECUTE migration_statement; DEALLOCATE PREPARE migration_statement;
SET @migration_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_certifications' AND COLUMN_NAME = 'ai_snapshot_json'), 'SELECT 1', 'ALTER TABLE drill_certifications ADD COLUMN ai_snapshot_json JSON NULL AFTER result_snapshot_json');
PREPARE migration_statement FROM @migration_sql; EXECUTE migration_statement; DEALLOCATE PREPARE migration_statement;
SET @migration_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_certifications' AND COLUMN_NAME = 'manual_adjustment_json'), 'SELECT 1', 'ALTER TABLE drill_certifications ADD COLUMN manual_adjustment_json JSON NULL AFTER ai_snapshot_json');
PREPARE migration_statement FROM @migration_sql; EXECUTE migration_statement; DEALLOCATE PREPARE migration_statement;
SET @migration_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_certifications' AND COLUMN_NAME = 'final_snapshot_json'), 'SELECT 1', 'ALTER TABLE drill_certifications ADD COLUMN final_snapshot_json JSON NULL AFTER manual_adjustment_json');
PREPARE migration_statement FROM @migration_sql; EXECUTE migration_statement; DEALLOCATE PREPARE migration_statement;
