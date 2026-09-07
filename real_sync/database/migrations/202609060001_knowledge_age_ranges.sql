SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `knowledge_age_ranges` (
    `age_code` VARCHAR(32) NOT NULL,
    `label` VARCHAR(64) NOT NULL,
    `min_age_months` SMALLINT UNSIGNED DEFAULT NULL,
    `max_age_months` SMALLINT UNSIGNED DEFAULT NULL,
    `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `status` VARCHAR(16) NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`age_code`),
    KEY `idx_knowledge_age_ranges_status_sort` (`status`, `sort_order`),
    CONSTRAINT `chk_knowledge_age_ranges_status` CHECK (`status` IN ('active', 'inactive'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='知识卡标准年龄段字典';

INSERT IGNORE INTO `knowledge_age_ranges`
    (`age_code`, `label`, `min_age_months`, `max_age_months`, `sort_order`)
VALUES
    ('age_0_3', '0-3岁', 0, 35, 10),
    ('age_3_4', '3-4岁', 36, 47, 20),
    ('age_4_5', '4-5岁', 48, 59, 30),
    ('age_5_6', '5-6岁', 60, 71, 40),
    ('age_6_9', '6-9岁', 72, 107, 50),
    ('age_9_12', '9-12岁', 108, 143, 60),
    ('age_12_plus', '12岁以上', 144, NULL, 70),
    ('age_all_review', '全年龄段，需教练现场评估', NULL, NULL, 80);

CREATE TABLE IF NOT EXISTS `knowledge_item_age_ranges` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `knowledge_item_id` INT UNSIGNED NOT NULL,
    `version_id` BIGINT UNSIGNED NOT NULL,
    `age_code` VARCHAR(32) NOT NULL,
    `source_text` VARCHAR(255) DEFAULT NULL,
    `confidence` DECIMAL(5,4) DEFAULT NULL,
    `review_status` VARCHAR(16) NOT NULL DEFAULT 'pending',
    `reviewed_by` INT UNSIGNED DEFAULT NULL,
    `reviewed_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_knowledge_item_age_ranges_version_code` (`knowledge_item_id`, `version_id`, `age_code`),
    KEY `idx_knowledge_item_age_ranges_version_age` (`version_id`, `age_code`),
    KEY `idx_knowledge_item_age_ranges_review` (`review_status`, `created_at`),
    CONSTRAINT `fk_knowledge_item_age_ranges_item` FOREIGN KEY (`knowledge_item_id`) REFERENCES `knowledge_items` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_knowledge_item_age_ranges_version` FOREIGN KEY (`version_id`) REFERENCES `knowledge_item_versions` (`version_id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_knowledge_item_age_ranges_age` FOREIGN KEY (`age_code`) REFERENCES `knowledge_age_ranges` (`age_code`) ON DELETE RESTRICT,
    CONSTRAINT `chk_knowledge_item_age_ranges_confidence` CHECK (`confidence` IS NULL OR (`confidence` >= 0 AND `confidence` <= 1)),
    CONSTRAINT `chk_knowledge_item_age_ranges_review_status` CHECK (`review_status` IN ('pending', 'confirmed', 'rejected'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='知识卡版本年龄段关联';
