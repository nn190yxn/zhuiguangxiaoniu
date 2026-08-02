-- Versioned session families, single-use refresh tokens, and security events.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS platform_sessions (
    id CHAR(32) NOT NULL,
    family_id CHAR(32) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    staff_id BIGINT UNSIGNED NULL,
    username_snapshot VARCHAR(191) NOT NULL DEFAULT '',
    role_snapshot VARCHAR(60) NOT NULL DEFAULT 'staff',
    client_type VARCHAR(32) NOT NULL,
    device_id VARCHAR(120) NULL,
    session_version INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('active', 'revoked', 'expired') NOT NULL DEFAULT 'active',
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    revoke_reason VARCHAR(80) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_refreshed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_platform_sessions_family (family_id, status),
    KEY idx_platform_sessions_user (user_id, status, expires_at),
    KEY idx_platform_sessions_staff (staff_id, status, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS platform_refresh_tokens (
    id CHAR(32) NOT NULL,
    session_id CHAR(32) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    status ENUM('active', 'rotated', 'revoked', 'expired') NOT NULL DEFAULT 'active',
    expires_at DATETIME NOT NULL,
    rotated_at DATETIME NULL,
    revoked_at DATETIME NULL,
    replaced_by_token_id CHAR(32) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_platform_refresh_tokens_hash (token_hash),
    KEY idx_platform_refresh_tokens_session (session_id, status),
    KEY idx_platform_refresh_tokens_expiry (status, expires_at),
    CONSTRAINT fk_platform_refresh_tokens_session FOREIGN KEY (session_id) REFERENCES platform_sessions (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS platform_security_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_type VARCHAR(60) NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    staff_id BIGINT UNSIGNED NULL,
    session_id CHAR(32) NULL,
    family_id CHAR(32) NULL,
    refresh_token_id CHAR(32) NULL,
    client_type VARCHAR(32) NULL,
    event_data_json LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_platform_security_events_type (event_type, created_at),
    KEY idx_platform_security_events_user (user_id, created_at),
    KEY idx_platform_security_events_family (family_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
