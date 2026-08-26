SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `knowledge_import_batches` (
    `batch_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `package_sha256` CHAR(64) NOT NULL,
    `source_root` VARCHAR(500) NOT NULL,
    `parser_version` VARCHAR(32) NOT NULL,
    `status` VARCHAR(24) NOT NULL DEFAULT 'isolated',
    `before_counts_json` JSON DEFAULT NULL,
    `after_counts_json` JSON DEFAULT NULL,
    `manifest_path` VARCHAR(500) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`batch_id`),
    UNIQUE KEY `uk_knowledge_import_batches_package` (`package_sha256`),
    KEY `idx_knowledge_import_batches_status_created` (`status`, `created_at`),
    CONSTRAINT `chk_knowledge_import_batches_status` CHECK (`status` IN ('isolated', 'reviewing', 'published', 'rolled_back', 'failed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='知识卡导入批次';

SET @knowledge_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'knowledge_items' AND COLUMN_NAME = 'item_code'),
    'SELECT 1',
    'ALTER TABLE knowledge_items ADD COLUMN item_code VARCHAR(96) NULL AFTER id'
);
PREPARE knowledge_statement FROM @knowledge_sql;
EXECUTE knowledge_statement;
DEALLOCATE PREPARE knowledge_statement;

SET @knowledge_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'knowledge_items' AND COLUMN_NAME = 'content_type'),
    'SELECT 1',
    'ALTER TABLE knowledge_items ADD COLUMN content_type VARCHAR(32) NULL AFTER item_code'
);
PREPARE knowledge_statement FROM @knowledge_sql;
EXECUTE knowledge_statement;
DEALLOCATE PREPARE knowledge_statement;

SET @knowledge_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'knowledge_items' AND COLUMN_NAME = 'domain_code'),
    'SELECT 1',
    'ALTER TABLE knowledge_items ADD COLUMN domain_code VARCHAR(64) NULL AFTER content_type'
);
PREPARE knowledge_statement FROM @knowledge_sql;
EXECUTE knowledge_statement;
DEALLOCATE PREPARE knowledge_statement;

SET @knowledge_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'knowledge_items' AND COLUMN_NAME = 'risk_level'),
    'SELECT 1',
    'ALTER TABLE knowledge_items ADD COLUMN risk_level VARCHAR(8) NULL AFTER domain_code'
);
PREPARE knowledge_statement FROM @knowledge_sql;
EXECUTE knowledge_statement;
DEALLOCATE PREPARE knowledge_statement;

SET @knowledge_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'knowledge_items' AND COLUMN_NAME = 'publication_status'),
    'SELECT 1',
    'ALTER TABLE knowledge_items ADD COLUMN publication_status VARCHAR(16) NOT NULL DEFAULT ''published'' AFTER risk_level'
);
PREPARE knowledge_statement FROM @knowledge_sql;
EXECUTE knowledge_statement;
DEALLOCATE PREPARE knowledge_statement;

SET @knowledge_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'knowledge_items' AND COLUMN_NAME = 'source_batch_id'),
    'SELECT 1',
    'ALTER TABLE knowledge_items ADD COLUMN source_batch_id BIGINT UNSIGNED NULL AFTER publication_status'
);
PREPARE knowledge_statement FROM @knowledge_sql;
EXECUTE knowledge_statement;
DEALLOCATE PREPARE knowledge_statement;

SET @knowledge_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'knowledge_items' AND COLUMN_NAME = 'current_version_id'),
    'SELECT 1',
    'ALTER TABLE knowledge_items ADD COLUMN current_version_id BIGINT UNSIGNED NULL AFTER source_batch_id'
);
PREPARE knowledge_statement FROM @knowledge_sql;
EXECUTE knowledge_statement;
DEALLOCATE PREPARE knowledge_statement;

SET @knowledge_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'knowledge_items' AND INDEX_NAME = 'uk_knowledge_items_item_code'),
    'SELECT 1',
    'ALTER TABLE knowledge_items ADD UNIQUE KEY uk_knowledge_items_item_code (item_code)'
);
PREPARE knowledge_statement FROM @knowledge_sql;
EXECUTE knowledge_statement;
DEALLOCATE PREPARE knowledge_statement;

SET @knowledge_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'knowledge_items' AND INDEX_NAME = 'idx_knowledge_items_publication'),
    'SELECT 1',
    'ALTER TABLE knowledge_items ADD KEY idx_knowledge_items_publication (publication_status, status, is_public)'
);
PREPARE knowledge_statement FROM @knowledge_sql;
EXECUTE knowledge_statement;
DEALLOCATE PREPARE knowledge_statement;

SET @knowledge_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'knowledge_items' AND INDEX_NAME = 'idx_knowledge_items_content_type'),
    'SELECT 1',
    'ALTER TABLE knowledge_items ADD KEY idx_knowledge_items_content_type (content_type, publication_status)'
);
PREPARE knowledge_statement FROM @knowledge_sql;
EXECUTE knowledge_statement;
DEALLOCATE PREPARE knowledge_statement;

SET @knowledge_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'knowledge_items' AND INDEX_NAME = 'idx_knowledge_items_domain_risk'),
    'SELECT 1',
    'ALTER TABLE knowledge_items ADD KEY idx_knowledge_items_domain_risk (domain_code, risk_level, publication_status)'
);
PREPARE knowledge_statement FROM @knowledge_sql;
EXECUTE knowledge_statement;
DEALLOCATE PREPARE knowledge_statement;

CREATE TABLE IF NOT EXISTS `knowledge_item_versions` (
    `version_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `knowledge_item_id` INT UNSIGNED NOT NULL,
    `version_no` INT UNSIGNED NOT NULL,
    `title` VARCHAR(200) NOT NULL,
    `summary` TEXT DEFAULT NULL,
    `content` LONGTEXT NOT NULL,
    `content_format` VARCHAR(16) NOT NULL DEFAULT 'markdown',
    `content_type` VARCHAR(32) DEFAULT NULL,
    `domain_code` VARCHAR(64) DEFAULT NULL,
    `risk_level` VARCHAR(8) DEFAULT NULL,
    `subject` VARCHAR(255) DEFAULT NULL,
    `age_group` VARCHAR(255) DEFAULT NULL,
    `training_type` VARCHAR(255) DEFAULT NULL,
    `difficulty` TINYINT UNSIGNED DEFAULT NULL,
    `tags_json` JSON DEFAULT NULL,
    `source_snapshot_json` JSON DEFAULT NULL,
    `raw_markdown` LONGTEXT DEFAULT NULL,
    `change_reason` VARCHAR(500) NOT NULL,
    `changed_by` INT UNSIGNED DEFAULT NULL,
    `status` VARCHAR(16) NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`version_id`),
    UNIQUE KEY `uk_knowledge_item_versions_number` (`knowledge_item_id`, `version_no`),
    KEY `idx_knowledge_item_versions_current` (`knowledge_item_id`, `status`, `created_at`),
    KEY `idx_knowledge_item_versions_changed_by` (`changed_by`, `created_at`),
    CONSTRAINT `fk_knowledge_item_versions_item` FOREIGN KEY (`knowledge_item_id`) REFERENCES `knowledge_items` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_knowledge_item_versions_format` CHECK (`content_format` IN ('markdown', 'html')),
    CONSTRAINT `chk_knowledge_item_versions_status` CHECK (`status` IN ('active', 'superseded', 'rolled_back')),
    CONSTRAINT `chk_knowledge_item_versions_difficulty` CHECK (`difficulty` IS NULL OR (`difficulty` >= 1 AND `difficulty` <= 5))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='知识卡内容版本';

SET @knowledge_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'knowledge_items' AND CONSTRAINT_NAME = 'fk_knowledge_items_source_batch'),
    'SELECT 1',
    'ALTER TABLE knowledge_items ADD CONSTRAINT fk_knowledge_items_source_batch FOREIGN KEY (source_batch_id) REFERENCES knowledge_import_batches (batch_id) ON DELETE RESTRICT'
);
PREPARE knowledge_statement FROM @knowledge_sql;
EXECUTE knowledge_statement;
DEALLOCATE PREPARE knowledge_statement;

SET @knowledge_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'knowledge_items' AND CONSTRAINT_NAME = 'fk_knowledge_items_current_version'),
    'SELECT 1',
    'ALTER TABLE knowledge_items ADD CONSTRAINT fk_knowledge_items_current_version FOREIGN KEY (current_version_id) REFERENCES knowledge_item_versions (version_id) ON DELETE RESTRICT'
);
PREPARE knowledge_statement FROM @knowledge_sql;
EXECUTE knowledge_statement;
DEALLOCATE PREPARE knowledge_statement;

CREATE TABLE IF NOT EXISTS `knowledge_item_sources` (
    `source_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `knowledge_item_id` INT UNSIGNED NOT NULL,
    `batch_id` BIGINT UNSIGNED NOT NULL,
    `source_card_id` VARCHAR(96) NOT NULL,
    `source_path` VARCHAR(500) NOT NULL,
    `source_sha256` CHAR(64) NOT NULL,
    `normalized_hash` CHAR(64) NOT NULL,
    `source_articles_json` JSON DEFAULT NULL,
    `source_images_json` JSON DEFAULT NULL,
    `raw_frontmatter_json` JSON DEFAULT NULL,
    `raw_markdown` LONGTEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`source_id`),
    UNIQUE KEY `uk_knowledge_item_sources_batch_card` (`batch_id`, `source_card_id`),
    KEY `idx_knowledge_item_sources_item` (`knowledge_item_id`, `created_at`),
    KEY `idx_knowledge_item_sources_hash` (`source_sha256`, `normalized_hash`),
    CONSTRAINT `fk_knowledge_item_sources_item` FOREIGN KEY (`knowledge_item_id`) REFERENCES `knowledge_items` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_knowledge_item_sources_batch` FOREIGN KEY (`batch_id`) REFERENCES `knowledge_import_batches` (`batch_id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_knowledge_item_sources_sha256` CHECK (`source_sha256` REGEXP '^[a-f0-9]{64}$' AND `normalized_hash` REGEXP '^[a-f0-9]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='知识卡来源快照';

CREATE TABLE IF NOT EXISTS `knowledge_item_relations` (
    `relation_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `source_item_id` INT UNSIGNED NOT NULL,
    `target_item_id` INT UNSIGNED NOT NULL,
    `relation_type` VARCHAR(24) NOT NULL DEFAULT 'candidate',
    `reviewed_by` INT UNSIGNED DEFAULT NULL,
    `reviewed_at` DATETIME DEFAULT NULL,
    `review_note` VARCHAR(1000) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`relation_id`),
    UNIQUE KEY `uk_knowledge_item_relations_pair` (`source_item_id`, `target_item_id`),
    KEY `idx_knowledge_item_relations_type` (`relation_type`, `reviewed_at`),
    CONSTRAINT `fk_knowledge_item_relations_source` FOREIGN KEY (`source_item_id`) REFERENCES `knowledge_items` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_knowledge_item_relations_target` FOREIGN KEY (`target_item_id`) REFERENCES `knowledge_items` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_knowledge_item_relations_type` CHECK (`relation_type` IN ('candidate', 'merged', 'kept_separate', 'rejected')),
    CONSTRAINT `chk_knowledge_item_relations_distinct` CHECK (`source_item_id` <> `target_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='知识卡新旧关系';

CREATE TABLE IF NOT EXISTS `knowledge_favorites` (
    `favorite_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `knowledge_id` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`favorite_id`),
    UNIQUE KEY `uk_knowledge_favorites_user_item` (`user_id`, `knowledge_id`),
    KEY `idx_knowledge_favorites_item` (`knowledge_id`, `created_at`),
    CONSTRAINT `fk_knowledge_favorites_item` FOREIGN KEY (`knowledge_id`) REFERENCES `knowledge_items` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='知识卡收藏';

CREATE TABLE IF NOT EXISTS `knowledge_recent_views` (
    `recent_view_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `knowledge_id` INT UNSIGNED NOT NULL,
    `view_count` INT UNSIGNED NOT NULL DEFAULT 1,
    `first_viewed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_viewed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`recent_view_id`),
    UNIQUE KEY `uk_knowledge_recent_views_user_item` (`user_id`, `knowledge_id`),
    KEY `idx_knowledge_recent_views_user_recent` (`user_id`, `last_viewed_at`),
    KEY `idx_knowledge_recent_views_item` (`knowledge_id`, `last_viewed_at`),
    CONSTRAINT `fk_knowledge_recent_views_item` FOREIGN KEY (`knowledge_id`) REFERENCES `knowledge_items` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='知识卡最近浏览';

CREATE TABLE IF NOT EXISTS `knowledge_audit_logs` (
    `audit_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `batch_id` BIGINT UNSIGNED DEFAULT NULL,
    `actor_user_id` INT UNSIGNED DEFAULT NULL,
    `actor_staff_id` INT UNSIGNED DEFAULT NULL,
    `action` VARCHAR(32) NOT NULL,
    `target_type` VARCHAR(64) NOT NULL,
    `target_id` VARCHAR(128) DEFAULT NULL,
    `before_json` JSON DEFAULT NULL,
    `after_json` JSON DEFAULT NULL,
    `metadata_json` JSON DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` VARCHAR(500) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`audit_id`),
    KEY `idx_knowledge_audit_logs_batch` (`batch_id`, `created_at`),
    KEY `idx_knowledge_audit_logs_actor` (`actor_user_id`, `actor_staff_id`, `created_at`),
    KEY `idx_knowledge_audit_logs_target` (`target_type`, `target_id`, `created_at`),
    KEY `idx_knowledge_audit_logs_action` (`action`, `created_at`),
    CONSTRAINT `fk_knowledge_audit_logs_batch` FOREIGN KEY (`batch_id`) REFERENCES `knowledge_import_batches` (`batch_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='知识卡运营审计日志';
