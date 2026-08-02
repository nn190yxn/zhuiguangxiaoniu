-- Recruitment requirement, rule, batch, scope assignment, and audit foundation.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS recruitment_requirements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    requirement_no VARCHAR(40) NOT NULL,
    store_id BIGINT UNSIGNED NULL,
    position_id BIGINT UNSIGNED NULL,
    position_name_snapshot VARCHAR(120) NOT NULL,
    job_description LONGTEXT NULL,
    headcount INT UNSIGNED NOT NULL DEFAULT 1,
    target_onboard_date DATE NULL,
    status ENUM('draft', 'approval_pending', 'returned', 'approved', 'closed') NOT NULL DEFAULT 'draft',
    additional_requirements LONGTEXT NULL,
    submitted_by BIGINT UNSIGNED NULL,
    submitted_at DATETIME NULL,
    approved_by BIGINT UNSIGNED NULL,
    approved_at DATETIME NULL,
    approval_comment VARCHAR(1000) NULL,
    closed_by BIGINT UNSIGNED NULL,
    closed_at DATETIME NULL,
    status_reason VARCHAR(1000) NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_recruitment_requirements_no (requirement_no),
    KEY idx_recruitment_requirements_status_store (status, store_id, updated_at),
    KEY idx_recruitment_requirements_store_position (store_id, position_id, status),
    KEY idx_recruitment_requirements_creator (created_by, created_at),
    KEY idx_recruitment_requirements_target_date (target_onboard_date, status),
    CONSTRAINT chk_recruitment_requirements_headcount CHECK (headcount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recruitment_rule_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    position_id BIGINT UNSIGNED NULL,
    position_name_snapshot VARCHAR(120) NOT NULL,
    version_no INT UNSIGNED NOT NULL,
    status ENUM('draft', 'in_review', 'published', 'archived') NOT NULL DEFAULT 'draft',
    job_description LONGTEXT NULL,
    hard_conditions_json LONGTEXT NULL,
    experience_rules_json LONGTEXT NULL,
    keyword_rules_json LONGTEXT NULL,
    grade_rules_json LONGTEXT NULL,
    prompt_version VARCHAR(80) NULL,
    source_rule_version_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NULL,
    published_by BIGINT UNSIGNED NULL,
    published_at DATETIME NULL,
    archived_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_recruitment_rule_versions_position_version (position_id, version_no),
    KEY idx_recruitment_rule_versions_status (status, position_id, updated_at),
    KEY idx_recruitment_rule_versions_source (source_rule_version_id),
    KEY idx_recruitment_rule_versions_published (published_at, published_by),
    CONSTRAINT chk_recruitment_rule_versions_version CHECK (version_no > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recruitment_resume_batches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_no VARCHAR(40) NOT NULL,
    requirement_id BIGINT UNSIGNED NOT NULL,
    rule_version_id BIGINT UNSIGNED NOT NULL,
    status ENUM('draft', 'uploaded', 'processing', 'completed', 'partial_failed', 'cancelled') NOT NULL DEFAULT 'draft',
    source_type ENUM('admin_upload', 'imap') NOT NULL DEFAULT 'admin_upload',
    file_count INT UNSIGNED NOT NULL DEFAULT 0,
    total_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    processed_count INT UNSIGNED NOT NULL DEFAULT 0,
    failed_count INT UNSIGNED NOT NULL DEFAULT 0,
    duplicate_count INT UNSIGNED NOT NULL DEFAULT 0,
    grade_a_count INT UNSIGNED NOT NULL DEFAULT 0,
    grade_b_count INT UNSIGNED NOT NULL DEFAULT 0,
    grade_c_count INT UNSIGNED NOT NULL DEFAULT 0,
    batch_note VARCHAR(1000) NULL,
    created_by BIGINT UNSIGNED NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    cancelled_by BIGINT UNSIGNED NULL,
    cancelled_at DATETIME NULL,
    cancel_reason VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_recruitment_resume_batches_no (batch_no),
    KEY idx_recruitment_resume_batches_requirement (requirement_id, status, created_at),
    KEY idx_recruitment_resume_batches_rule (rule_version_id, status),
    KEY idx_recruitment_resume_batches_status (status, updated_at),
    KEY idx_recruitment_resume_batches_creator (created_by, created_at),
    CONSTRAINT fk_recruitment_batches_requirement FOREIGN KEY (requirement_id) REFERENCES recruitment_requirements (id) ON DELETE RESTRICT,
    CONSTRAINT fk_recruitment_batches_rule_version FOREIGN KEY (rule_version_id) REFERENCES recruitment_rule_versions (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recruitment_requirement_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    requirement_id BIGINT UNSIGNED NOT NULL,
    staff_id BIGINT UNSIGNED NOT NULL,
    assignment_type ENUM('temporary', 'reviewer', 'owner') NOT NULL DEFAULT 'temporary',
    status ENUM('active', 'revoked', 'expired') NOT NULL DEFAULT 'active',
    starts_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NULL,
    assigned_by BIGINT UNSIGNED NULL,
    assignment_reason VARCHAR(500) NULL,
    revoked_by BIGINT UNSIGNED NULL,
    revoked_at DATETIME NULL,
    revoke_reason VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_recruitment_requirement_assignment_window (requirement_id, staff_id, assignment_type, starts_at),
    KEY idx_recruitment_requirement_assignments_staff (staff_id, status, starts_at, expires_at),
    KEY idx_recruitment_requirement_assignments_requirement (requirement_id, status),
    CONSTRAINT fk_recruitment_assignments_requirement FOREIGN KEY (requirement_id) REFERENCES recruitment_requirements (id) ON DELETE RESTRICT,
    CONSTRAINT chk_recruitment_assignment_window CHECK (expires_at IS NULL OR expires_at >= starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recruitment_idempotency_keys (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    idempotency_key VARCHAR(128) NOT NULL,
    action VARCHAR(60) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    response_json LONGTEXT NULL,
    operator_staff_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_recruitment_idempotency_action (idempotency_key, action),
    KEY idx_recruitment_idempotency_operator (operator_staff_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_operation_logs' AND COLUMN_NAME = 'recruitment_requirement_id')
    OR NOT EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_operation_logs'),
    'SELECT 1',
    'ALTER TABLE admin_operation_logs ADD COLUMN recruitment_requirement_id BIGINT UNSIGNED NULL AFTER target_id'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

CREATE TABLE IF NOT EXISTS recruitment_resume_files (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_id BIGINT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    storage_key VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    byte_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    sha256 CHAR(64) NOT NULL,
    page_count INT UNSIGNED NOT NULL DEFAULT 1,
    status ENUM('uploaded', 'queued', 'processing', 'completed', 'failed', 'skipped') NOT NULL DEFAULT 'uploaded',
    failure_stage VARCHAR(60) NULL,
    failure_code VARCHAR(80) NULL,
    failure_message VARCHAR(1000) NULL,
    duplicate_of_file_id BIGINT UNSIGNED NULL,
    filename_match_json LONGTEXT NULL,
    uploaded_by BIGINT UNSIGNED NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_recruitment_resume_files_storage (storage_key),
    KEY idx_recruitment_resume_files_batch_status (batch_id, status, id),
    KEY idx_recruitment_resume_files_sha256 (sha256, byte_size),
    KEY idx_recruitment_resume_files_duplicate (duplicate_of_file_id),
    KEY idx_recruitment_resume_files_uploader (uploaded_by, uploaded_at),
    CONSTRAINT fk_recruitment_files_batch FOREIGN KEY (batch_id) REFERENCES recruitment_resume_batches (id) ON DELETE RESTRICT,
    CONSTRAINT fk_recruitment_files_duplicate FOREIGN KEY (duplicate_of_file_id) REFERENCES recruitment_resume_files (id) ON DELETE RESTRICT,
    CONSTRAINT chk_recruitment_files_page_count CHECK (page_count > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recruitment_resume_file_sources (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    resume_file_id BIGINT UNSIGNED NOT NULL,
    batch_id BIGINT UNSIGNED NOT NULL,
    source_type ENUM('admin_upload', 'imap') NOT NULL DEFAULT 'admin_upload',
    original_name VARCHAR(255) NOT NULL,
    source_message_id VARCHAR(255) NULL,
    source_received_at DATETIME NULL,
    uploaded_by BIGINT UNSIGNED NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_recruitment_file_sources_file (resume_file_id, uploaded_at),
    KEY idx_recruitment_file_sources_batch (batch_id, source_type, uploaded_at),
    KEY idx_recruitment_file_sources_message (source_message_id),
    CONSTRAINT fk_recruitment_file_sources_file FOREIGN KEY (resume_file_id) REFERENCES recruitment_resume_files (id) ON DELETE RESTRICT,
    CONSTRAINT fk_recruitment_file_sources_batch FOREIGN KEY (batch_id) REFERENCES recruitment_resume_batches (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recruitment_resume_documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_id BIGINT UNSIGNED NOT NULL,
    document_type ENUM('pdf', 'image_group') NOT NULL,
    document_sha256 CHAR(64) NOT NULL,
    revision_no INT UNSIGNED NOT NULL DEFAULT 1,
    status ENUM('draft', 'queued', 'processing', 'completed', 'failed', 'superseded') NOT NULL DEFAULT 'draft',
    superseded_by_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NULL,
    failure_stage VARCHAR(60) NULL,
    failure_code VARCHAR(80) NULL,
    failure_message VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_recruitment_documents_revision (batch_id, document_sha256, revision_no),
    KEY idx_recruitment_documents_batch_status (batch_id, status, id),
    KEY idx_recruitment_documents_sha256 (document_sha256, status),
    KEY idx_recruitment_documents_superseded (superseded_by_id),
    CONSTRAINT fk_recruitment_documents_batch FOREIGN KEY (batch_id) REFERENCES recruitment_resume_batches (id) ON DELETE RESTRICT,
    CONSTRAINT fk_recruitment_documents_superseded FOREIGN KEY (superseded_by_id) REFERENCES recruitment_resume_documents (id) ON DELETE RESTRICT,
    CONSTRAINT chk_recruitment_documents_revision CHECK (revision_no > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recruitment_resume_document_pages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    document_id BIGINT UNSIGNED NOT NULL,
    resume_file_id BIGINT UNSIGNED NOT NULL,
    page_order INT UNSIGNED NOT NULL,
    file_page_no INT UNSIGNED NOT NULL DEFAULT 1,
    page_sha256 CHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_recruitment_document_pages_order (document_id, page_order),
    UNIQUE KEY uq_recruitment_document_pages_file_page (document_id, resume_file_id, file_page_no),
    KEY idx_recruitment_document_pages_file (resume_file_id, document_id),
    CONSTRAINT fk_recruitment_document_pages_document FOREIGN KEY (document_id) REFERENCES recruitment_resume_documents (id) ON DELETE RESTRICT,
    CONSTRAINT fk_recruitment_document_pages_file FOREIGN KEY (resume_file_id) REFERENCES recruitment_resume_files (id) ON DELETE RESTRICT,
    CONSTRAINT chk_recruitment_document_pages_order CHECK (page_order > 0),
    CONSTRAINT chk_recruitment_document_pages_file_page CHECK (file_page_no > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recruitment_resume_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    document_id BIGINT UNSIGNED NOT NULL,
    job_type ENUM('extract', 'match', 'grade') NOT NULL,
    status ENUM('pending', 'running', 'ai_pending_retry', 'ai_retry_exhausted', 'succeeded', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
    priority INT NOT NULL DEFAULT 100,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts INT UNSIGNED NOT NULL DEFAULT 3,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_at DATETIME NULL,
    locked_by VARCHAR(120) NULL,
    lease_expires_at DATETIME NULL,
    idempotency_hash CHAR(64) NOT NULL,
    processing_version_id BIGINT UNSIGNED NULL,
    failure_code VARCHAR(80) NULL,
    failure_message VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_recruitment_resume_jobs_idempotency (document_id, job_type, idempotency_hash),
    KEY idx_recruitment_resume_jobs_claim (status, available_at, priority, id),
    KEY idx_recruitment_resume_jobs_lease (locked_by, lease_expires_at),
    KEY idx_recruitment_resume_jobs_document (document_id, status),
    KEY idx_recruitment_resume_jobs_processing_version (processing_version_id),
    CONSTRAINT fk_recruitment_jobs_document FOREIGN KEY (document_id) REFERENCES recruitment_resume_documents (id) ON DELETE RESTRICT,
    CONSTRAINT chk_recruitment_jobs_attempts CHECK (max_attempts > 0 AND attempt_count <= max_attempts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recruitment_resume_duplicate_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    duplicate_type ENUM('exact_document', 'candidate_phone', 'candidate_similarity') NOT NULL,
    batch_id BIGINT UNSIGNED NOT NULL,
    current_file_id BIGINT UNSIGNED NULL,
    current_document_id BIGINT UNSIGNED NULL,
    historical_file_id BIGINT UNSIGNED NULL,
    historical_document_id BIGINT UNSIGNED NULL,
    historical_application_id BIGINT UNSIGNED NULL,
    evidence_json LONGTEXT NULL,
    status ENUM('pending', 'skipped', 'reused', 'continued') NOT NULL DEFAULT 'pending',
    prompted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_by BIGINT UNSIGNED NULL,
    resolved_at DATETIME NULL,
    resolution_note VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_recruitment_duplicates_batch_status (batch_id, status, duplicate_type),
    KEY idx_recruitment_duplicates_current_file (current_file_id, status),
    KEY idx_recruitment_duplicates_current_document (current_document_id, status),
    KEY idx_recruitment_duplicates_historical_file (historical_file_id),
    KEY idx_recruitment_duplicates_historical_document (historical_document_id),
    KEY idx_recruitment_duplicates_application (historical_application_id),
    CONSTRAINT fk_recruitment_duplicates_batch FOREIGN KEY (batch_id) REFERENCES recruitment_resume_batches (id) ON DELETE RESTRICT,
    CONSTRAINT fk_recruitment_duplicates_current_file FOREIGN KEY (current_file_id) REFERENCES recruitment_resume_files (id) ON DELETE RESTRICT,
    CONSTRAINT fk_recruitment_duplicates_current_document FOREIGN KEY (current_document_id) REFERENCES recruitment_resume_documents (id) ON DELETE RESTRICT,
    CONSTRAINT fk_recruitment_duplicates_historical_file FOREIGN KEY (historical_file_id) REFERENCES recruitment_resume_files (id) ON DELETE RESTRICT,
    CONSTRAINT fk_recruitment_duplicates_historical_document FOREIGN KEY (historical_document_id) REFERENCES recruitment_resume_documents (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_operation_logs' AND COLUMN_NAME = 'recruitment_batch_id')
    OR NOT EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_operation_logs'),
    'SELECT 1',
    'ALTER TABLE admin_operation_logs ADD COLUMN recruitment_batch_id BIGINT UNSIGNED NULL AFTER recruitment_requirement_id'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_operation_logs' AND COLUMN_NAME = 'recruitment_candidate_id')
    OR NOT EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_operation_logs'),
    'SELECT 1',
    'ALTER TABLE admin_operation_logs ADD COLUMN recruitment_candidate_id BIGINT UNSIGNED NULL AFTER recruitment_batch_id'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_operation_logs' AND INDEX_NAME = 'idx_admin_operation_logs_recruitment')
    OR NOT EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_operation_logs'),
    'SELECT 1',
    'ALTER TABLE admin_operation_logs ADD KEY idx_admin_operation_logs_recruitment (recruitment_requirement_id, recruitment_batch_id, recruitment_candidate_id, created_at)'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

CREATE TABLE IF NOT EXISTS recruitment_candidates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NULL,
    name_confidence DECIMAL(5,4) NULL,
    phone_ciphertext LONGTEXT NULL,
    phone_display_ciphertext LONGTEXT NULL,
    phone_lookup_hash CHAR(64) NULL,
    phone_confidence DECIMAL(5,4) NULL,
    phone_key_version VARCHAR(40) NULL,
    email_ciphertext LONGTEXT NULL,
    email_lookup_hash CHAR(64) NULL,
    email_confidence DECIMAL(5,4) NULL,
    duplicate_status ENUM('unique', 'suspected', 'confirmed') NOT NULL DEFAULT 'unique',
    record_status ENUM('active', 'merged') NOT NULL DEFAULT 'active',
    canonical_candidate_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_recruitment_candidates_phone_lookup (phone_lookup_hash, record_status),
    KEY idx_recruitment_candidates_email_lookup (email_lookup_hash, record_status),
    KEY idx_recruitment_candidates_name (name, record_status),
    KEY idx_recruitment_candidates_duplicate (duplicate_status, updated_at),
    KEY idx_recruitment_candidates_canonical (canonical_candidate_id),
    CONSTRAINT fk_recruitment_candidates_canonical FOREIGN KEY (canonical_candidate_id) REFERENCES recruitment_candidates (id) ON DELETE RESTRICT,
    CONSTRAINT chk_recruitment_candidates_name_confidence CHECK (name_confidence IS NULL OR (name_confidence >= 0 AND name_confidence <= 1)),
    CONSTRAINT chk_recruitment_candidates_phone_confidence CHECK (phone_confidence IS NULL OR (phone_confidence >= 0 AND phone_confidence <= 1)),
    CONSTRAINT chk_recruitment_candidates_email_confidence CHECK (email_confidence IS NULL OR (email_confidence >= 0 AND email_confidence <= 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recruitment_processing_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    document_id BIGINT UNSIGNED NOT NULL,
    requirement_id BIGINT UNSIGNED NOT NULL,
    rule_version_id BIGINT UNSIGNED NOT NULL,
    parser_version VARCHAR(80) NULL,
    ocr_version VARCHAR(80) NULL,
    model_provider VARCHAR(80) NULL,
    model_name VARCHAR(120) NULL,
    prompt_version VARCHAR(80) NULL,
    evidence_validator_version VARCHAR(80) NULL,
    scoring_version VARCHAR(80) NULL,
    content_hash CHAR(64) NOT NULL,
    status ENUM('active', 'superseded') NOT NULL DEFAULT 'active',
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_recruitment_processing_version_hash (document_id, requirement_id, rule_version_id, content_hash),
    KEY idx_recruitment_processing_versions_document (document_id, status),
    KEY idx_recruitment_processing_versions_requirement (requirement_id, rule_version_id, created_at),
    CONSTRAINT fk_recruitment_processing_document FOREIGN KEY (document_id) REFERENCES recruitment_resume_documents (id) ON DELETE RESTRICT,
    CONSTRAINT fk_recruitment_processing_requirement FOREIGN KEY (requirement_id) REFERENCES recruitment_requirements (id) ON DELETE RESTRICT,
    CONSTRAINT fk_recruitment_processing_rule FOREIGN KEY (rule_version_id) REFERENCES recruitment_rule_versions (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recruitment_applications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    candidate_id BIGINT UNSIGNED NOT NULL,
    document_id BIGINT UNSIGNED NOT NULL,
    requirement_id BIGINT UNSIGNED NOT NULL,
    rule_version_id BIGINT UNSIGNED NOT NULL,
    current_processing_version_id BIGINT UNSIGNED NULL,
    extracted_profile_json LONGTEXT NULL,
    highlights_json LONGTEXT NULL,
    system_grade ENUM('A', 'B', 'C') NULL,
    manual_grade ENUM('A', 'B', 'C') NULL,
    effective_grade ENUM('A', 'B', 'C') NULL,
    total_score DECIMAL(5,2) NULL,
    raw_score DECIMAL(5,2) NULL,
    score_adjustment_reason VARCHAR(1000) NULL,
    information_status ENUM('complete', 'needs_confirmation', 'missing_contact') NOT NULL DEFAULT 'needs_confirmation',
    contact_status ENUM('not_contacted', 'calling', 'no_answer', 'scheduled', 'rejected', 'invalid_phone') NOT NULL DEFAULT 'not_contacted',
    contact_note VARCHAR(1000) NULL,
    queue_status ENUM('appointment', 'review_archive') NOT NULL DEFAULT 'review_archive',
    archive_reason ENUM('grade_c', 'parse_failed', 'manual_removed') NULL,
    archived_by BIGINT UNSIGNED NULL,
    archived_at DATETIME NULL,
    restored_by BIGINT UNSIGNED NULL,
    restored_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_recruitment_applications_document_requirement (document_id, requirement_id),
    KEY idx_recruitment_applications_candidate (candidate_id, created_at),
    KEY idx_recruitment_applications_requirement_grade (requirement_id, effective_grade, total_score),
    KEY idx_recruitment_applications_requirement_queue (requirement_id, queue_status, contact_status, updated_at),
    KEY idx_recruitment_applications_rule (rule_version_id, created_at),
    KEY idx_recruitment_applications_processing (current_processing_version_id),
    CONSTRAINT fk_recruitment_applications_candidate FOREIGN KEY (candidate_id) REFERENCES recruitment_candidates (id) ON DELETE RESTRICT,
    CONSTRAINT fk_recruitment_applications_document FOREIGN KEY (document_id) REFERENCES recruitment_resume_documents (id) ON DELETE RESTRICT,
    CONSTRAINT fk_recruitment_applications_requirement FOREIGN KEY (requirement_id) REFERENCES recruitment_requirements (id) ON DELETE RESTRICT,
    CONSTRAINT fk_recruitment_applications_rule FOREIGN KEY (rule_version_id) REFERENCES recruitment_rule_versions (id) ON DELETE RESTRICT,
    CONSTRAINT fk_recruitment_applications_processing FOREIGN KEY (current_processing_version_id) REFERENCES recruitment_processing_versions (id) ON DELETE RESTRICT,
    CONSTRAINT chk_recruitment_applications_total_score CHECK (total_score IS NULL OR (total_score >= 0 AND total_score <= 100)),
    CONSTRAINT chk_recruitment_applications_raw_score CHECK (raw_score IS NULL OR (raw_score >= 0 AND raw_score <= 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recruitment_match_evidence (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id BIGINT UNSIGNED NOT NULL,
    dimension_type ENUM('hard_condition', 'experience', 'keyword', 'transferable_skill') NOT NULL,
    rule_key VARCHAR(120) NOT NULL,
    match_status ENUM('matched', 'unmatched', 'unknown', 'manual_check') NOT NULL,
    score DECIMAL(6,2) NULL,
    source_text VARCHAR(1000) NULL,
    page_no INT UNSIGNED NULL,
    confidence DECIMAL(5,4) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_recruitment_match_evidence_application (application_id, dimension_type),
    KEY idx_recruitment_match_evidence_rule (rule_key, match_status),
    CONSTRAINT fk_recruitment_match_evidence_application FOREIGN KEY (application_id) REFERENCES recruitment_applications (id) ON DELETE RESTRICT,
    CONSTRAINT chk_recruitment_match_evidence_confidence CHECK (confidence IS NULL OR (confidence >= 0 AND confidence <= 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recruitment_candidate_relations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    relation_type ENUM('suspected_duplicate', 'confirmed_duplicate', 'released', 'merged') NOT NULL,
    canonical_candidate_id BIGINT UNSIGNED NOT NULL,
    related_candidate_id BIGINT UNSIGNED NOT NULL,
    before_snapshot_json LONGTEXT NULL,
    reason VARCHAR(1000) NULL,
    operator_staff_id BIGINT UNSIGNED NULL,
    operated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_recruitment_candidate_relation_pair (canonical_candidate_id, related_candidate_id, relation_type),
    KEY idx_recruitment_candidate_relations_related (related_candidate_id, relation_type),
    KEY idx_recruitment_candidate_relations_operator (operator_staff_id, operated_at),
    CONSTRAINT fk_recruitment_relations_canonical FOREIGN KEY (canonical_candidate_id) REFERENCES recruitment_candidates (id) ON DELETE RESTRICT,
    CONSTRAINT fk_recruitment_relations_related FOREIGN KEY (related_candidate_id) REFERENCES recruitment_candidates (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recruitment_grade_reviews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id BIGINT UNSIGNED NOT NULL,
    system_grade ENUM('A', 'B', 'C') NULL,
    manual_grade ENUM('A', 'B', 'C') NOT NULL,
    review_reason VARCHAR(1000) NOT NULL,
    reviewer_staff_id BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_recruitment_grade_reviews_application (application_id, reviewed_at),
    KEY idx_recruitment_grade_reviews_reviewer (reviewer_staff_id, reviewed_at),
    CONSTRAINT fk_recruitment_grade_reviews_application FOREIGN KEY (application_id) REFERENCES recruitment_applications (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recruitment_queue_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id BIGINT UNSIGNED NOT NULL,
    event_type ENUM('archive', 'manual_removed', 'restore', 'appointment') NOT NULL,
    before_status VARCHAR(40) NULL,
    after_status VARCHAR(40) NULL,
    event_reason VARCHAR(1000) NULL,
    operator_staff_id BIGINT UNSIGNED NULL,
    operated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_recruitment_queue_events_application (application_id, operated_at),
    KEY idx_recruitment_queue_events_type (event_type, operated_at),
    CONSTRAINT fk_recruitment_queue_events_application FOREIGN KEY (application_id) REFERENCES recruitment_applications (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recruitment_contact_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id BIGINT UNSIGNED NOT NULL,
    contact_status ENUM('calling', 'no_answer', 'scheduled', 'rejected', 'invalid_phone', 'note') NOT NULL,
    scheduled_at DATETIME NULL,
    contact_note VARCHAR(1000) NULL,
    operator_staff_id BIGINT UNSIGNED NULL,
    contacted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_recruitment_contact_logs_application (application_id, contacted_at),
    KEY idx_recruitment_contact_logs_schedule (scheduled_at, contact_status),
    KEY idx_recruitment_contact_logs_operator (operator_staff_id, contacted_at),
    CONSTRAINT fk_recruitment_contact_logs_application FOREIGN KEY (application_id) REFERENCES recruitment_applications (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recruitment_ai_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    processing_version_id BIGINT UNSIGNED NULL,
    document_id BIGINT UNSIGNED NULL,
    job_id BIGINT UNSIGNED NULL,
    run_type ENUM('ocr', 'extract', 'match', 'grade') NOT NULL,
    provider VARCHAR(80) NULL,
    service_region VARCHAR(80) NULL,
    model VARCHAR(120) NULL,
    prompt_version VARCHAR(80) NULL,
    input_summary_hash CHAR(64) NULL,
    output_summary_hash CHAR(64) NULL,
    duration_ms INT UNSIGNED NULL,
    status ENUM('succeeded', 'failed', 'retryable_failed') NOT NULL,
    error_code VARCHAR(80) NULL,
    error_message VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_recruitment_ai_runs_processing (processing_version_id, run_type, created_at),
    KEY idx_recruitment_ai_runs_document (document_id, run_type, created_at),
    KEY idx_recruitment_ai_runs_job (job_id),
    KEY idx_recruitment_ai_runs_status (status, error_code, created_at),
    CONSTRAINT fk_recruitment_ai_runs_processing FOREIGN KEY (processing_version_id) REFERENCES recruitment_processing_versions (id) ON DELETE RESTRICT,
    CONSTRAINT fk_recruitment_ai_runs_document FOREIGN KEY (document_id) REFERENCES recruitment_resume_documents (id) ON DELETE RESTRICT,
    CONSTRAINT fk_recruitment_ai_runs_job FOREIGN KEY (job_id) REFERENCES recruitment_resume_jobs (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recruitment_extraction_results (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    processing_version_id BIGINT UNSIGNED NOT NULL,
    application_id BIGINT UNSIGNED NULL,
    fields_json LONGTEXT NOT NULL,
    confidence_json LONGTEXT NULL,
    status ENUM('succeeded', 'failed') NOT NULL DEFAULT 'succeeded',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_recruitment_extraction_processing (processing_version_id),
    KEY idx_recruitment_extraction_application (application_id),
    CONSTRAINT fk_recruitment_extraction_processing FOREIGN KEY (processing_version_id) REFERENCES recruitment_processing_versions (id) ON DELETE RESTRICT,
    CONSTRAINT fk_recruitment_extraction_application FOREIGN KEY (application_id) REFERENCES recruitment_applications (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recruitment_model_results (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    processing_version_id BIGINT UNSIGNED NOT NULL,
    application_id BIGINT UNSIGNED NULL,
    model_output_json LONGTEXT NOT NULL,
    evidence_summary_json LONGTEXT NULL,
    status ENUM('succeeded', 'failed') NOT NULL DEFAULT 'succeeded',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_recruitment_model_processing (processing_version_id),
    KEY idx_recruitment_model_application (application_id),
    CONSTRAINT fk_recruitment_model_processing FOREIGN KEY (processing_version_id) REFERENCES recruitment_processing_versions (id) ON DELETE RESTRICT,
    CONSTRAINT fk_recruitment_model_application FOREIGN KEY (application_id) REFERENCES recruitment_applications (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recruitment_grade_results (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    processing_version_id BIGINT UNSIGNED NOT NULL,
    application_id BIGINT UNSIGNED NOT NULL,
    raw_score DECIMAL(5,2) NOT NULL,
    total_score DECIMAL(5,2) NOT NULL,
    system_grade ENUM('A', 'B', 'C') NOT NULL,
    score_adjustment_reason VARCHAR(1000) NULL,
    grade_snapshot_json LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_recruitment_grade_processing (processing_version_id),
    KEY idx_recruitment_grade_application (application_id, created_at),
    CONSTRAINT fk_recruitment_grade_processing FOREIGN KEY (processing_version_id) REFERENCES recruitment_processing_versions (id) ON DELETE RESTRICT,
    CONSTRAINT fk_recruitment_grade_application FOREIGN KEY (application_id) REFERENCES recruitment_applications (id) ON DELETE RESTRICT,
    CONSTRAINT chk_recruitment_grade_raw_score CHECK (raw_score >= 0 AND raw_score <= 100),
    CONSTRAINT chk_recruitment_grade_total_score CHECK (total_score >= 0 AND total_score <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recruitment_export_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    export_no VARCHAR(40) NOT NULL,
    requirement_id BIGINT UNSIGNED NULL,
    batch_id BIGINT UNSIGNED NULL,
    workbook_scope ENUM('requirement', 'batch', 'all') NOT NULL DEFAULT 'requirement',
    status ENUM('pending', 'running', 'completed', 'failed', 'expired') NOT NULL DEFAULT 'pending',
    query_json LONGTEXT NULL,
    column_schema_hash CHAR(64) NOT NULL,
    sort_schema_hash CHAR(64) NOT NULL,
    file_key VARCHAR(255) NULL,
    file_name VARCHAR(255) NULL,
    row_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    failed_at DATETIME NULL,
    failure_message VARCHAR(1000) NULL,
    expires_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_recruitment_export_jobs_no (export_no),
    KEY idx_recruitment_export_jobs_requirement (requirement_id, status, created_at),
    KEY idx_recruitment_export_jobs_batch (batch_id, status, created_at),
    KEY idx_recruitment_export_jobs_creator (created_by, created_at),
    KEY idx_recruitment_export_jobs_expiry (status, expires_at),
    CONSTRAINT fk_recruitment_export_jobs_requirement FOREIGN KEY (requirement_id) REFERENCES recruitment_requirements (id) ON DELETE RESTRICT,
    CONSTRAINT fk_recruitment_export_jobs_batch FOREIGN KEY (batch_id) REFERENCES recruitment_resume_batches (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recruitment_external_processors (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    processor_code VARCHAR(80) NOT NULL,
    processor_name VARCHAR(160) NOT NULL,
    processor_type ENUM('ocr', 'model', 'storage', 'export') NOT NULL,
    provider VARCHAR(120) NOT NULL,
    model_name VARCHAR(160) NULL,
    service_region VARCHAR(120) NOT NULL,
    transport_encryption VARCHAR(160) NOT NULL,
    retention_days INT UNSIGNED NOT NULL DEFAULT 0,
    training_use_allowed TINYINT(1) NOT NULL DEFAULT 0,
    subcontractors_json LONGTEXT NULL,
    deletion_mechanism VARCHAR(500) NULL,
    approval_status ENUM('draft', 'approved', 'disabled') NOT NULL DEFAULT 'draft',
    approved_by BIGINT UNSIGNED NULL,
    approved_at DATETIME NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_recruitment_external_processors_code (processor_code),
    KEY idx_recruitment_external_processors_type (processor_type, approval_status, status),
    KEY idx_recruitment_external_processors_provider (provider, service_region),
    KEY idx_recruitment_external_processors_approval (approved_by, approved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_external_processors' AND COLUMN_NAME = 'model_name'),
    'SELECT 1',
    'ALTER TABLE recruitment_external_processors ADD COLUMN model_name VARCHAR(160) NULL AFTER provider'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_external_processors' AND COLUMN_NAME = 'transport_encryption'),
    'SELECT 1',
    'ALTER TABLE recruitment_external_processors ADD COLUMN transport_encryption VARCHAR(160) NULL AFTER service_region'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

CREATE TABLE IF NOT EXISTS recruitment_retention_policies (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    policy_code VARCHAR(80) NOT NULL,
    data_category ENUM('raw_resume', 'ocr_text', 'structured_profile', 'archive_record', 'ai_result', 'contact_log', 'export_file', 'audit_log') NOT NULL,
    retention_days INT UNSIGNED NOT NULL,
    disposal_action ENUM('delete', 'anonymize', 'archive') NOT NULL DEFAULT 'delete',
    effective_version INT UNSIGNED NOT NULL DEFAULT 1,
    status ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'draft',
    approved_by BIGINT UNSIGNED NULL,
    approved_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_recruitment_retention_policy_version (policy_code, effective_version),
    KEY idx_recruitment_retention_policies_category (data_category, status),
    KEY idx_recruitment_retention_policies_approval (approved_by, approved_at),
    CONSTRAINT chk_recruitment_retention_days CHECK (retention_days > 0),
    CONSTRAINT chk_recruitment_retention_version CHECK (effective_version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE recruitment_retention_policies MODIFY COLUMN data_category ENUM('raw_resume', 'ocr_text', 'structured_profile', 'archive_record', 'ai_result', 'contact_log', 'export_file', 'audit_log') NOT NULL;

CREATE TABLE IF NOT EXISTS recruitment_legal_holds (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    hold_no VARCHAR(40) NOT NULL,
    scope_type ENUM('all', 'requirement', 'batch', 'candidate', 'application', 'export') NOT NULL,
    scope_id BIGINT UNSIGNED NULL,
    legal_basis VARCHAR(500) NOT NULL,
    hold_reason VARCHAR(1000) NOT NULL,
    status ENUM('active', 'released') NOT NULL DEFAULT 'active',
    created_by BIGINT UNSIGNED NULL,
    released_by BIGINT UNSIGNED NULL,
    released_at DATETIME NULL,
    release_reason VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_recruitment_legal_holds_no (hold_no),
    KEY idx_recruitment_legal_holds_scope (scope_type, scope_id, status),
    KEY idx_recruitment_legal_holds_status (status, created_at),
    KEY idx_recruitment_legal_holds_release (released_by, released_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recruitment_disposal_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    disposal_no VARCHAR(40) NOT NULL,
    policy_id BIGINT UNSIGNED NOT NULL,
    data_category ENUM('raw_resume', 'ocr_text', 'structured_profile', 'archive_record', 'ai_result', 'contact_log', 'export_file', 'audit_log') NOT NULL,
    scope_type ENUM('all', 'requirement', 'batch', 'candidate', 'application', 'export') NOT NULL DEFAULT 'all',
    scope_id BIGINT UNSIGNED NULL,
    scan_status ENUM('pending', 'running', 'completed', 'failed') NOT NULL DEFAULT 'pending',
    scanned_count INT UNSIGNED NOT NULL DEFAULT 0,
    blocked_by_hold_count INT UNSIGNED NOT NULL DEFAULT 0,
    approval_status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    approved_by BIGINT UNSIGNED NULL,
    approved_at DATETIME NULL,
    execution_status ENUM('pending', 'running', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
    executed_count INT UNSIGNED NOT NULL DEFAULT 0,
    supplier_delete_confirmed_at DATETIME NULL,
    backup_disposal_status ENUM('not_required', 'pending', 'completed', 'failed') NOT NULL DEFAULT 'not_required',
    retry_count INT UNSIGNED NOT NULL DEFAULT 0,
    failure_message VARCHAR(1000) NULL,
    created_by BIGINT UNSIGNED NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_recruitment_disposal_jobs_no (disposal_no),
    KEY idx_recruitment_disposal_jobs_policy (policy_id, execution_status, created_at),
    KEY idx_recruitment_disposal_jobs_scope (scope_type, scope_id, data_category),
    KEY idx_recruitment_disposal_jobs_approval (approval_status, approved_by, approved_at),
    KEY idx_recruitment_disposal_jobs_retry (execution_status, retry_count, updated_at),
    CONSTRAINT fk_recruitment_disposal_jobs_policy FOREIGN KEY (policy_id) REFERENCES recruitment_retention_policies (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE recruitment_disposal_jobs MODIFY COLUMN data_category ENUM('raw_resume', 'ocr_text', 'structured_profile', 'archive_record', 'ai_result', 'contact_log', 'export_file', 'audit_log') NOT NULL;
