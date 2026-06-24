ALTER TABLE staffs ADD COLUMN wecom_userid VARCHAR(128) NULL AFTER openid_bound_at;
ALTER TABLE staffs ADD COLUMN wecom_name VARCHAR(100) NULL AFTER wecom_userid;
ALTER TABLE staffs ADD COLUMN wecom_mobile VARCHAR(32) NULL AFTER wecom_name;
ALTER TABLE staffs ADD COLUMN wecom_department_id VARCHAR(128) NULL AFTER wecom_mobile;
ALTER TABLE staffs ADD COLUMN wecom_department_path VARCHAR(255) NULL AFTER wecom_department_id;
ALTER TABLE staffs ADD COLUMN wecom_status TINYINT NULL AFTER wecom_department_path;
ALTER TABLE staffs ADD COLUMN wecom_bound_at DATETIME NULL AFTER wecom_status;

CREATE TABLE IF NOT EXISTS wecom_sync_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sync_type VARCHAR(32) NOT NULL DEFAULT 'members',
    status VARCHAR(16) NOT NULL DEFAULT 'success',
    operator_user_id BIGINT UNSIGNED DEFAULT NULL,
    operator_staff_id BIGINT UNSIGNED DEFAULT NULL,
    departments_total INT UNSIGNED NOT NULL DEFAULT 0,
    users_total INT UNSIGNED NOT NULL DEFAULT 0,
    matched_total INT UNSIGNED NOT NULL DEFAULT 0,
    updated_total INT UNSIGNED NOT NULL DEFAULT 0,
    unbound_total INT UNSIGNED NOT NULL DEFAULT 0,
    deactivated_total INT UNSIGNED NOT NULL DEFAULT 0,
    payload_json JSON DEFAULT NULL,
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
    source_job_id BIGINT UNSIGNED DEFAULT NULL,
    message_type VARCHAR(32) NOT NULL DEFAULT 'miniprogram_notice',
    target_user_id BIGINT UNSIGNED DEFAULT NULL,
    target_staff_id BIGINT UNSIGNED DEFAULT NULL,
    target_wecom_userid VARCHAR(128) NOT NULL DEFAULT '',
    page_path VARCHAR(255) NOT NULL DEFAULT '',
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    request_json JSON DEFAULT NULL,
    response_json JSON DEFAULT NULL,
    error_message VARCHAR(255) NOT NULL DEFAULT '',
    sent_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_source_job (source_type, source_job_id),
    KEY idx_status_created (status, created_at),
    KEY idx_target_wecom_userid (target_wecom_userid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
