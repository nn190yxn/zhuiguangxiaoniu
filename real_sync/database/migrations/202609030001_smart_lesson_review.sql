-- 教案上传、结构化编辑、优化建议与两级审核

CREATE TABLE IF NOT EXISTS `lesson_submissions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `store_id` INT UNSIGNED NULL,
    `store_name` VARCHAR(128) NOT NULL,
    `author_staff_id` INT UNSIGNED NULL,
    `author_name` VARCHAR(128) NOT NULL,
    `course_line` VARCHAR(128) NOT NULL,
    `class_level` VARCHAR(128) NOT NULL,
    `lesson_date` DATE NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `status` VARCHAR(32) NOT NULL DEFAULT 'draft',
    `current_version_id` BIGINT UNSIGNED NULL,
    `approved_version_id` BIGINT UNSIGNED NULL,
    `status_version` INT UNSIGNED NOT NULL DEFAULT 1,
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_lesson_submissions_author_status` (`author_staff_id`, `status`, `updated_at`),
    KEY `idx_lesson_submissions_store_status` (`store_id`, `status`, `updated_at`),
    KEY `idx_lesson_submissions_date` (`lesson_date`, `course_line`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lesson_source_files` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `submission_id` BIGINT UNSIGNED NOT NULL,
    `original_name` VARCHAR(255) NOT NULL,
    `storage_key` VARCHAR(512) NOT NULL,
    `mime_type` VARCHAR(128) NOT NULL,
    `extension` VARCHAR(16) NOT NULL,
    `byte_size` BIGINT UNSIGNED NOT NULL,
    `sha256` CHAR(64) NULL,
    `status` VARCHAR(32) NOT NULL DEFAULT 'uploaded',
    `uploaded_by` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_lesson_source_files_storage_key` (`storage_key`),
    KEY `idx_lesson_source_files_submission` (`submission_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lesson_versions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `submission_id` BIGINT UNSIGNED NOT NULL,
    `version_no` INT UNSIGNED NOT NULL,
    `content_json` LONGTEXT NOT NULL,
    `source_snapshot_json` LONGTEXT NULL,
    `changed_fields_json` TEXT NULL,
    `version_type` VARCHAR(32) NOT NULL DEFAULT 'draft',
    `is_submitted` TINYINT(1) NOT NULL DEFAULT 0,
    `is_immutable` TINYINT(1) NOT NULL DEFAULT 0,
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_lesson_versions_submission_no` (`submission_id`, `version_no`),
    KEY `idx_lesson_versions_submission_created` (`submission_id`, `created_at`),
    KEY `idx_lesson_versions_submitted` (`submission_id`, `is_submitted`, `is_immutable`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lesson_parse_runs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `submission_id` BIGINT UNSIGNED NOT NULL,
    `source_file_id` BIGINT UNSIGNED NOT NULL,
    `parser_version` VARCHAR(64) NOT NULL,
    `status` VARCHAR(32) NOT NULL DEFAULT 'queued',
    `location_map_json` LONGTEXT NULL,
    `error_code` VARCHAR(64) NULL,
    `error_message` TEXT NULL,
    `started_at` DATETIME NULL,
    `completed_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_lesson_parse_runs_submission` (`submission_id`, `created_at`),
    KEY `idx_lesson_parse_runs_status` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lesson_suggestions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `submission_id` BIGINT UNSIGNED NOT NULL,
    `version_id` BIGINT UNSIGNED NOT NULL,
    `suggestion_type` VARCHAR(64) NOT NULL,
    `priority` VARCHAR(16) NOT NULL DEFAULT 'medium',
    `field_path` VARCHAR(255) NULL,
    `message` TEXT NOT NULL,
    `reason` TEXT NULL,
    `source_type` VARCHAR(64) NULL,
    `knowledge_item_id` BIGINT UNSIGNED NULL,
    `decision` VARCHAR(16) NOT NULL DEFAULT 'pending',
    `decided_by` INT UNSIGNED NULL,
    `decided_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_lesson_suggestions_version_decision` (`version_id`, `decision`),
    KEY `idx_lesson_suggestions_knowledge` (`knowledge_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lesson_review_tasks` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `submission_id` BIGINT UNSIGNED NOT NULL,
    `version_id` BIGINT UNSIGNED NOT NULL,
    `reviewer_staff_id` INT UNSIGNED NOT NULL,
    `reviewer_role` VARCHAR(32) NOT NULL,
    `stage` VARCHAR(32) NOT NULL,
    `status` VARCHAR(16) NOT NULL DEFAULT 'pending',
    `decision` VARCHAR(16) NULL,
    `comments` TEXT NULL,
    `decided_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_lesson_review_tasks_reviewer_status` (`reviewer_staff_id`, `status`, `created_at`),
    KEY `idx_lesson_review_tasks_submission_stage` (`submission_id`, `stage`, `created_at`),
    KEY `idx_lesson_review_tasks_version` (`version_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lesson_exports` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `submission_id` BIGINT UNSIGNED NOT NULL,
    `version_id` BIGINT UNSIGNED NOT NULL,
    `format` VARCHAR(16) NOT NULL,
    `storage_key` VARCHAR(512) NULL,
    `status` VARCHAR(16) NOT NULL DEFAULT 'queued',
    `error_message` TEXT NULL,
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_lesson_exports_storage_key` (`storage_key`),
    KEY `idx_lesson_exports_submission_version` (`submission_id`, `version_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lesson_audit_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `submission_id` BIGINT UNSIGNED NOT NULL,
    `version_id` BIGINT UNSIGNED NULL,
    `actor_user_id` BIGINT UNSIGNED NULL,
    `actor_staff_id` INT UNSIGNED NULL,
    `action` VARCHAR(64) NOT NULL,
    `from_status` VARCHAR(32) NULL,
    `to_status` VARCHAR(32) NULL,
    `metadata_json` LONGTEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_lesson_audit_logs_submission` (`submission_id`, `created_at`),
    KEY `idx_lesson_audit_logs_actor` (`actor_staff_id`, `created_at`),
    KEY `idx_lesson_audit_logs_action` (`action`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
