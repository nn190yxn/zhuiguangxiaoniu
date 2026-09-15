SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `knowledge_enrichment_batches` (
    `batch_id` VARCHAR(64) NOT NULL,
    `scope` VARCHAR(32) NOT NULL DEFAULT 'snapshot',
    `source_snapshot_sha256` CHAR(64) NOT NULL,
    `cursor_offset` INT UNSIGNED NOT NULL DEFAULT 0,
    `page_size` SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    `status` VARCHAR(16) NOT NULL DEFAULT 'running',
    `total_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `processed_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `success_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `failed_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `skipped_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `retry_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `error_summary_json` JSON NOT NULL,
    `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`batch_id`),
    KEY `idx_knowledge_enrichment_batches_status` (`status`, `updated_at`),
    CONSTRAINT `chk_knowledge_enrichment_batches_hash` CHECK (`source_snapshot_sha256` REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT `chk_knowledge_enrichment_batches_status` CHECK (`status` IN ('running', 'completed', 'failed', 'rolled_back'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='知识卡增强批次运行状态';

CREATE TABLE IF NOT EXISTS `knowledge_enrichment_release_items` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `release_batch_id` VARCHAR(64) NOT NULL,
    `enrichment_record_id` BIGINT UNSIGNED NOT NULL,
    `before_enrichment_status` VARCHAR(16) NOT NULL,
    `before_review_status` VARCHAR(16) NOT NULL,
    `before_release_batch_id` VARCHAR(64) DEFAULT NULL,
    `before_content_json` JSON DEFAULT NULL,
    `before_content_sha256` CHAR(64) DEFAULT NULL,
    `after_content_sha256` CHAR(64) NOT NULL,
    `rolled_back_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_knowledge_enrichment_release_item` (`release_batch_id`, `enrichment_record_id`),
    KEY `idx_knowledge_enrichment_release_record` (`enrichment_record_id`, `created_at`),
    CONSTRAINT `fk_knowledge_enrichment_release_record` FOREIGN KEY (`enrichment_record_id`) REFERENCES `knowledge_enrichment_records` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_knowledge_enrichment_release_after_hash` CHECK (`after_content_sha256` REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='知识卡增强发布前后快照';
