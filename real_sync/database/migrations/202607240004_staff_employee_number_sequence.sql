-- Transaction-safe employee number allocation.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS staff_employee_number_sequences (
    sequence_key VARCHAR(64) NOT NULL,
    current_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (sequence_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
