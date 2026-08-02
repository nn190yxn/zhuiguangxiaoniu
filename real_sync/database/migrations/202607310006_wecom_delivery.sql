-- Move WeCom synchronization and delivery logs out of request paths.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS wecom_sync_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sync_type VARCHAR(32) NOT NULL DEFAULT 'members',
    status VARCHAR(16) NOT NULL DEFAULT 'success',
    operator_user_id BIGINT UNSIGNED NULL,
    operator_staff_id BIGINT UNSIGNED NULL,
    departments_total INT UNSIGNED NOT NULL DEFAULT 0,
    users_total INT UNSIGNED NOT NULL DEFAULT 0,
    matched_total INT UNSIGNED NOT NULL DEFAULT 0,
    updated_total INT UNSIGNED NOT NULL DEFAULT 0,
    unbound_total INT UNSIGNED NOT NULL DEFAULT 0,
    deactivated_total INT UNSIGNED NOT NULL DEFAULT 0,
    payload_json JSON NULL,
    error_message VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sync_created (sync_type, created_at),
    KEY idx_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wecom_message_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_type VARCHAR(32) NOT NULL DEFAULT 'reminder',
    source_key VARCHAR(64) NOT NULL DEFAULT '',
    source_job_id BIGINT UNSIGNED NULL,
    message_type VARCHAR(32) NOT NULL DEFAULT 'miniprogram_notice',
    target_user_id BIGINT UNSIGNED NULL,
    target_staff_id BIGINT UNSIGNED NULL,
    target_wecom_userid VARCHAR(128) NOT NULL DEFAULT '',
    page_path VARCHAR(255) NOT NULL DEFAULT '',
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    request_json JSON NULL,
    response_json JSON NULL,
    error_message VARCHAR(255) NOT NULL DEFAULT '',
    sent_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_source_job (source_type, source_job_id),
    KEY idx_status_created (status, created_at),
    KEY idx_target_wecom_userid (target_wecom_userid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
