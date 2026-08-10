-- Recruitment platform adapters, state versions, and idempotent hire conversion.

SET NAMES utf8mb4;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_resume_files' AND COLUMN_NAME = 'platform_asset_id'),
    'SELECT 1',
    'ALTER TABLE recruitment_resume_files ADD COLUMN platform_asset_id BIGINT UNSIGNED NULL AFTER storage_key, ADD UNIQUE KEY uq_recruitment_resume_files_asset (platform_asset_id), ADD CONSTRAINT fk_recruitment_resume_files_asset FOREIGN KEY (platform_asset_id) REFERENCES platform_file_assets (id) ON DELETE RESTRICT'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_applications' AND COLUMN_NAME = 'hiring_status'),
    'SELECT 1',
    'ALTER TABLE recruitment_applications ADD COLUMN hiring_status ENUM(''screening'', ''approved'', ''converted'') NOT NULL DEFAULT ''screening'' AFTER queue_status, ADD COLUMN state_version BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER hiring_status, ADD KEY idx_recruitment_applications_hiring (hiring_status, state_version, updated_at)'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

CREATE TABLE IF NOT EXISTS recruitment_hire_approvals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id BIGINT UNSIGNED NOT NULL,
    decision ENUM('approved', 'revoked') NOT NULL,
    approval_reason VARCHAR(1000) NOT NULL,
    idempotency_key VARCHAR(128) NOT NULL,
    state_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    approved_by BIGINT UNSIGNED NOT NULL,
    approved_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    revoked_by BIGINT UNSIGNED NULL,
    revoked_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_recruitment_hire_approval_application (application_id),
    UNIQUE KEY uq_recruitment_hire_approval_idempotency (idempotency_key),
    KEY idx_recruitment_hire_approval_decision (decision, approved_at),
    CONSTRAINT fk_recruitment_hire_approval_application FOREIGN KEY (application_id) REFERENCES recruitment_applications (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recruitment_hire_conversions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id BIGINT UNSIGNED NOT NULL,
    approval_id BIGINT UNSIGNED NOT NULL,
    employee_staff_id INT UNSIGNED NULL,
    idempotency_key VARCHAR(128) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    response_json LONGTEXT NULL,
    status ENUM('processing', 'completed') NOT NULL DEFAULT 'processing',
    state_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    converted_by BIGINT UNSIGNED NOT NULL,
    converted_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_recruitment_hire_conversion_application (application_id),
    UNIQUE KEY uq_recruitment_hire_conversion_idempotency (idempotency_key),
    KEY idx_recruitment_hire_conversion_staff (employee_staff_id),
    KEY idx_recruitment_hire_conversion_status (status, updated_at),
    CONSTRAINT fk_recruitment_hire_conversion_application FOREIGN KEY (application_id) REFERENCES recruitment_applications (id) ON DELETE RESTRICT,
    CONSTRAINT fk_recruitment_hire_conversion_approval FOREIGN KEY (approval_id) REFERENCES recruitment_hire_approvals (id) ON DELETE RESTRICT,
    CONSTRAINT fk_recruitment_hire_conversion_staff FOREIGN KEY (employee_staff_id) REFERENCES staffs (id) ON DELETE RESTRICT,
    CONSTRAINT chk_recruitment_hire_conversion_response CHECK (response_json IS NULL OR JSON_VALID(response_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
