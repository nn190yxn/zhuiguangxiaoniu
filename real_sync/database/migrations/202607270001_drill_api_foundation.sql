-- Drill v2 request idempotency foundation.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS drill_idempotency_keys (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(80) NOT NULL,
    idempotency_key VARCHAR(128) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    response_json LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_drill_idempotency_identity (user_id, action, idempotency_key),
    KEY idx_drill_idempotency_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
