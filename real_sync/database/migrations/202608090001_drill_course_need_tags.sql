-- Published business courses mapped to controlled new-signing customer needs.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `drill_course_need_tags` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `course_id` INT UNSIGNED NOT NULL,
    `need_code` VARCHAR(64) NOT NULL,
    `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_drill_course_need_tag` (`course_id`, `need_code`),
    KEY `idx_drill_course_need_tags_need` (`need_code`, `status`, `sort_order`),
    CONSTRAINT `fk_drill_course_need_tags_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
