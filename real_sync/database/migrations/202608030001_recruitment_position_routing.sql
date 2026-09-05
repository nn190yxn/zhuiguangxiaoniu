SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS recruitment_position_route_results (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    processing_version_id BIGINT UNSIGNED NOT NULL,
    document_id BIGINT UNSIGNED NOT NULL,
    candidate_id BIGINT UNSIGNED NULL,
    requirement_id BIGINT UNSIGNED NULL,
    rule_version_id BIGINT UNSIGNED NOT NULL,
    position_id BIGINT UNSIGNED NULL,
    position_name_snapshot VARCHAR(120) NOT NULL,
    rank_no INT UNSIGNED NOT NULL,
    confidence DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
    match_score DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    reason_summary VARCHAR(1000) NOT NULL DEFAULT '',
    signal_sources_json LONGTEXT NULL,
    evidence_json LONGTEXT NULL,
    status ENUM('active', 'dismissed', 'confirmed') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_recruitment_route_processing_rank (processing_version_id, rank_no),
    KEY idx_recruitment_route_document_rank (document_id, rank_no),
    KEY idx_recruitment_route_candidate (candidate_id, status),
    KEY idx_recruitment_route_requirement (requirement_id, rank_no),
    KEY idx_recruitment_route_rule (rule_version_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recruitment_position_route_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id BIGINT UNSIGNED NOT NULL,
    event_type ENUM('confirm', 'adjust', 'add_pool', 'remove_pool') NOT NULL,
    before_route_id BIGINT UNSIGNED NULL,
    after_route_id BIGINT UNSIGNED NULL,
    event_reason VARCHAR(1000) NOT NULL DEFAULT '',
    operator_staff_id BIGINT UNSIGNED NULL,
    operated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_recruitment_route_events_application (application_id, operated_at),
    KEY idx_recruitment_route_events_after_route (after_route_id),
    KEY idx_recruitment_route_events_operator (operator_staff_id, operated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_applications')
    AND NOT EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_applications' AND COLUMN_NAME = 'position_confirmation_status'),
    'ALTER TABLE recruitment_applications ADD COLUMN position_confirmation_status ENUM(''pending'', ''confirmed'', ''adjusted'', ''unresolved'') NOT NULL DEFAULT ''pending''',
    'SELECT 1'
);
PREPARE stmt FROM @migration_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_applications')
    AND NOT EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_applications' AND COLUMN_NAME = 'recommended_route_id'),
    'ALTER TABLE recruitment_applications ADD COLUMN recommended_route_id BIGINT UNSIGNED NULL',
    'SELECT 1'
);
PREPARE stmt FROM @migration_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_applications')
    AND NOT EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_applications' AND COLUMN_NAME = 'confirmed_route_id'),
    'ALTER TABLE recruitment_applications ADD COLUMN confirmed_route_id BIGINT UNSIGNED NULL',
    'SELECT 1'
);
PREPARE stmt FROM @migration_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_applications')
    AND NOT EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_applications' AND COLUMN_NAME = 'position_adjustment_reason'),
    'ALTER TABLE recruitment_applications ADD COLUMN position_adjustment_reason VARCHAR(1000) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @migration_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_applications' AND COLUMN_NAME = 'position_confirmation_status'),
    'UPDATE recruitment_applications SET position_confirmation_status = ''confirmed'' WHERE position_confirmation_status = ''pending'' AND recommended_route_id IS NULL AND confirmed_route_id IS NULL',
    'SELECT 1'
);
PREPARE stmt FROM @migration_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_applications')
    AND NOT EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_applications' AND INDEX_NAME = 'idx_recruitment_applications_position_confirmation'),
    'ALTER TABLE recruitment_applications ADD KEY idx_recruitment_applications_position_confirmation (position_confirmation_status, requirement_id)',
    'SELECT 1'
);
PREPARE stmt FROM @migration_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_applications')
    AND NOT EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_applications' AND INDEX_NAME = 'idx_recruitment_applications_recommended_route'),
    'ALTER TABLE recruitment_applications ADD KEY idx_recruitment_applications_recommended_route (recommended_route_id)',
    'SELECT 1'
);
PREPARE stmt FROM @migration_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_applications')
    AND NOT EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_applications' AND INDEX_NAME = 'idx_recruitment_applications_confirmed_route'),
    'ALTER TABLE recruitment_applications ADD KEY idx_recruitment_applications_confirmed_route (confirmed_route_id)',
    'SELECT 1'
);
PREPARE stmt FROM @migration_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
