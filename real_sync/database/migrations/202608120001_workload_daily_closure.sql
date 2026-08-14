-- Add additive daily workload settlement, penalty, and audit ledgers.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS workload_daily_settlements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    business_date DATE NOT NULL,
    store_id BIGINT UNSIGNED NOT NULL,
    staff_id BIGINT UNSIGNED NOT NULL,
    role_code VARCHAR(64) NOT NULL,
    target_points DECIMAL(10,2) NOT NULL DEFAULT 4.00,
    reported_points DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    pending_points DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    effective_points DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    rejected_points DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    gap_points DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    settlement_status VARCHAR(32) NOT NULL DEFAULT 'today_open',
    makeup_deadline_at DATETIME NULL,
    settled_at DATETIME NULL,
    rule_snapshot_json LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_workload_daily_settlement_scope (business_date, store_id, staff_id, role_code),
    KEY idx_workload_daily_settlements_status (settlement_status, business_date, makeup_deadline_at),
    KEY idx_workload_daily_settlements_staff (staff_id, business_date, settlement_status),
    KEY idx_workload_daily_settlements_store (store_id, business_date, settlement_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS workload_penalty_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    settlement_id BIGINT UNSIGNED NOT NULL,
    business_date DATE NOT NULL,
    store_id BIGINT UNSIGNED NOT NULL,
    staff_id BIGINT UNSIGNED NOT NULL,
    role_code VARCHAR(64) NOT NULL,
    gap_points DECIMAL(10,2) NOT NULL,
    unit_amount DECIMAL(10,2) NOT NULL DEFAULT 20.00,
    penalty_amount DECIMAL(10,2) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending_confirmation',
    adjustment_reason VARCHAR(500) NULL,
    confirmed_by_staff_id BIGINT UNSIGNED NULL,
    confirmed_at DATETIME NULL,
    confirmation_comment VARCHAR(500) NULL,
    cancelled_by_staff_id BIGINT UNSIGNED NULL,
    cancelled_at DATETIME NULL,
    cancellation_reason VARCHAR(500) NULL,
    payroll_handed_off_by_staff_id BIGINT UNSIGNED NULL,
    payroll_handed_off_at DATETIME NULL,
    payroll_handoff_note VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_workload_penalty_record_scope (business_date, store_id, staff_id, role_code),
    KEY idx_workload_penalty_records_settlement (settlement_id),
    KEY idx_workload_penalty_records_status (status, business_date, store_id),
    KEY idx_workload_penalty_records_staff (staff_id, business_date, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS workload_penalty_record_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    penalty_record_id BIGINT UNSIGNED NOT NULL,
    action_code VARCHAR(32) NOT NULL,
    before_snapshot_json LONGTEXT NULL,
    after_snapshot_json LONGTEXT NOT NULL,
    reason VARCHAR(500) NULL,
    operated_by_staff_id BIGINT UNSIGNED NULL,
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_workload_penalty_record_logs_record (penalty_record_id, occurred_at),
    KEY idx_workload_penalty_record_logs_operator (operated_by_staff_id, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
