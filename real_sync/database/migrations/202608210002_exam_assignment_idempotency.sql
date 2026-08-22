-- Stable idempotency records for mini program exam assignment.

CREATE TABLE IF NOT EXISTS exam_assignment_idempotency (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    source_exam_id BIGINT UNSIGNED NOT NULL,
    idempotency_key_hash CHAR(64) NOT NULL,
    response_json LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_exam_assignment_idempotency (user_id, source_exam_id, idempotency_key_hash),
    KEY idx_exam_assignment_source (source_exam_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
