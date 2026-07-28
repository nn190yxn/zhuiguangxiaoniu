SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_process_versions' AND INDEX_NAME = 'uk_drill_process_versions_id_domain'),
    'SELECT 1',
    'ALTER TABLE drill_process_versions ADD UNIQUE KEY uk_drill_process_versions_id_domain (id, domain_id)'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_process_stages' AND INDEX_NAME = 'uk_drill_process_stages_id_version'),
    'SELECT 1',
    'ALTER TABLE drill_process_stages ADD UNIQUE KEY uk_drill_process_stages_id_version (id, process_version_id)'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_reference_material_versions' AND COLUMN_NAME = 'content_snapshot_json'),
    'SELECT 1',
    'ALTER TABLE drill_reference_material_versions ADD COLUMN content_snapshot_json JSON NULL AFTER source_name'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_reference_material_versions' AND COLUMN_NAME = 'review_summary_json'),
    'SELECT 1',
    'ALTER TABLE drill_reference_material_versions ADD COLUMN review_summary_json JSON NULL AFTER content_hash'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

CREATE TABLE IF NOT EXISTS `drill_rubric_stage_mappings` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain_id` BIGINT UNSIGNED NOT NULL,
    `rubric_id` BIGINT UNSIGNED NOT NULL,
    `rubric_version_id` BIGINT UNSIGNED NOT NULL,
    `dimension_code` VARCHAR(64) NOT NULL,
    `process_version_id` BIGINT UNSIGNED NOT NULL,
    `process_stage_id` BIGINT UNSIGNED NOT NULL,
    `mapping_weight` DECIMAL(5, 4) NOT NULL DEFAULT 1.0000,
    `source_type` VARCHAR(64) NOT NULL,
    `source_ref` VARCHAR(255) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_drill_rubric_stage_mapping` (`rubric_version_id`, `dimension_code`, `process_stage_id`),
    KEY `idx_drill_rubric_stage_mapping_stage` (`process_version_id`, `process_stage_id`, `rubric_version_id`),
    KEY `idx_drill_rubric_stage_mapping_domain` (`domain_id`, `rubric_version_id`),
    CONSTRAINT `fk_drill_rubric_stage_mapping_rubric` FOREIGN KEY (`rubric_id`, `domain_id`) REFERENCES `drill_rubrics` (`id`, `domain_id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_drill_rubric_stage_mapping_version` FOREIGN KEY (`rubric_version_id`, `rubric_id`) REFERENCES `drill_rubric_versions` (`id`, `rubric_id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_drill_rubric_stage_mapping_process` FOREIGN KEY (`process_version_id`, `domain_id`) REFERENCES `drill_process_versions` (`id`, `domain_id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_drill_rubric_stage_mapping_stage` FOREIGN KEY (`process_stage_id`, `process_version_id`) REFERENCES `drill_process_stages` (`id`, `process_version_id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_drill_rubric_stage_mapping_weight` CHECK (`mapping_weight` > 0 AND `mapping_weight` <= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drill_content_import_batches` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain_id` BIGINT UNSIGNED NOT NULL,
    `batch_code` VARCHAR(96) NOT NULL,
    `source_name` VARCHAR(255) NOT NULL,
    `source_hash` CHAR(64) NOT NULL,
    `status` ENUM('importing', 'review_pending', 'approved', 'rejected', 'failed') NOT NULL DEFAULT 'importing',
    `summary_json` JSON NOT NULL,
    `imported_by_staff_id` BIGINT UNSIGNED NOT NULL,
    `completed_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_drill_content_import_batches_code` (`batch_code`),
    KEY `idx_drill_content_import_batches_domain_status` (`domain_id`, `status`, `created_at`),
    CONSTRAINT `fk_drill_content_import_batches_domain` FOREIGN KEY (`domain_id`) REFERENCES `drill_training_domains` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_drill_content_import_batches_completed` CHECK ((`status` IN ('review_pending', 'approved', 'rejected', 'failed') AND `completed_at` IS NOT NULL) OR (`status` = 'importing'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drill_content_import_items` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `batch_id` BIGINT UNSIGNED NOT NULL,
    `domain_id` BIGINT UNSIGNED NOT NULL,
    `content_type` ENUM('persona', 'scenario', 'rubric', 'rubric_mapping', 'reference_material', 'calibration') NOT NULL,
    `stable_code` VARCHAR(96) NOT NULL,
    `target_id` BIGINT UNSIGNED DEFAULT NULL,
    `target_version_id` BIGINT UNSIGNED DEFAULT NULL,
    `review_status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    `source_ref` VARCHAR(500) NOT NULL,
    `payload_hash` CHAR(64) NOT NULL,
    `source_snapshot_json` JSON NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_drill_content_import_items_identity` (`batch_id`, `content_type`, `stable_code`),
    KEY `idx_drill_content_import_items_review` (`domain_id`, `review_status`, `content_type`),
    CONSTRAINT `fk_drill_content_import_items_batch` FOREIGN KEY (`batch_id`) REFERENCES `drill_content_import_batches` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_drill_content_import_items_domain` FOREIGN KEY (`domain_id`) REFERENCES `drill_training_domains` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drill_content_review_issues` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `batch_id` BIGINT UNSIGNED NOT NULL,
    `item_id` BIGINT UNSIGNED DEFAULT NULL,
    `domain_id` BIGINT UNSIGNED NOT NULL,
    `issue_code` VARCHAR(96) NOT NULL,
    `issue_category` ENUM('business_number', 'effect_claim', 'authorization', 'validity', 'source_conflict') NOT NULL,
    `severity` ENUM('warning', 'blocking') NOT NULL DEFAULT 'blocking',
    `subject` VARCHAR(255) NOT NULL,
    `details_json` JSON NOT NULL,
    `issue_fingerprint` CHAR(64) NOT NULL,
    `status` ENUM('open', 'resolved', 'accepted_risk') NOT NULL DEFAULT 'open',
    `resolved_by_staff_id` BIGINT UNSIGNED DEFAULT NULL,
    `resolution_note` VARCHAR(1000) DEFAULT NULL,
    `resolved_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_drill_content_review_issues_fingerprint` (`batch_id`, `issue_fingerprint`),
    KEY `idx_drill_content_review_issues_status` (`domain_id`, `status`, `severity`),
    CONSTRAINT `fk_drill_content_review_issues_batch` FOREIGN KEY (`batch_id`) REFERENCES `drill_content_import_batches` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_drill_content_review_issues_item` FOREIGN KEY (`item_id`) REFERENCES `drill_content_import_items` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_drill_content_review_issues_domain` FOREIGN KEY (`domain_id`) REFERENCES `drill_training_domains` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_drill_content_review_issues_resolution` CHECK ((`status` = 'open' AND `resolved_by_staff_id` IS NULL AND `resolved_at` IS NULL) OR (`status` <> 'open' AND `resolved_by_staff_id` IS NOT NULL AND `resolution_note` IS NOT NULL AND `resolved_at` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
