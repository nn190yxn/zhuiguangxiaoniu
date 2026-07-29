-- Additive analytics, governance and cutover controls. No legacy or media data is deleted here.

CREATE TABLE IF NOT EXISTS `drill_governance_runs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `run_type` VARCHAR(64) NOT NULL,
    `status` VARCHAR(32) NOT NULL,
    `dry_run` TINYINT(1) NOT NULL DEFAULT 1,
    `summary_json` JSON NOT NULL,
    `actor_staff_id` BIGINT UNSIGNED NULL,
    `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `idx_drill_governance_runs_type_status` (`run_type`, `status`, `started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drill_cutover_batches` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `batch_key` VARCHAR(128) NOT NULL,
    `surface` ENUM('admin', 'pwa', 'mini_program') NOT NULL,
    `status` ENUM('planned', 'preflight_passed', 'drill_completed', 'rollback_planned') NOT NULL DEFAULT 'planned',
    `target_scope_json` JSON NOT NULL,
    `preflight_json` JSON NOT NULL,
    `created_by_staff_id` BIGINT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_drill_cutover_batches_key` (`batch_key`),
    KEY `idx_drill_cutover_batches_surface_status` (`surface`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drill_cutover_reconciliations` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `batch_id` BIGINT UNSIGNED NOT NULL,
    `entity_type` ENUM('tasks', 'attempts', 'recordings', 'analyses', 'certifications') NOT NULL,
    `legacy_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `v2_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `mapped_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `status` ENUM('matched', 'mismatch', 'unavailable') NOT NULL,
    `details_json` JSON NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_drill_cutover_reconciliation_entity` (`batch_id`, `entity_type`),
    CONSTRAINT `fk_drill_cutover_reconciliation_batch` FOREIGN KEY (`batch_id`) REFERENCES `drill_cutover_batches` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drill_cutover_rollback_drills` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `batch_id` BIGINT UNSIGNED NOT NULL,
    `status` ENUM('planned', 'verified') NOT NULL DEFAULT 'planned',
    `plan_json` JSON NOT NULL,
    `verified_by_staff_id` BIGINT UNSIGNED NULL,
    `verified_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_drill_cutover_rollback_batch` (`batch_id`),
    CONSTRAINT `fk_drill_cutover_rollback_batch` FOREIGN KEY (`batch_id`) REFERENCES `drill_cutover_batches` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
