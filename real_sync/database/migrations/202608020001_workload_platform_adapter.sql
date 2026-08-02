-- Move workload platform adapter runtime prerequisites into migration control.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS workload_alert_worker_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_key VARCHAR(32) NOT NULL,
    business_date DATE NOT NULL,
    status VARCHAR(32) NOT NULL,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    summary_json LONGTEXT NULL,
    error_message VARCHAR(500) NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_workload_alert_worker_run_key (run_key),
    KEY idx_workload_alert_worker_runs_status (status, business_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
