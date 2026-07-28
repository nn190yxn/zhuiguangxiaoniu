-- Workload standard import batches and row-level preflight results.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS workload_standard_import_batches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_key CHAR(36) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_sha256 CHAR(64) NOT NULL,
    idempotency_key VARCHAR(128) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'preflight_ready',
    role_count INT UNSIGNED NOT NULL DEFAULT 0,
    total_rows INT UNSIGNED NOT NULL DEFAULT 0,
    valid_rows INT UNSIGNED NOT NULL DEFAULT 0,
    error_rows INT UNSIGNED NOT NULL DEFAULT 0,
    summary_json LONGTEXT NOT NULL,
    created_by_staff_id BIGINT UNSIGNED NULL,
    confirmed_by_staff_id BIGINT UNSIGNED NULL,
    confirmed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_workload_standard_import_batch_key (batch_key),
    UNIQUE KEY uq_workload_standard_import_request (file_sha256, idempotency_key),
    KEY idx_workload_standard_import_status (status, created_at),
    KEY idx_workload_standard_import_operator (created_by_staff_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS workload_standard_import_rows (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_id BIGINT UNSIGNED NOT NULL,
    row_number INT UNSIGNED NOT NULL,
    role_code VARCHAR(64) NOT NULL DEFAULT '',
    metric_code VARCHAR(64) NOT NULL DEFAULT '',
    field_summary_json LONGTEXT NOT NULL,
    validation_status VARCHAR(20) NOT NULL,
    difference_action VARCHAR(20) NOT NULL,
    error_json LONGTEXT NULL,
    target_rule_version_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_workload_standard_import_row (batch_id, row_number),
    KEY idx_workload_standard_import_row_role (batch_id, role_code, validation_status),
    KEY idx_workload_standard_import_row_target (target_rule_version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
