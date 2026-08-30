-- 销售 Q&A 题库与逐题练习存储
-- 题库数据以《追光小牛儿童运动Q&A》培训手册为源，由数据库导入脚本写入。

CREATE TABLE IF NOT EXISTS `drill_qa_sections` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `section_code` VARCHAR(32) NOT NULL,
    `section_name` VARCHAR(64) NOT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `status` VARCHAR(16) NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_drill_qa_sections_code` (`section_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drill_qa_questions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `section_id` INT UNSIGNED NOT NULL,
    `question_no` SMALLINT UNSIGNED NOT NULL,
    `question` TEXT NOT NULL,
    `reference_answer` MEDIUMTEXT NOT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `status` VARCHAR(16) NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_drill_qa_questions_section_no` (`section_id`, `question_no`),
    KEY `idx_drill_qa_questions_section_status` (`section_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drill_qa_sessions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `staff_id` INT UNSIGNED NOT NULL,
    `section_id` INT UNSIGNED NULL,
    `question_count` SMALLINT UNSIGNED NOT NULL DEFAULT 10,
    `question_ids_json` TEXT NOT NULL,
    `current_index` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `status` VARCHAR(16) NOT NULL DEFAULT 'active',
    `total_score` DECIMAL(5,1) NULL,
    `level_name` VARCHAR(32) NULL,
    `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `idx_drill_qa_sessions_staff_status` (`staff_id`, `status`, `started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drill_qa_answers` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_id` BIGINT UNSIGNED NOT NULL,
    `question_id` INT UNSIGNED NOT NULL,
    `question_no` SMALLINT UNSIGNED NOT NULL,
    `question` TEXT NOT NULL,
    `staff_answer` MEDIUMTEXT NOT NULL,
    `score` DECIMAL(5,1) NOT NULL DEFAULT 0,
    `dimension_scores_json` TEXT NULL,
    `ai_feedback` TEXT NULL,
    `ai_metadata_json` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_drill_qa_answers_session` (`session_id`),
    KEY `idx_drill_qa_answers_question` (`question_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
