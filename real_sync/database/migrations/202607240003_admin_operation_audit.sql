-- Move staff lifecycle audit storage into the versioned migration path.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS admin_operation_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    operator_user_id INT UNSIGNED NULL,
    operator_staff_id INT UNSIGNED NULL,
    module VARCHAR(60) NOT NULL,
    action VARCHAR(60) NOT NULL,
    target_type VARCHAR(60) NOT NULL,
    target_id VARCHAR(120) NULL,
    before_json LONGTEXT NULL,
    after_json LONGTEXT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_module_created (module, created_at),
    KEY idx_operator_created (operator_user_id, created_at),
    KEY idx_target_lookup (target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
