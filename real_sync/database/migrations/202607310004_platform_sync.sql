-- Shared incremental sync feed and versioned cross-device drafts.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS platform_sync_drafts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_staff_id BIGINT UNSIGNED NOT NULL,
    domain VARCHAR(63) NOT NULL,
    object_type VARCHAR(63) NOT NULL,
    object_id VARCHAR(128) NOT NULL,
    draft_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    base_state_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
    payload_json LONGTEXT NOT NULL,
    source_client VARCHAR(32) NOT NULL,
    source_device_id VARCHAR(120) NULL,
    status ENUM('active', 'deleted') NOT NULL DEFAULT 'active',
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_platform_sync_draft_owner_object (owner_staff_id, domain, object_type, object_id),
    KEY idx_platform_sync_drafts_expiry (status, expires_at),
    KEY idx_platform_sync_drafts_owner_updated (owner_staff_id, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS platform_sync_changes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope_hash CHAR(64) NOT NULL,
    domain VARCHAR(63) NOT NULL,
    object_type VARCHAR(63) NOT NULL,
    object_id VARCHAR(128) NOT NULL,
    state_version BIGINT UNSIGNED NOT NULL,
    sync_level ENUM('A', 'B', 'C') NOT NULL,
    status ENUM('active', 'deleted', 'revoked', 'permission_revoked') NOT NULL DEFAULT 'active',
    state_json LONGTEXT NULL,
    etag CHAR(66) NOT NULL,
    reason VARCHAR(160) NULL,
    occurred_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_platform_sync_change_version (scope_hash, domain, object_type, object_id, state_version),
    KEY idx_platform_sync_changes_cursor (scope_hash, occurred_at, id),
    KEY idx_platform_sync_changes_object (domain, object_type, object_id, state_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
