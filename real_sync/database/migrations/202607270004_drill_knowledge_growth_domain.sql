-- Drill knowledge graph, learning, mastery, growth, and calibration domain.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `drill_knowledge_points` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain_id` BIGINT UNSIGNED NOT NULL,
    `knowledge_code` VARCHAR(64) NOT NULL,
    `name` VARCHAR(160) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `status` ENUM('active', 'inactive', 'archived') NOT NULL DEFAULT 'active',
    `created_by_staff_id` BIGINT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_drill_knowledge_points_domain_code` (`domain_id`, `knowledge_code`),
    UNIQUE KEY `uk_drill_knowledge_points_id_domain` (`id`, `domain_id`),
    CONSTRAINT `fk_drill_knowledge_points_domain` FOREIGN KEY (`domain_id`) REFERENCES `drill_training_domains` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drill_knowledge_point_versions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `knowledge_point_id` BIGINT UNSIGNED NOT NULL,
    `domain_id` BIGINT UNSIGNED NOT NULL,
    `version_no` INT UNSIGNED NOT NULL,
    `title` VARCHAR(160) NOT NULL,
    `content_json` JSON NOT NULL,
    `content_hash` CHAR(64) NOT NULL,
    `status` ENUM('draft', 'review_pending', 'published', 'retired') NOT NULL DEFAULT 'draft',
    `review_status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    `published_by_staff_id` BIGINT UNSIGNED DEFAULT NULL,
    `published_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_drill_knowledge_point_versions_no` (`knowledge_point_id`, `version_no`),
    UNIQUE KEY `uk_drill_knowledge_point_versions_scope` (`id`, `knowledge_point_id`, `domain_id`),
    CONSTRAINT `fk_drill_knowledge_point_versions_point` FOREIGN KEY (`knowledge_point_id`, `domain_id`) REFERENCES `drill_knowledge_points` (`id`, `domain_id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_drill_knowledge_point_published` CHECK ((`status` = 'published' AND `review_status` = 'approved' AND `published_by_staff_id` IS NOT NULL AND `published_at` IS NOT NULL) OR (`status` <> 'published'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drill_learning_resources` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain_id` BIGINT UNSIGNED NOT NULL,
    `resource_code` VARCHAR(64) NOT NULL,
    `name` VARCHAR(160) NOT NULL,
    `resource_type` ENUM('article', 'audio', 'video', 'card', 'course', 'exercise') NOT NULL,
    `status` ENUM('active', 'inactive', 'archived') NOT NULL DEFAULT 'active',
    `created_by_staff_id` BIGINT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_drill_learning_resources_domain_code` (`domain_id`, `resource_code`),
    UNIQUE KEY `uk_drill_learning_resources_id_domain` (`id`, `domain_id`),
    CONSTRAINT `fk_drill_learning_resources_domain` FOREIGN KEY (`domain_id`) REFERENCES `drill_training_domains` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drill_learning_resource_versions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `learning_resource_id` BIGINT UNSIGNED NOT NULL,
    `domain_id` BIGINT UNSIGNED NOT NULL,
    `version_code` VARCHAR(64) NOT NULL,
    `title` VARCHAR(160) NOT NULL,
    `mobile_locator` VARCHAR(500) DEFAULT NULL,
    `content_json` JSON NOT NULL,
    `content_hash` CHAR(64) NOT NULL,
    `estimated_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `status` ENUM('draft', 'review_pending', 'published', 'retired') NOT NULL DEFAULT 'draft',
    `review_status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    `published_by_staff_id` BIGINT UNSIGNED DEFAULT NULL,
    `published_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_drill_learning_resource_versions_code` (`learning_resource_id`, `version_code`),
    UNIQUE KEY `uk_drill_learning_resource_versions_scope` (`id`, `learning_resource_id`, `domain_id`),
    CONSTRAINT `fk_drill_learning_resource_versions_resource` FOREIGN KEY (`learning_resource_id`, `domain_id`) REFERENCES `drill_learning_resources` (`id`, `domain_id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_drill_learning_resource_published` CHECK ((`status` = 'published' AND `review_status` = 'approved' AND `mobile_locator` IS NOT NULL AND `published_by_staff_id` IS NOT NULL AND `published_at` IS NOT NULL) OR (`status` <> 'published')),
    CONSTRAINT `chk_drill_learning_resource_minutes` CHECK (`estimated_minutes` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_rubrics' AND INDEX_NAME = 'uk_drill_rubrics_id_domain'),
    'SELECT 1',
    'ALTER TABLE drill_rubrics ADD UNIQUE KEY uk_drill_rubrics_id_domain (id, domain_id)'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_rubric_versions' AND INDEX_NAME = 'uk_drill_rubric_versions_id_rubric'),
    'SELECT 1',
    'ALTER TABLE drill_rubric_versions ADD UNIQUE KEY uk_drill_rubric_versions_id_rubric (id, rubric_id)'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

CREATE TABLE IF NOT EXISTS `drill_knowledge_mapping_versions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain_id` BIGINT UNSIGNED NOT NULL,
    `rubric_id` BIGINT UNSIGNED NOT NULL,
    `rubric_version_id` BIGINT UNSIGNED NOT NULL,
    `version_no` INT UNSIGNED NOT NULL,
    `expected_reinforceable_criteria` INT UNSIGNED NOT NULL,
    `mapped_reinforceable_criteria` INT UNSIGNED NOT NULL DEFAULT 0,
    `mapped_knowledge_points` INT UNSIGNED NOT NULL DEFAULT 0,
    `mobile_resource_ready_points` INT UNSIGNED NOT NULL DEFAULT 0,
    `mapping_hash` CHAR(64) NOT NULL,
    `status` ENUM('draft', 'review_pending', 'published', 'retired') NOT NULL DEFAULT 'draft',
    `published_by_staff_id` BIGINT UNSIGNED DEFAULT NULL,
    `published_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_drill_knowledge_mapping_versions_no` (`rubric_version_id`, `version_no`),
    UNIQUE KEY `uk_drill_knowledge_mapping_versions_domain` (`id`, `domain_id`),
    UNIQUE KEY `uk_drill_knowledge_mapping_versions_scope` (`id`, `domain_id`, `rubric_version_id`),
    KEY `idx_drill_knowledge_mapping_versions_status` (`domain_id`, `status`, `published_at`),
    CONSTRAINT `fk_drill_knowledge_mapping_versions_domain` FOREIGN KEY (`domain_id`) REFERENCES `drill_training_domains` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_drill_knowledge_mapping_versions_rubric` FOREIGN KEY (`rubric_id`, `domain_id`) REFERENCES `drill_rubrics` (`id`, `domain_id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_drill_knowledge_mapping_versions_rubric_version` FOREIGN KEY (`rubric_version_id`, `rubric_id`) REFERENCES `drill_rubric_versions` (`id`, `rubric_id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_drill_knowledge_mapping_counts` CHECK (`mapped_reinforceable_criteria` <= `expected_reinforceable_criteria` AND `mobile_resource_ready_points` <= `mapped_knowledge_points`),
    CONSTRAINT `chk_drill_knowledge_mapping_published` CHECK ((`status` = 'published' AND `expected_reinforceable_criteria` > 0 AND `mapped_reinforceable_criteria` = `expected_reinforceable_criteria` AND `mapped_knowledge_points` > 0 AND `mobile_resource_ready_points` = `mapped_knowledge_points` AND `published_by_staff_id` IS NOT NULL AND `published_at` IS NOT NULL) OR (`status` <> 'published'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drill_rubric_knowledge_links` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `mapping_version_id` BIGINT UNSIGNED NOT NULL,
    `domain_id` BIGINT UNSIGNED NOT NULL,
    `rubric_version_id` BIGINT UNSIGNED NOT NULL,
    `dimension_code` VARCHAR(64) NOT NULL,
    `criterion_code` VARCHAR(64) NOT NULL,
    `knowledge_point_id` BIGINT UNSIGNED NOT NULL,
    `knowledge_point_version_id` BIGINT UNSIGNED NOT NULL,
    `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_drill_rubric_knowledge_links_point` (`mapping_version_id`, `criterion_code`, `knowledge_point_id`),
    UNIQUE KEY `uk_drill_rubric_knowledge_links_version` (`mapping_version_id`, `criterion_code`, `knowledge_point_id`, `knowledge_point_version_id`),
    KEY `idx_drill_rubric_knowledge_links_lookup` (`rubric_version_id`, `dimension_code`, `criterion_code`),
    CONSTRAINT `fk_drill_rubric_knowledge_links_mapping` FOREIGN KEY (`mapping_version_id`, `domain_id`, `rubric_version_id`) REFERENCES `drill_knowledge_mapping_versions` (`id`, `domain_id`, `rubric_version_id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_drill_rubric_knowledge_links_point` FOREIGN KEY (`knowledge_point_version_id`, `knowledge_point_id`, `domain_id`) REFERENCES `drill_knowledge_point_versions` (`id`, `knowledge_point_id`, `domain_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drill_knowledge_resource_links` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `mapping_version_id` BIGINT UNSIGNED NOT NULL,
    `domain_id` BIGINT UNSIGNED NOT NULL,
    `knowledge_point_id` BIGINT UNSIGNED NOT NULL,
    `knowledge_point_version_id` BIGINT UNSIGNED NOT NULL,
    `learning_resource_id` BIGINT UNSIGNED NOT NULL,
    `learning_resource_version_id` BIGINT UNSIGNED NOT NULL,
    `priority` SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_drill_knowledge_resource_links_version` (`mapping_version_id`, `knowledge_point_id`, `learning_resource_id`, `learning_resource_version_id`),
    KEY `idx_drill_knowledge_resource_links_point` (`knowledge_point_version_id`, `priority`),
    CONSTRAINT `fk_drill_knowledge_resource_links_mapping` FOREIGN KEY (`mapping_version_id`, `domain_id`) REFERENCES `drill_knowledge_mapping_versions` (`id`, `domain_id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_drill_knowledge_resource_links_point` FOREIGN KEY (`knowledge_point_version_id`, `knowledge_point_id`, `domain_id`) REFERENCES `drill_knowledge_point_versions` (`id`, `knowledge_point_id`, `domain_id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_drill_knowledge_resource_links_resource` FOREIGN KEY (`learning_resource_version_id`, `learning_resource_id`, `domain_id`) REFERENCES `drill_learning_resource_versions` (`id`, `learning_resource_id`, `domain_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drill_content_gaps` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain_id` BIGINT UNSIGNED NOT NULL,
    `mapping_version_id` BIGINT UNSIGNED NOT NULL,
    `rubric_version_id` BIGINT UNSIGNED NOT NULL,
    `dimension_code` VARCHAR(64) NOT NULL,
    `criterion_code` VARCHAR(64) NOT NULL,
    `knowledge_point_id` BIGINT UNSIGNED DEFAULT NULL,
    `gap_type` ENUM('missing_knowledge', 'missing_mobile_resource', 'invalid_resource', 'mapping_incomplete') NOT NULL,
    `status` ENUM('open', 'resolved', 'waived') NOT NULL DEFAULT 'open',
    `details_json` JSON NOT NULL,
    `resolved_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_drill_content_gaps_status` (`domain_id`, `status`, `gap_type`),
    CONSTRAINT `fk_drill_content_gaps_mapping` FOREIGN KEY (`mapping_version_id`, `domain_id`, `rubric_version_id`) REFERENCES `drill_knowledge_mapping_versions` (`id`, `domain_id`, `rubric_version_id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_drill_content_gaps_point` FOREIGN KEY (`knowledge_point_id`, `domain_id`) REFERENCES `drill_knowledge_points` (`id`, `domain_id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_drill_content_gaps_resolved` CHECK ((`status` = 'resolved' AND `resolved_at` IS NOT NULL) OR (`status` <> 'resolved'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drill_reference_materials` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain_id` BIGINT UNSIGNED NOT NULL,
    `material_code` VARCHAR(64) NOT NULL,
    `name` VARCHAR(160) NOT NULL,
    `material_type` VARCHAR(64) NOT NULL,
    `status` ENUM('active', 'inactive', 'archived') NOT NULL DEFAULT 'active',
    `created_by_staff_id` BIGINT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_drill_reference_materials_domain_code` (`domain_id`, `material_code`),
    UNIQUE KEY `uk_drill_reference_materials_id_domain` (`id`, `domain_id`),
    CONSTRAINT `fk_drill_reference_materials_domain` FOREIGN KEY (`domain_id`) REFERENCES `drill_training_domains` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drill_reference_material_versions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `reference_material_id` BIGINT UNSIGNED NOT NULL,
    `domain_id` BIGINT UNSIGNED NOT NULL,
    `version_code` VARCHAR(64) NOT NULL,
    `title` VARCHAR(160) NOT NULL,
    `source_locator` VARCHAR(500) NOT NULL,
    `source_name` VARCHAR(255) NOT NULL,
    `content_hash` CHAR(64) NOT NULL,
    `authorization_status` ENUM('pending', 'authorized', 'rejected', 'expired') NOT NULL DEFAULT 'pending',
    `authorization_reference` VARCHAR(500) DEFAULT NULL,
    `effective_from` DATETIME DEFAULT NULL,
    `effective_until` DATETIME DEFAULT NULL,
    `status` ENUM('draft', 'review_pending', 'published', 'retired') NOT NULL DEFAULT 'draft',
    `published_by_staff_id` BIGINT UNSIGNED DEFAULT NULL,
    `published_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_drill_reference_material_versions_code` (`reference_material_id`, `version_code`),
    UNIQUE KEY `uk_drill_reference_material_versions_scope` (`id`, `reference_material_id`, `domain_id`),
    KEY `idx_drill_reference_material_versions_validity` (`domain_id`, `status`, `effective_from`, `effective_until`),
    CONSTRAINT `fk_drill_reference_material_versions_material` FOREIGN KEY (`reference_material_id`, `domain_id`) REFERENCES `drill_reference_materials` (`id`, `domain_id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_drill_reference_material_validity` CHECK (`effective_until` IS NULL OR (`effective_from` IS NOT NULL AND `effective_until` > `effective_from`)),
    CONSTRAINT `chk_drill_reference_material_published` CHECK ((`status` = 'published' AND `authorization_status` = 'authorized' AND `authorization_reference` IS NOT NULL AND `effective_from` IS NOT NULL AND `effective_until` IS NOT NULL AND `published_by_staff_id` IS NOT NULL AND `published_at` >= `effective_from` AND `published_at` < `effective_until`) OR (`status` <> 'published'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_attempts' AND INDEX_NAME = 'uk_drill_attempts_mastery_scope'),
    'SELECT 1',
    'ALTER TABLE drill_attempts ADD UNIQUE KEY uk_drill_attempts_mastery_scope (id, staff_id, domain_id, rubric_version_id)'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_attempts' AND COLUMN_NAME = 'rubric_id'),
    'SELECT 1',
    'ALTER TABLE drill_attempts ADD COLUMN rubric_id BIGINT UNSIGNED NULL AFTER domain_id'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

UPDATE `drill_attempts` attempt
INNER JOIN `drill_rubric_versions` rubric_version ON rubric_version.id = attempt.rubric_version_id
SET attempt.rubric_id = rubric_version.rubric_id
WHERE attempt.rubric_id IS NULL;

ALTER TABLE `drill_attempts` MODIFY COLUMN `rubric_id` BIGINT UNSIGNED NOT NULL;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_attempts' AND INDEX_NAME = 'uk_drill_attempts_rubric_version'),
    'SELECT 1',
    'ALTER TABLE drill_attempts ADD UNIQUE KEY uk_drill_attempts_rubric_version (id, rubric_version_id)'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_attempts' AND CONSTRAINT_NAME = 'fk_drill_attempts_rubric_domain'),
    'SELECT 1',
    'ALTER TABLE drill_attempts ADD CONSTRAINT fk_drill_attempts_rubric_domain FOREIGN KEY (rubric_id, domain_id) REFERENCES drill_rubrics (id, domain_id) ON DELETE RESTRICT'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_attempts' AND CONSTRAINT_NAME = 'fk_drill_attempts_rubric_identity'),
    'SELECT 1',
    'ALTER TABLE drill_attempts ADD CONSTRAINT fk_drill_attempts_rubric_identity FOREIGN KEY (rubric_version_id, rubric_id) REFERENCES drill_rubric_versions (id, rubric_id) ON DELETE RESTRICT'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_evaluations' AND INDEX_NAME = 'uk_drill_evaluations_version_scope'),
    'SELECT 1',
    'ALTER TABLE drill_evaluations ADD UNIQUE KEY uk_drill_evaluations_version_scope (id, attempt_id, rubric_version_id)'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_evaluations' AND CONSTRAINT_NAME = 'fk_drill_evaluations_attempt_rubric'),
    'SELECT 1',
    'ALTER TABLE drill_evaluations ADD CONSTRAINT fk_drill_evaluations_attempt_rubric FOREIGN KEY (attempt_id, rubric_version_id) REFERENCES drill_attempts (id, rubric_version_id) ON DELETE RESTRICT'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_evaluation_evidence' AND INDEX_NAME = 'uk_drill_evaluation_evidence_version_scope'),
    'SELECT 1',
    'ALTER TABLE drill_evaluation_evidence ADD UNIQUE KEY uk_drill_evaluation_evidence_version_scope (id, evaluation_id, attempt_id, rubric_version_id)'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

CREATE TABLE IF NOT EXISTS `drill_learning_recommendations` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `staff_id` BIGINT UNSIGNED NOT NULL,
    `domain_id` BIGINT UNSIGNED NOT NULL,
    `attempt_id` BIGINT UNSIGNED NOT NULL,
    `evaluation_id` BIGINT UNSIGNED NOT NULL,
    `evidence_id` BIGINT UNSIGNED NOT NULL,
    `mapping_version_id` BIGINT UNSIGNED NOT NULL,
    `rubric_version_id` BIGINT UNSIGNED NOT NULL,
    `criterion_code` VARCHAR(64) NOT NULL,
    `knowledge_point_id` BIGINT UNSIGNED NOT NULL,
    `knowledge_point_version_id` BIGINT UNSIGNED NOT NULL,
    `learning_resource_id` BIGINT UNSIGNED NOT NULL,
    `learning_resource_version_id` BIGINT UNSIGNED NOT NULL,
    `reason_snapshot_json` JSON NOT NULL,
    `status` ENUM('recommended', 'started', 'completed', 'dismissed') NOT NULL DEFAULT 'recommended',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_drill_learning_recommendations_resource` (`attempt_id`, `criterion_code`, `knowledge_point_id`, `learning_resource_version_id`),
    KEY `idx_drill_learning_recommendations_staff` (`staff_id`, `domain_id`, `status`, `created_at`),
    CONSTRAINT `fk_drill_learning_recommendations_attempt` FOREIGN KEY (`attempt_id`, `staff_id`, `domain_id`, `rubric_version_id`) REFERENCES `drill_attempts` (`id`, `staff_id`, `domain_id`, `rubric_version_id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_drill_learning_recommendations_evaluation` FOREIGN KEY (`evaluation_id`, `attempt_id`, `rubric_version_id`) REFERENCES `drill_evaluations` (`id`, `attempt_id`, `rubric_version_id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_drill_learning_recommendations_evidence` FOREIGN KEY (`evidence_id`, `evaluation_id`, `attempt_id`, `rubric_version_id`) REFERENCES `drill_evaluation_evidence` (`id`, `evaluation_id`, `attempt_id`, `rubric_version_id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_drill_learning_recommendations_mapping` FOREIGN KEY (`mapping_version_id`, `domain_id`, `rubric_version_id`) REFERENCES `drill_knowledge_mapping_versions` (`id`, `domain_id`, `rubric_version_id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_drill_learning_recommendations_criterion_point` FOREIGN KEY (`mapping_version_id`, `criterion_code`, `knowledge_point_id`, `knowledge_point_version_id`) REFERENCES `drill_rubric_knowledge_links` (`mapping_version_id`, `criterion_code`, `knowledge_point_id`, `knowledge_point_version_id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_drill_learning_recommendations_mapped_resource` FOREIGN KEY (`mapping_version_id`, `knowledge_point_id`, `learning_resource_id`, `learning_resource_version_id`) REFERENCES `drill_knowledge_resource_links` (`mapping_version_id`, `knowledge_point_id`, `learning_resource_id`, `learning_resource_version_id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_drill_learning_recommendations_resource` FOREIGN KEY (`learning_resource_version_id`, `learning_resource_id`, `domain_id`) REFERENCES `drill_learning_resource_versions` (`id`, `learning_resource_id`, `domain_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drill_learning_progress` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `staff_id` BIGINT UNSIGNED NOT NULL,
    `domain_id` BIGINT UNSIGNED NOT NULL,
    `learning_resource_id` BIGINT UNSIGNED NOT NULL,
    `learning_resource_version_id` BIGINT UNSIGNED NOT NULL,
    `recommendation_id` BIGINT UNSIGNED DEFAULT NULL,
    `status` ENUM('not_started', 'in_progress', 'completed') NOT NULL DEFAULT 'not_started',
    `progress_percent` DECIMAL(5, 2) NOT NULL DEFAULT 0,
    `started_at` DATETIME DEFAULT NULL,
    `completed_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_drill_learning_progress_staff_resource` (`staff_id`, `learning_resource_version_id`),
    KEY `idx_drill_learning_progress_domain_status` (`domain_id`, `status`, `updated_at`),
    CONSTRAINT `fk_drill_learning_progress_resource` FOREIGN KEY (`learning_resource_version_id`, `learning_resource_id`, `domain_id`) REFERENCES `drill_learning_resource_versions` (`id`, `learning_resource_id`, `domain_id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_drill_learning_progress_recommendation` FOREIGN KEY (`recommendation_id`) REFERENCES `drill_learning_recommendations` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_drill_learning_progress_percent` CHECK (`progress_percent` >= 0 AND `progress_percent` <= 100),
    CONSTRAINT `chk_drill_learning_progress_completed` CHECK ((`status` = 'completed' AND `progress_percent` = 100 AND `completed_at` IS NOT NULL) OR (`status` <> 'completed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drill_score_calibration_versions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain_id` BIGINT UNSIGNED NOT NULL,
    `rubric_id` BIGINT UNSIGNED NOT NULL,
    `rubric_version_id` BIGINT UNSIGNED NOT NULL,
    `evaluation_context` ENUM('ai_roleplay', 'training_demo', 'real_call_review') NOT NULL,
    `version_no` INT UNSIGNED NOT NULL,
    `test_sample_snapshot_json` JSON NOT NULL,
    `human_benchmark_snapshot_json` JSON NOT NULL,
    `weight_changes_json` JSON NOT NULL,
    `threshold_changes_json` JSON NOT NULL,
    `version_notes` TEXT NOT NULL,
    `sample_size` INT UNSIGNED NOT NULL,
    `agreement_rate` DECIMAL(5, 4) NOT NULL,
    `status` ENUM('draft', 'validated', 'published', 'retired') NOT NULL DEFAULT 'draft',
    `published_by_staff_id` BIGINT UNSIGNED DEFAULT NULL,
    `published_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_drill_score_calibrations_no` (`rubric_version_id`, `evaluation_context`, `version_no`),
    UNIQUE KEY `uk_drill_score_calibrations_scope` (`id`, `domain_id`, `rubric_version_id`),
    UNIQUE KEY `uk_drill_score_calibrations_rubric` (`id`, `rubric_version_id`),
    CONSTRAINT `fk_drill_score_calibrations_rubric` FOREIGN KEY (`rubric_id`, `domain_id`) REFERENCES `drill_rubrics` (`id`, `domain_id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_drill_score_calibrations_rubric_version` FOREIGN KEY (`rubric_version_id`, `rubric_id`) REFERENCES `drill_rubric_versions` (`id`, `rubric_id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_drill_score_calibrations_agreement` CHECK (`agreement_rate` >= 0 AND `agreement_rate` <= 1),
    CONSTRAINT `chk_drill_score_calibrations_published` CHECK ((`status` = 'published' AND `sample_size` > 0 AND `published_by_staff_id` IS NOT NULL AND `published_at` IS NOT NULL) OR (`status` <> 'published'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drill_mastery_scores` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `staff_id` BIGINT UNSIGNED NOT NULL,
    `domain_id` BIGINT UNSIGNED NOT NULL,
    `scope_type` ENUM('required_section', 'full_process') NOT NULL,
    `scope_key` VARCHAR(64) NOT NULL,
    `rubric_id` BIGINT UNSIGNED NOT NULL,
    `rubric_version_id` BIGINT UNSIGNED NOT NULL,
    `latest_attempt_id` BIGINT UNSIGNED NOT NULL,
    `latest_score` DECIMAL(5, 2) NOT NULL,
    `latest_scored_at` DATETIME NOT NULL,
    `best_attempt_id` BIGINT UNSIGNED NOT NULL,
    `effective_best_score` DECIMAL(5, 2) NOT NULL,
    `best_scored_at` DATETIME NOT NULL,
    `attempt_count` INT UNSIGNED NOT NULL DEFAULT 1,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_drill_mastery_scores_scope` (`staff_id`, `domain_id`, `scope_type`, `scope_key`, `rubric_version_id`),
    KEY `idx_drill_mastery_scores_domain` (`domain_id`, `scope_type`, `updated_at`),
    CONSTRAINT `fk_drill_mastery_scores_rubric` FOREIGN KEY (`rubric_id`, `domain_id`) REFERENCES `drill_rubrics` (`id`, `domain_id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_drill_mastery_scores_rubric_version` FOREIGN KEY (`rubric_version_id`, `rubric_id`) REFERENCES `drill_rubric_versions` (`id`, `rubric_id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_drill_mastery_scores_latest` FOREIGN KEY (`latest_attempt_id`, `staff_id`, `domain_id`, `rubric_version_id`) REFERENCES `drill_attempts` (`id`, `staff_id`, `domain_id`, `rubric_version_id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_drill_mastery_scores_best` FOREIGN KEY (`best_attempt_id`, `staff_id`, `domain_id`, `rubric_version_id`) REFERENCES `drill_attempts` (`id`, `staff_id`, `domain_id`, `rubric_version_id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_drill_mastery_scores_latest` CHECK (`latest_score` >= 0 AND `latest_score` <= 100),
    CONSTRAINT `chk_drill_mastery_scores_best` CHECK (`effective_best_score` >= 0 AND `effective_best_score` <= 100 AND `best_scored_at` <= `latest_scored_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drill_growth_level_snapshots` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `staff_id` BIGINT UNSIGNED NOT NULL,
    `domain_id` BIGINT UNSIGNED NOT NULL,
    `rubric_id` BIGINT UNSIGNED NOT NULL,
    `rubric_version_id` BIGINT UNSIGNED NOT NULL,
    `level_code` ENUM('foundation', 'developing', 'proficient', 'advanced', 'expert') DEFAULT NULL,
    `level_floor_score` TINYINT UNSIGNED DEFAULT NULL,
    `level_score` DECIMAL(5, 2) DEFAULT NULL,
    `required_section_min_score` DECIMAL(5, 2) DEFAULT NULL,
    `full_process_score` DECIMAL(5, 2) DEFAULT NULL,
    `required_sections_passed` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `required_sections_total` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `qualification_status` ENUM('qualified', 'section_gap', 'full_process_gap', 'both_gap', 'reassessment_pending') NOT NULL,
    `status` ENUM('current', 'reassessment_pending', 'historical') NOT NULL DEFAULT 'current',
    `historical_reference_id` BIGINT UNSIGNED DEFAULT NULL,
    `score_snapshot_json` JSON NOT NULL,
    `calculated_at` DATETIME NOT NULL,
    `superseded_at` DATETIME DEFAULT NULL,
    `current_identity` VARCHAR(160) GENERATED ALWAYS AS (CASE WHEN `status` IN ('current', 'reassessment_pending') AND `superseded_at` IS NULL THEN CONCAT(`staff_id`, ':', `domain_id`) ELSE NULL END) STORED,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_drill_growth_levels_history_scope` (`id`, `staff_id`, `domain_id`),
    UNIQUE KEY `uk_drill_growth_levels_current` (`current_identity`),
    KEY `idx_drill_growth_levels_staff_history` (`staff_id`, `domain_id`, `calculated_at`),
    CONSTRAINT `fk_drill_growth_levels_rubric` FOREIGN KEY (`rubric_id`, `domain_id`) REFERENCES `drill_rubrics` (`id`, `domain_id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_drill_growth_levels_rubric_version` FOREIGN KEY (`rubric_version_id`, `rubric_id`) REFERENCES `drill_rubric_versions` (`id`, `rubric_id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_drill_growth_levels_history` FOREIGN KEY (`historical_reference_id`, `staff_id`, `domain_id`) REFERENCES `drill_growth_level_snapshots` (`id`, `staff_id`, `domain_id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_drill_growth_levels_floor` CHECK ((`status` = 'reassessment_pending' AND `level_code` IS NULL AND `level_floor_score` IS NULL) OR (`status` <> 'reassessment_pending' AND `level_code` IS NOT NULL AND `level_floor_score` IS NOT NULL AND ((`level_code` = 'foundation' AND `level_floor_score` = 0 AND `level_score` < 60) OR (`level_code` = 'developing' AND `level_floor_score` = 60 AND `level_score` >= 60 AND `level_score` < 70) OR (`level_code` = 'proficient' AND `level_floor_score` = 70 AND `level_score` >= 70 AND `level_score` < 80) OR (`level_code` = 'advanced' AND `level_floor_score` = 80 AND `level_score` >= 80 AND `level_score` < 90) OR (`level_code` = 'expert' AND `level_floor_score` = 90 AND `level_score` >= 90 AND `level_score` <= 100)))),
    CONSTRAINT `chk_drill_growth_levels_scores` CHECK ((`status` = 'reassessment_pending' AND `level_score` IS NULL AND `required_section_min_score` IS NULL AND `full_process_score` IS NULL) OR (`status` <> 'reassessment_pending' AND `level_score` IS NOT NULL AND `required_section_min_score` IS NOT NULL AND `full_process_score` IS NOT NULL AND `level_score` = LEAST(`required_section_min_score`, `full_process_score`) AND `required_section_min_score` >= 0 AND `required_section_min_score` <= 100 AND `full_process_score` >= 0 AND `full_process_score` <= 100)),
    CONSTRAINT `chk_drill_growth_levels_sections` CHECK ((`status` = 'reassessment_pending' AND `required_sections_total` = 0 AND `required_sections_passed` = 0) OR (`status` <> 'reassessment_pending' AND `required_sections_total` > 0 AND `required_sections_passed` <= `required_sections_total`)),
    CONSTRAINT `chk_drill_growth_levels_reassessment` CHECK ((`status` = 'reassessment_pending' AND `qualification_status` = 'reassessment_pending' AND `historical_reference_id` IS NOT NULL) OR (`status` <> 'reassessment_pending' AND `qualification_status` <> 'reassessment_pending')),
    CONSTRAINT `chk_drill_growth_levels_qualified` CHECK ((`qualification_status` = 'qualified' AND `required_sections_passed` = `required_sections_total` AND `required_section_min_score` >= `level_floor_score` AND `full_process_score` >= `level_floor_score`) OR (`qualification_status` <> 'qualified'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_attempt_reference_bindings' AND CONSTRAINT_NAME = 'fk_drill_attempt_reference_material'),
    'SELECT 1',
    'ALTER TABLE drill_attempt_reference_bindings ADD CONSTRAINT fk_drill_attempt_reference_material FOREIGN KEY (material_version_id) REFERENCES drill_reference_material_versions (id) ON DELETE RESTRICT'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_report_action_items' AND CONSTRAINT_NAME = 'fk_drill_report_actions_resource'),
    'SELECT 1',
    'ALTER TABLE drill_report_action_items ADD CONSTRAINT fk_drill_report_actions_resource FOREIGN KEY (learning_resource_id, learning_resource_version) REFERENCES drill_learning_resource_versions (learning_resource_id, version_code) ON DELETE RESTRICT'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_attempts' AND CONSTRAINT_NAME = 'fk_drill_attempts_calibration'),
    'SELECT 1',
    'ALTER TABLE drill_attempts ADD CONSTRAINT fk_drill_attempts_calibration FOREIGN KEY (calibration_version_id, domain_id, rubric_version_id) REFERENCES drill_score_calibration_versions (id, domain_id, rubric_version_id) ON DELETE RESTRICT'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_evaluations' AND CONSTRAINT_NAME = 'fk_drill_evaluations_calibration'),
    'SELECT 1',
    'ALTER TABLE drill_evaluations ADD CONSTRAINT fk_drill_evaluations_calibration FOREIGN KEY (calibration_version_id, rubric_version_id) REFERENCES drill_score_calibration_versions (id, rubric_version_id) ON DELETE RESTRICT'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;
