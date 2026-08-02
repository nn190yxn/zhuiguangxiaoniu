-- Version campaign and summer-camp topic tables previously created by HTTP endpoints.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS campaign_daily_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    entry_date DATE NOT NULL,
    store VARCHAR(50) NOT NULL,
    role_type VARCHAR(20) NOT NULL,
    person_name VARCHAR(50) NULL,
    new_members INT NOT NULL DEFAULT 0,
    renewal_members INT NOT NULL DEFAULT 0,
    trial_conversions INT NOT NULL DEFAULT 0,
    revenue DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    notes TEXT NULL,
    data_json LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_entry_date (entry_date),
    KEY idx_store (store)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'campaign_daily_entries' AND COLUMN_NAME = 'data_json'),
    'SELECT 1',
    'ALTER TABLE campaign_daily_entries ADD COLUMN data_json LONGTEXT NULL'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

CREATE TABLE IF NOT EXISTS campaign_channel_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    entry_date DATE NOT NULL,
    store VARCHAR(50) NOT NULL,
    channel VARCHAR(32) NOT NULL,
    count_val INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_campaign_channel_entry (entry_date, store, channel),
    KEY idx_campaign_channel_store (store, entry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS summer_camp_assessments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    camp_type VARCHAR(32) NOT NULL,
    student_name VARCHAR(100) NOT NULL,
    student_gender VARCHAR(10) NULL,
    student_grade VARCHAR(50) NULL,
    student_age INT NULL,
    student_height DECIMAL(10,2) NULL,
    student_weight DECIMAL(10,2) NULL,
    phone VARCHAR(20) NULL,
    coach_diagnosis TEXT NULL,
    staff_id BIGINT UNSIGNED NOT NULL,
    store_id BIGINT UNSIGNED NULL,
    assessment_date DATE NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_camp_type (camp_type),
    KEY idx_staff_id (staff_id),
    KEY idx_store_id (store_id),
    KEY idx_date (assessment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS summer_camp_test_data (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    assessment_id BIGINT UNSIGNED NOT NULL,
    metric_code VARCHAR(50) NOT NULL,
    metric_value DECIMAL(10,2) NULL,
    metric_text VARCHAR(100) NULL,
    rating VARCHAR(20) NULL,
    percentile INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_assessment_id (assessment_id),
    CONSTRAINT fk_summer_camp_test_assessment FOREIGN KEY (assessment_id) REFERENCES summer_camp_assessments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'summer_camp_test_data' AND COLUMN_NAME = 'metric_text'),
    'SELECT 1',
    'ALTER TABLE summer_camp_test_data ADD COLUMN metric_text VARCHAR(100) NULL'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

CREATE TABLE IF NOT EXISTS summer_camp_reports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    assessment_id BIGINT UNSIGNED NOT NULL,
    ai_content TEXT NULL,
    coach_remarks TEXT NULL,
    coach_name VARCHAR(100) NULL,
    coach_phone VARCHAR(20) NULL,
    coach_store VARCHAR(100) NULL,
    report_date DATE NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_assessment_id (assessment_id),
    CONSTRAINT fk_summer_camp_report_assessment FOREIGN KEY (assessment_id) REFERENCES summer_camp_assessments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
