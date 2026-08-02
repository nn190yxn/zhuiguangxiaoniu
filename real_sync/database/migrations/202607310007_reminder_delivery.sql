-- Move reminder delivery storage and default rules out of request paths.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS mini_reminder_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rule_code VARCHAR(64) NOT NULL,
    rule_name VARCHAR(128) NOT NULL,
    scene_code VARCHAR(32) NOT NULL,
    channel_code VARCHAR(32) NOT NULL DEFAULT 'station+wechat',
    recipient_scope VARCHAR(32) NOT NULL,
    target_roles VARCHAR(255) NOT NULL DEFAULT '',
    schedule_time CHAR(5) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    config_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_rule_code (rule_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mini_reminder_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reminder_date DATE NOT NULL,
    rule_code VARCHAR(64) NOT NULL,
    target_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    target_staff_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    target_store_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    target_role_code VARCHAR(32) NOT NULL DEFAULT '',
    target_name VARCHAR(128) NOT NULL DEFAULT '',
    type VARCHAR(32) NOT NULL DEFAULT 'reminder',
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    payload_json JSON NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    channel_station_status VARCHAR(16) NOT NULL DEFAULT 'pending',
    channel_wechat_status VARCHAR(16) NOT NULL DEFAULT 'pending',
    channel_wechat_note VARCHAR(255) NOT NULL DEFAULT '',
    notification_id BIGINT UNSIGNED NULL,
    sent_at DATETIME NULL,
    last_error VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_target_job (reminder_date, rule_code, target_user_id, target_staff_id, target_store_id),
    KEY idx_status_date (status, reminder_date),
    KEY idx_rule_date (rule_code, reminder_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mini_user_subscriptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    staff_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    scene_code VARCHAR(32) NOT NULL,
    template_key VARCHAR(64) NOT NULL,
    openid VARCHAR(128) NOT NULL DEFAULT '',
    accept_status VARCHAR(16) NOT NULL DEFAULT 'unknown',
    extra_json JSON NULL,
    granted_at DATETIME NULL,
    last_seen_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_user_template (user_id, template_key),
    KEY idx_scene_user (scene_code, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mini_user_notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(32) NOT NULL DEFAULT 'reminder',
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    policy_id BIGINT UNSIGNED NULL,
    source_type VARCHAR(32) NOT NULL DEFAULT 'reminder',
    source_key VARCHAR(64) NOT NULL DEFAULT '',
    source_job_id BIGINT UNSIGNED NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    is_confirmed TINYINT(1) NOT NULL DEFAULT 0,
    read_at DATETIME NULL,
    confirmed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_source_job (source_type, source_job_id),
    KEY idx_user_created (user_id, created_at),
    KEY idx_user_unread (user_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO mini_reminder_rules
    (rule_code, rule_name, scene_code, channel_code, recipient_scope, target_roles, schedule_time, enabled, config_json)
VALUES
    ('learning_required_daily', '必修课程待完成提醒', 'learning', 'station+wechat', 'staff', 'all', '09:00', 1, JSON_OBJECT('phase', 'learning_required')),
    ('workload_daily_first', '工作量首次提醒', 'workload', 'station+wechat', 'staff', 'sales,coach,manager', '20:00', 1, JSON_OBJECT('phase', 'first')),
    ('workload_daily_second', '工作量二次提醒', 'workload', 'station+wechat', 'staff', 'sales,coach,manager', '23:00', 1, JSON_OBJECT('phase', 'second')),
    ('workload_store_summary', '门店工作量汇总提醒', 'workload', 'station+wechat', 'manager', 'manager', '23:05', 1, JSON_OBJECT('phase', 'store_summary')),
    ('workload_hq_summary', '总部工作量汇总提醒', 'workload', 'station+wechat', 'headquarter', 'operation,finance,admin,ceo', '23:10', 1, JSON_OBJECT('phase', 'hq_summary'))
ON DUPLICATE KEY UPDATE
    rule_name = VALUES(rule_name),
    scene_code = VALUES(scene_code),
    channel_code = VALUES(channel_code),
    recipient_scope = VALUES(recipient_scope),
    target_roles = VALUES(target_roles),
    schedule_time = VALUES(schedule_time),
    enabled = VALUES(enabled),
    config_json = VALUES(config_json);
