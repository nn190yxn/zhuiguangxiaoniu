-- Cloud media mappings for mini program CloudBase migration.

CREATE TABLE IF NOT EXISTS platform_cloud_media_mappings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    asset_key VARCHAR(128) NOT NULL,
    purpose VARCHAR(64) NOT NULL,
    business_type VARCHAR(64) NOT NULL,
    business_id VARCHAR(128) NOT NULL,
    staff_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    source_fingerprint CHAR(64) NULL DEFAULT NULL,
    cloud_file_id VARCHAR(255) NOT NULL,
    mime_type VARCHAR(80) NOT NULL,
    byte_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    sha256 CHAR(64) NOT NULL,
    status ENUM('pending','ready','failed','expired') NOT NULL DEFAULT 'pending',
    retry_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_code VARCHAR(64) NOT NULL DEFAULT '',
    idempotency_key_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_cloud_media_asset_key (asset_key),
    UNIQUE KEY uniq_cloud_media_idempotency (idempotency_key_hash),
    UNIQUE KEY uniq_cloud_media_source_fingerprint (source_fingerprint),
    KEY idx_cloud_media_business (business_type, business_id, purpose),
    KEY idx_cloud_media_status (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
