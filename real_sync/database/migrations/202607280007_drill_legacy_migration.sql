-- Additive storage for read-only legacy drill migration. Legacy tables are never mutated.

CREATE TABLE IF NOT EXISTS `drill_migration_batches` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `batch_key` CHAR(64) NOT NULL,
    `status` ENUM('preflight', 'running', 'completed', 'failed') NOT NULL DEFAULT 'preflight',
    `source_counts_json` JSON NOT NULL,
    `report_json` JSON NULL,
    `last_error` VARCHAR(1000) NULL,
    `attempt_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `started_at` DATETIME NULL,
    `completed_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_drill_migration_batches_key` (`batch_key`),
    KEY `idx_drill_migration_batches_status` (`status`, `updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drill_migration_items` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `batch_id` BIGINT UNSIGNED NOT NULL,
    `migration_key` CHAR(64) NOT NULL,
    `source_type` VARCHAR(64) NOT NULL,
    `source_id` VARCHAR(128) NOT NULL,
    `outcome` ENUM('migrated', 'pending_review', 'preserved_summary', 'failed', 'skipped') NOT NULL,
    `target_type` VARCHAR(64) NULL,
    `target_id` BIGINT UNSIGNED NULL,
    `source_summary_json` JSON NOT NULL,
    `error_message` VARCHAR(1000) NULL,
    `retryable` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_drill_migration_items_key` (`migration_key`),
    UNIQUE KEY `uk_drill_migration_items_batch_source` (`batch_id`, `source_type`, `source_id`),
    KEY `idx_drill_migration_items_batch_outcome` (`batch_id`, `outcome`),
    CONSTRAINT `fk_drill_migration_items_batch` FOREIGN KEY (`batch_id`) REFERENCES `drill_migration_batches` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drill_legacy_history_instances` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `migration_key` CHAR(64) NOT NULL,
    `legacy_task_id` VARCHAR(128) NULL,
    `legacy_recording_id` VARCHAR(128) NULL,
    `legacy_analysis_id` VARCHAR(128) NULL,
    `legacy_user_id` BIGINT UNSIGNED NULL,
    `readonly_status` VARCHAR(32) NOT NULL DEFAULT 'readonly',
    `evaluation_context` VARCHAR(32) NOT NULL DEFAULT 'real_call_review',
    `participant_source_json` JSON NOT NULL,
    `reference_source_json` JSON NOT NULL,
    `context_snapshot_json` JSON NOT NULL,
    `source_summary_json` JSON NOT NULL,
    `occurred_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_drill_legacy_history_instances_key` (`migration_key`),
    KEY `idx_drill_legacy_history_instances_user_time` (`legacy_user_id`, `occurred_at`),
    KEY `idx_drill_legacy_history_instances_recording` (`legacy_recording_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drill_legacy_feedback_mappings` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `legacy_feedback_id` VARCHAR(128) NOT NULL,
    `legacy_analysis_id` VARCHAR(128) NULL,
    `legacy_recording_id` VARCHAR(128) NULL,
    `history_instance_id` BIGINT UNSIGNED NULL,
    `source_summary_json` JSON NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_drill_legacy_feedback_mappings_feedback` (`legacy_feedback_id`),
    UNIQUE KEY `uk_drill_legacy_feedback_mappings_analysis` (`legacy_analysis_id`),
    KEY `idx_drill_legacy_feedback_mappings_recording` (`legacy_recording_id`),
    CONSTRAINT `fk_drill_legacy_feedback_mappings_history` FOREIGN KEY (`history_instance_id`) REFERENCES `drill_legacy_history_instances` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
