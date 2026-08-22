-- One-time tickets for mini program WeChat and WeCom binding after password login.

CREATE TABLE IF NOT EXISTS miniprogram_bind_tickets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_hash CHAR(64) NOT NULL,
    staff_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    bind_mode ENUM('wechat', 'wecom') NOT NULL DEFAULT 'wechat',
    device_id VARCHAR(120) NOT NULL,
    device_fingerprint VARCHAR(120) NOT NULL,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_miniprogram_bind_ticket_hash (ticket_hash),
    KEY idx_miniprogram_bind_ticket_staff (staff_id, bind_mode, expires_at),
    KEY idx_miniprogram_bind_ticket_expiry (expires_at, consumed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
