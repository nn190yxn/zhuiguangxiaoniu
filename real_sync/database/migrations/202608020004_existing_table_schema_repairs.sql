-- Complete additive schema for tables that existed before platform migrations.

SET NAMES utf8mb4;

SET @migration_sql = IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_audit_logs' AND INDEX_NAME = 'idx_device_created'), 'SELECT 1', 'ALTER TABLE login_audit_logs ADD KEY idx_device_created (device_fingerprint, created_at)');
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_audit_logs' AND INDEX_NAME = 'idx_risk_created'), 'SELECT 1', 'ALTER TABLE login_audit_logs ADD KEY idx_risk_created (risk_level, created_at)');
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'device_logins' AND INDEX_NAME = 'idx_staff_device'), 'SELECT 1', 'ALTER TABLE device_logins ADD KEY idx_staff_device (staff_id, device_fingerprint)');
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'skill_review_records' AND INDEX_NAME = 'idx_skill_review_user_created'), 'SELECT 1', 'ALTER TABLE skill_review_records ADD KEY idx_skill_review_user_created (user_id, created_at)');
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'skill_review_records' AND INDEX_NAME = 'idx_skill_review_staff_created'), 'SELECT 1', 'ALTER TABLE skill_review_records ADD KEY idx_skill_review_staff_created (staff_id, created_at)');
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'skill_review_records' AND INDEX_NAME = 'idx_skill_review_status_created'), 'SELECT 1', 'ALTER TABLE skill_review_records ADD KEY idx_skill_review_status_created (status, created_at)');
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'campaign_daily_entries' AND COLUMN_NAME = 'new_members'), 'SELECT 1', 'ALTER TABLE campaign_daily_entries ADD COLUMN new_members INT NOT NULL DEFAULT 0');
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'campaign_daily_entries' AND COLUMN_NAME = 'renewal_members'), 'SELECT 1', 'ALTER TABLE campaign_daily_entries ADD COLUMN renewal_members INT NOT NULL DEFAULT 0');
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'campaign_daily_entries' AND COLUMN_NAME = 'trial_conversions'), 'SELECT 1', 'ALTER TABLE campaign_daily_entries ADD COLUMN trial_conversions INT NOT NULL DEFAULT 0');
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'campaign_daily_entries' AND COLUMN_NAME = 'revenue'), 'SELECT 1', 'ALTER TABLE campaign_daily_entries ADD COLUMN revenue DECIMAL(10,2) NOT NULL DEFAULT 0.00');
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'campaign_daily_entries' AND COLUMN_NAME = 'notes'), 'SELECT 1', 'ALTER TABLE campaign_daily_entries ADD COLUMN notes TEXT NULL');
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'campaign_daily_entries' AND INDEX_NAME = 'idx_entry_date'), 'SELECT 1', 'ALTER TABLE campaign_daily_entries ADD KEY idx_entry_date (entry_date)');
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'campaign_daily_entries' AND INDEX_NAME = 'idx_store'), 'SELECT 1', 'ALTER TABLE campaign_daily_entries ADD KEY idx_store (store)');
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'campaign_channel_entries' AND INDEX_NAME = 'uq_campaign_channel_entry'), 'SELECT 1', 'ALTER TABLE campaign_channel_entries ADD UNIQUE KEY uq_campaign_channel_entry (entry_date, store, channel)');
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'campaign_channel_entries' AND INDEX_NAME = 'idx_campaign_channel_store'), 'SELECT 1', 'ALTER TABLE campaign_channel_entries ADD KEY idx_campaign_channel_store (store, entry_date)');
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;
