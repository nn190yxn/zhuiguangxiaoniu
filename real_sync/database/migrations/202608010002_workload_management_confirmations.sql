-- Confirmations are auditable evidence for management workload actions.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS workload_management_confirmations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    report_id BIGINT UNSIGNED NOT NULL,
    metric_code VARCHAR(64) NOT NULL,
    confirmer_staff_id BIGINT UNSIGNED NOT NULL,
    confirmer_role_code VARCHAR(32) NOT NULL,
    comment VARCHAR(255) NOT NULL DEFAULT '',
    confirmed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at DATETIME NULL,
    revoked_by_staff_id BIGINT UNSIGNED NULL,
    revoke_comment VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_workload_management_confirmation (report_id, metric_code),
    KEY idx_workload_management_confirmation_active (report_id, metric_code, revoked_at),
    KEY idx_workload_management_confirmation_confirmer (confirmer_staff_id, confirmed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
