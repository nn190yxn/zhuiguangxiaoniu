-- Separate lesson reads from completion writes and track optimistic state.
SET NAMES utf8mb4;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_course_progress' AND COLUMN_NAME = 'state_version'),
    'SELECT 1',
    'ALTER TABLE user_course_progress ADD COLUMN state_version BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER status'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

CREATE TABLE IF NOT EXISTS learning_lesson_idempotency (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    lesson_id BIGINT UNSIGNED NOT NULL,
    idempotency_key_hash CHAR(64) NOT NULL,
    response_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_learning_lesson_idempotency (user_id, lesson_id, idempotency_key_hash),
    KEY idx_learning_lesson_idempotency_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS unified_content_index (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    stable_key VARCHAR(160) NOT NULL,
    center_code VARCHAR(32) NOT NULL,
    primary_category VARCHAR(32) NULL,
    content_type VARCHAR(48) NOT NULL,
    title VARCHAR(255) NOT NULL,
    summary TEXT NULL,
    body LONGTEXT NULL,
    tags JSON NULL,
    source_type VARCHAR(48) NOT NULL,
    source_path VARCHAR(512) NOT NULL,
    canonical_url VARCHAR(512) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'active',
    publication_status VARCHAR(24) NOT NULL DEFAULT 'published',
    domain_code VARCHAR(64) NULL,
    target_roles JSON NULL,
    target_stages JSON NULL,
    version_id BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_unified_content_index_key (stable_key),
    KEY idx_unified_content_index_search (publication_status, center_code, content_type, updated_at),
    KEY idx_unified_content_index_source (source_type, source_path)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
