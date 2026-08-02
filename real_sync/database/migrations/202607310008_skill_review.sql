-- Version AI settings and legacy skill review records used by HTTP and Cron paths.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ai_settings (
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT NULL,
    description VARCHAR(255) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS skill_review_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    staff_id BIGINT UNSIGNED NOT NULL,
    scene_type VARCHAR(32) NOT NULL,
    recording_url VARCHAR(500) NOT NULL,
    transcript_text LONGTEXT NULL,
    ai_report LONGTEXT NULL,
    ai_score DECIMAL(6,2) NULL,
    ai_level VARCHAR(64) NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'pending',
    error_message VARCHAR(500) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_skill_review_user_created (user_id, created_at),
    KEY idx_skill_review_staff_created (staff_id, created_at),
    KEY idx_skill_review_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
