SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS recruitment_mixed_batch_routing_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    parent_batch_id BIGINT UNSIGNED NOT NULL,
    resume_file_id BIGINT UNSIGNED NOT NULL,
    document_id BIGINT UNSIGNED NULL,
    identified_position_id BIGINT UNSIGNED NULL,
    identified_position_name_snapshot VARCHAR(120) NOT NULL DEFAULT '',
    target_requirement_id BIGINT UNSIGNED NULL,
    candidate_requirement_ids_json LONGTEXT NULL,
    routing_status ENUM('routed', 'position_confirmation_required', 'manual_confirmation_required', 'failed') NOT NULL DEFAULT 'manual_confirmation_required',
    evidence_json LONGTEXT NULL,
    confidence DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
    routing_version VARCHAR(40) NOT NULL DEFAULT 'position-routing-v1',
    manual_reason VARCHAR(1000) NULL,
    confirmed_by BIGINT UNSIGNED NULL,
    confirmed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_recruitment_mixed_route_file (parent_batch_id, resume_file_id),
    KEY idx_recruitment_mixed_route_batch_status (parent_batch_id, routing_status, identified_position_name_snapshot),
    KEY idx_recruitment_mixed_route_document (document_id),
    KEY idx_recruitment_mixed_route_target_requirement (target_requirement_id, routing_status),
    KEY idx_recruitment_mixed_route_confirmer (confirmed_by, confirmed_at),
    CONSTRAINT fk_recruitment_mixed_route_batch FOREIGN KEY (parent_batch_id) REFERENCES recruitment_resume_batches (id) ON DELETE RESTRICT,
    CONSTRAINT fk_recruitment_mixed_route_file FOREIGN KEY (resume_file_id) REFERENCES recruitment_resume_files (id) ON DELETE RESTRICT,
    CONSTRAINT fk_recruitment_mixed_route_document FOREIGN KEY (document_id) REFERENCES recruitment_resume_documents (id) ON DELETE RESTRICT,
    CONSTRAINT fk_recruitment_mixed_route_target_requirement FOREIGN KEY (target_requirement_id) REFERENCES recruitment_requirements (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recruitment_mixed_batch_routing_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    routing_record_id BIGINT UNSIGNED NOT NULL,
    event_type ENUM('created', 'auto_routed', 'manual_confirmed', 'adjusted', 'failed') NOT NULL,
    before_json LONGTEXT NULL,
    after_json LONGTEXT NULL,
    event_reason VARCHAR(1000) NOT NULL DEFAULT '',
    operator_staff_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_recruitment_mixed_route_events_record (routing_record_id, created_at),
    KEY idx_recruitment_mixed_route_events_operator (operator_staff_id, created_at),
    CONSTRAINT fk_recruitment_mixed_route_events_record FOREIGN KEY (routing_record_id) REFERENCES recruitment_mixed_batch_routing_records (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
