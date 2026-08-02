-- Shared leased jobs with fenced execution and immutable attempt summaries.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS platform_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_type VARCHAR(96) NOT NULL,
    object_type VARCHAR(96) NOT NULL,
    object_id VARCHAR(160) NOT NULL,
    idempotency_key CHAR(64) NOT NULL,
    payload_json LONGTEXT NOT NULL,
    status ENUM('pending', 'running', 'retry_wait', 'succeeded', 'dead_letter', 'cancelled') NOT NULL DEFAULT 'pending',
    priority SMALLINT NOT NULL DEFAULT 0,
    available_at DATETIME(6) NOT NULL,
    max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 3,
    attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    worker_id VARCHAR(120) NULL,
    fencing_token BIGINT UNSIGNED NOT NULL DEFAULT 0,
    locked_at DATETIME(6) NULL,
    heartbeat_at DATETIME(6) NULL,
    lease_expires_at DATETIME(6) NULL,
    result_json LONGTEXT NULL,
    error_code VARCHAR(100) NULL,
    error_summary VARCHAR(1000) NULL,
    recovery_required TINYINT(1) NOT NULL DEFAULT 0,
    completed_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_platform_jobs_idempotency (job_type, idempotency_key),
    KEY idx_platform_jobs_claim (status, available_at, priority, id),
    KEY idx_platform_jobs_lease (status, lease_expires_at),
    KEY idx_platform_jobs_object (object_type, object_id, created_at),
    KEY idx_platform_jobs_backlog (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS platform_job_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id BIGINT UNSIGNED NOT NULL,
    attempt_number SMALLINT UNSIGNED NOT NULL,
    worker_id VARCHAR(120) NOT NULL,
    fencing_token BIGINT UNSIGNED NOT NULL,
    status ENUM('running', 'succeeded', 'failed_retryable', 'dead_letter') NOT NULL DEFAULT 'running',
    result_json LONGTEXT NULL,
    error_code VARCHAR(100) NULL,
    error_summary VARCHAR(1000) NULL,
    started_at DATETIME(6) NOT NULL,
    heartbeat_at DATETIME(6) NOT NULL,
    finished_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_platform_job_runs_fence (job_id, fencing_token),
    KEY idx_platform_job_runs_worker (worker_id, started_at),
    KEY idx_platform_job_runs_status (status, started_at),
    CONSTRAINT fk_platform_job_runs_job FOREIGN KEY (job_id) REFERENCES platform_jobs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
