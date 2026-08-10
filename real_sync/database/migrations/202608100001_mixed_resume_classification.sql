-- Mixed resume batch intake, position scope snapshots, and classification history.

SET NAMES utf8mb4;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_resume_batches' AND COLUMN_NAME = 'intake_mode'),
    'SELECT 1',
    'ALTER TABLE recruitment_resume_batches MODIFY requirement_id BIGINT UNSIGNED NULL, MODIFY rule_version_id BIGINT UNSIGNED NULL, ADD COLUMN intake_mode ENUM(''single_requirement'', ''mixed_requirements'') NOT NULL DEFAULT ''single_requirement'' AFTER rule_version_id, ADD COLUMN candidate_scope_json LONGTEXT NULL AFTER intake_mode, ADD COLUMN candidate_scope_hash CHAR(64) NULL AFTER candidate_scope_json, ADD COLUMN classification_status ENUM(''awaiting_upload'', ''awaiting_rules'', ''queued'', ''processing'', ''completed'', ''partial_failed'') NOT NULL DEFAULT ''awaiting_upload'' AFTER status, ADD KEY idx_recruitment_resume_batches_intake (intake_mode, classification_status, created_at)'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

CREATE TABLE IF NOT EXISTS recruitment_resume_batch_requirements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_id BIGINT UNSIGNED NOT NULL,
    requirement_id BIGINT UNSIGNED NOT NULL,
    rule_version_id BIGINT UNSIGNED NULL,
    rule_status_snapshot VARCHAR(32) NOT NULL,
    classification_ready TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_recruitment_batch_requirement (batch_id, requirement_id),
    KEY idx_recruitment_batch_requirements_ready (classification_ready, requirement_id, batch_id),
    CONSTRAINT fk_recruitment_batch_requirements_batch FOREIGN KEY (batch_id) REFERENCES recruitment_resume_batches (id) ON DELETE RESTRICT,
    CONSTRAINT fk_recruitment_batch_requirements_requirement FOREIGN KEY (requirement_id) REFERENCES recruitment_requirements (id) ON DELETE RESTRICT,
    CONSTRAINT fk_recruitment_batch_requirements_rule FOREIGN KEY (rule_version_id) REFERENCES recruitment_rule_versions (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_resume_documents' AND COLUMN_NAME = 'assigned_requirement_id'),
    'SELECT 1',
    'ALTER TABLE recruitment_resume_documents ADD COLUMN assigned_requirement_id BIGINT UNSIGNED NULL AFTER batch_id, ADD COLUMN classification_status ENUM(''pending'', ''awaiting_rule'', ''classified'', ''needs_confirmation'', ''failed'') NOT NULL DEFAULT ''pending'' AFTER status, ADD COLUMN classification_version_id BIGINT UNSIGNED NULL AFTER classification_status, ADD KEY idx_recruitment_documents_classification (classification_status, assigned_requirement_id, updated_at), ADD CONSTRAINT fk_recruitment_documents_assigned_requirement FOREIGN KEY (assigned_requirement_id) REFERENCES recruitment_requirements (id) ON DELETE RESTRICT'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

UPDATE recruitment_resume_documents document
JOIN recruitment_resume_batches batch ON batch.id = document.batch_id
SET document.assigned_requirement_id = batch.requirement_id,
    document.classification_status = CASE WHEN batch.requirement_id IS NULL THEN 'pending' ELSE 'classified' END
WHERE document.assigned_requirement_id IS NULL;

CREATE TABLE IF NOT EXISTS recruitment_resume_classification_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    document_id BIGINT UNSIGNED NOT NULL,
    version_no INT UNSIGNED NOT NULL,
    candidate_scope_hash CHAR(64) NOT NULL,
    classifier_version VARCHAR(80) NOT NULL,
    status ENUM('classified', 'needs_confirmation', 'awaiting_rule', 'failed', 'superseded') NOT NULL,
    selected_requirement_id BIGINT UNSIGNED NULL,
    confidence_level ENUM('high', 'medium', 'low') NULL,
    confidence_score DECIMAL(6,3) NULL,
    reason_code VARCHAR(80) NOT NULL,
    evidence_json LONGTEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_recruitment_classification_version (document_id, version_no),
    KEY idx_recruitment_classification_current (document_id, status, created_at),
    KEY idx_recruitment_classification_requirement (selected_requirement_id, status, created_at),
    CONSTRAINT fk_recruitment_classification_document FOREIGN KEY (document_id) REFERENCES recruitment_resume_documents (id) ON DELETE RESTRICT,
    CONSTRAINT fk_recruitment_classification_requirement FOREIGN KEY (selected_requirement_id) REFERENCES recruitment_requirements (id) ON DELETE RESTRICT,
    CONSTRAINT chk_recruitment_classification_score CHECK (confidence_score IS NULL OR (confidence_score >= 0 AND confidence_score <= 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recruitment_resume_classification_candidates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    classification_version_id BIGINT UNSIGNED NOT NULL,
    requirement_id BIGINT UNSIGNED NOT NULL,
    rank_no SMALLINT UNSIGNED NOT NULL,
    score DECIMAL(6,3) NOT NULL,
    evidence_json LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_recruitment_classification_candidate (classification_version_id, requirement_id),
    UNIQUE KEY uq_recruitment_classification_rank (classification_version_id, rank_no),
    KEY idx_recruitment_classification_candidates_requirement (requirement_id, score),
    CONSTRAINT fk_recruitment_classification_candidates_version FOREIGN KEY (classification_version_id) REFERENCES recruitment_resume_classification_versions (id) ON DELETE RESTRICT,
    CONSTRAINT fk_recruitment_classification_candidates_requirement FOREIGN KEY (requirement_id) REFERENCES recruitment_requirements (id) ON DELETE RESTRICT,
    CONSTRAINT chk_recruitment_classification_rank CHECK (rank_no > 0),
    CONSTRAINT chk_recruitment_classification_candidate_score CHECK (score >= 0 AND score <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recruitment_resume_classification_reviews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    document_id BIGINT UNSIGNED NOT NULL,
    before_version_id BIGINT UNSIGNED NULL,
    after_version_id BIGINT UNSIGNED NOT NULL,
    selected_requirement_id BIGINT UNSIGNED NOT NULL,
    review_reason VARCHAR(1000) NOT NULL,
    reviewer_staff_id INT UNSIGNED NOT NULL,
    reviewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_recruitment_classification_reviews_document (document_id, reviewed_at),
    KEY idx_recruitment_classification_reviews_reviewer (reviewer_staff_id, reviewed_at),
    CONSTRAINT fk_recruitment_classification_reviews_document FOREIGN KEY (document_id) REFERENCES recruitment_resume_documents (id) ON DELETE RESTRICT,
    CONSTRAINT fk_recruitment_classification_reviews_before FOREIGN KEY (before_version_id) REFERENCES recruitment_resume_classification_versions (id) ON DELETE RESTRICT,
    CONSTRAINT fk_recruitment_classification_reviews_after FOREIGN KEY (after_version_id) REFERENCES recruitment_resume_classification_versions (id) ON DELETE RESTRICT,
    CONSTRAINT fk_recruitment_classification_reviews_requirement FOREIGN KEY (selected_requirement_id) REFERENCES recruitment_requirements (id) ON DELETE RESTRICT,
    CONSTRAINT fk_recruitment_classification_reviews_staff FOREIGN KEY (reviewer_staff_id) REFERENCES staffs (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_resume_documents' AND CONSTRAINT_NAME = 'fk_recruitment_documents_classification_version'),
    'SELECT 1',
    'ALTER TABLE recruitment_resume_documents ADD CONSTRAINT fk_recruitment_documents_classification_version FOREIGN KEY (classification_version_id) REFERENCES recruitment_resume_classification_versions (id) ON DELETE RESTRICT'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;
