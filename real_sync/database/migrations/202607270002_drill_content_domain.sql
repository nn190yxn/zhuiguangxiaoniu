CREATE TABLE IF NOT EXISTS `drill_training_domains` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain_code` VARCHAR(64) NOT NULL,
    `name` VARCHAR(128) NOT NULL,
    `description` VARCHAR(500) DEFAULT NULL,
    `status` ENUM('active', 'archived') NOT NULL DEFAULT 'active',
    `source_type` VARCHAR(64) NOT NULL,
    `source_ref` VARCHAR(255) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_drill_training_domains_code` (`domain_code`),
    KEY `idx_drill_training_domains_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drill_process_versions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain_id` BIGINT UNSIGNED NOT NULL,
    `version_no` INT UNSIGNED NOT NULL,
    `name` VARCHAR(128) NOT NULL,
    `status` ENUM('draft', 'in_review', 'published', 'archived') NOT NULL DEFAULT 'draft',
    `source_type` VARCHAR(64) NOT NULL,
    `source_ref` VARCHAR(255) DEFAULT NULL,
    `published_at` DATETIME DEFAULT NULL,
    `archived_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_drill_process_versions_domain_version` (`domain_id`, `version_no`),
    KEY `idx_drill_process_versions_domain_status` (`domain_id`, `status`),
    CONSTRAINT `fk_drill_process_versions_domain` FOREIGN KEY (`domain_id`) REFERENCES `drill_training_domains` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drill_process_stages` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `process_version_id` BIGINT UNSIGNED NOT NULL,
    `stage_code` VARCHAR(64) NOT NULL,
    `name` VARCHAR(128) NOT NULL,
    `description` VARCHAR(500) DEFAULT NULL,
    `sort_order` SMALLINT UNSIGNED NOT NULL,
    `required` TINYINT(1) NOT NULL DEFAULT 1,
    `status` ENUM('active', 'archived') NOT NULL DEFAULT 'active',
    `source_type` VARCHAR(64) NOT NULL,
    `source_ref` VARCHAR(255) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_drill_process_stages_version_code` (`process_version_id`, `stage_code`),
    UNIQUE KEY `uk_drill_process_stages_version_order` (`process_version_id`, `sort_order`),
    KEY `idx_drill_process_stages_version_status` (`process_version_id`, `status`),
    CONSTRAINT `fk_drill_process_stages_version` FOREIGN KEY (`process_version_id`) REFERENCES `drill_process_versions` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drill_persona_dimensions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain_id` BIGINT UNSIGNED NOT NULL,
    `dimension_code` VARCHAR(64) NOT NULL,
    `dimension_name` VARCHAR(128) NOT NULL,
    `value_code` VARCHAR(64) NOT NULL,
    `name` VARCHAR(128) NOT NULL,
    `description` VARCHAR(500) DEFAULT NULL,
    `sort_order` SMALLINT UNSIGNED NOT NULL,
    `status` ENUM('active', 'archived') NOT NULL DEFAULT 'active',
    `source_type` VARCHAR(64) NOT NULL,
    `source_ref` VARCHAR(255) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_drill_persona_dimensions_domain_code` (`domain_id`, `dimension_code`, `value_code`),
    KEY `idx_drill_persona_dimensions_domain_order` (`domain_id`, `dimension_code`, `sort_order`),
    KEY `idx_drill_persona_dimensions_domain_status` (`domain_id`, `status`),
    CONSTRAINT `fk_drill_persona_dimensions_domain` FOREIGN KEY (`domain_id`) REFERENCES `drill_training_domains` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drill_scenarios` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain_id` BIGINT UNSIGNED NOT NULL,
    `stage_id` BIGINT UNSIGNED DEFAULT NULL,
    `scenario_code` VARCHAR(64) NOT NULL,
    `name` VARCHAR(160) NOT NULL,
    `difficulty` VARCHAR(32) NOT NULL,
    `status` ENUM('active', 'archived') NOT NULL DEFAULT 'active',
    `source_type` VARCHAR(64) NOT NULL,
    `source_ref` VARCHAR(255) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_drill_scenarios_domain_code` (`domain_id`, `scenario_code`),
    KEY `idx_drill_scenarios_domain_stage_status` (`domain_id`, `stage_id`, `difficulty`, `status`),
    CONSTRAINT `fk_drill_scenarios_domain` FOREIGN KEY (`domain_id`) REFERENCES `drill_training_domains` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_drill_scenarios_stage` FOREIGN KEY (`stage_id`) REFERENCES `drill_process_stages` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drill_scenario_versions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `scenario_id` BIGINT UNSIGNED NOT NULL,
    `version_no` INT UNSIGNED NOT NULL,
    `status` ENUM('draft', 'in_review', 'published', 'archived') NOT NULL DEFAULT 'draft',
    `title` VARCHAR(200) NOT NULL,
    `customer_profile_json` JSON NOT NULL,
    `objectives_json` JSON NOT NULL,
    `key_actions_json` JSON NOT NULL,
    `standard_expressions_json` JSON NOT NULL,
    `risk_expressions_json` JSON NOT NULL,
    `prompt_policy_json` JSON NOT NULL,
    `content_hash` CHAR(64) DEFAULT NULL,
    `source_type` VARCHAR(64) NOT NULL,
    `source_ref` VARCHAR(255) DEFAULT NULL,
    `submitted_by` BIGINT UNSIGNED DEFAULT NULL,
    `submitted_at` DATETIME DEFAULT NULL,
    `reviewed_by` BIGINT UNSIGNED DEFAULT NULL,
    `reviewed_at` DATETIME DEFAULT NULL,
    `published_by` BIGINT UNSIGNED DEFAULT NULL,
    `published_at` DATETIME DEFAULT NULL,
    `archived_by` BIGINT UNSIGNED DEFAULT NULL,
    `archived_at` DATETIME DEFAULT NULL,
    `created_by` BIGINT UNSIGNED NOT NULL,
    `updated_by` BIGINT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_drill_scenario_versions_scenario_version` (`scenario_id`, `version_no`),
    KEY `idx_drill_scenario_versions_scenario_status` (`scenario_id`, `status`),
    KEY `idx_drill_scenario_versions_status_published` (`status`, `published_at`),
    CONSTRAINT `fk_drill_scenario_versions_scenario` FOREIGN KEY (`scenario_id`) REFERENCES `drill_scenarios` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drill_scenario_personas` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `scenario_version_id` BIGINT UNSIGNED NOT NULL,
    `dimension_id` BIGINT UNSIGNED NOT NULL,
    `value_code` VARCHAR(64) NOT NULL,
    `source` VARCHAR(64) NOT NULL,
    `source_ref` VARCHAR(255) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_drill_scenario_personas_version_dimension` (`scenario_version_id`, `dimension_id`),
    KEY `idx_drill_scenario_personas_dimension` (`dimension_id`),
    CONSTRAINT `fk_drill_scenario_personas_version` FOREIGN KEY (`scenario_version_id`) REFERENCES `drill_scenario_versions` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_drill_scenario_personas_dimension` FOREIGN KEY (`dimension_id`) REFERENCES `drill_persona_dimensions` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drill_rubrics` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain_id` BIGINT UNSIGNED NOT NULL,
    `stage_id` BIGINT UNSIGNED DEFAULT NULL,
    `rubric_code` VARCHAR(64) NOT NULL,
    `name` VARCHAR(160) NOT NULL,
    `mode` ENUM('capability', 'script_match', 'hybrid') NOT NULL,
    `status` ENUM('active', 'archived') NOT NULL DEFAULT 'active',
    `source_type` VARCHAR(64) NOT NULL,
    `source_ref` VARCHAR(255) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_drill_rubrics_domain_code` (`domain_id`, `rubric_code`),
    KEY `idx_drill_rubrics_domain_stage_status` (`domain_id`, `stage_id`, `mode`, `status`),
    CONSTRAINT `fk_drill_rubrics_domain` FOREIGN KEY (`domain_id`) REFERENCES `drill_training_domains` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_drill_rubrics_stage` FOREIGN KEY (`stage_id`) REFERENCES `drill_process_stages` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drill_rubric_versions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `rubric_id` BIGINT UNSIGNED NOT NULL,
    `version_no` INT UNSIGNED NOT NULL,
    `status` ENUM('draft', 'in_review', 'published', 'archived') NOT NULL DEFAULT 'draft',
    `dimensions_json` JSON NOT NULL,
    `critical_items_json` JSON NOT NULL,
    `score_policy_json` JSON NOT NULL,
    `max_score` DECIMAL(8, 2) NOT NULL DEFAULT 100.00,
    `pass_score` DECIMAL(8, 2) DEFAULT NULL,
    `content_hash` CHAR(64) DEFAULT NULL,
    `source_type` VARCHAR(64) NOT NULL,
    `source_ref` VARCHAR(255) DEFAULT NULL,
    `submitted_by` BIGINT UNSIGNED DEFAULT NULL,
    `submitted_at` DATETIME DEFAULT NULL,
    `reviewed_by` BIGINT UNSIGNED DEFAULT NULL,
    `reviewed_at` DATETIME DEFAULT NULL,
    `published_by` BIGINT UNSIGNED DEFAULT NULL,
    `published_at` DATETIME DEFAULT NULL,
    `archived_by` BIGINT UNSIGNED DEFAULT NULL,
    `archived_at` DATETIME DEFAULT NULL,
    `created_by` BIGINT UNSIGNED NOT NULL,
    `updated_by` BIGINT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_drill_rubric_versions_rubric_version` (`rubric_id`, `version_no`),
    KEY `idx_drill_rubric_versions_rubric_status` (`rubric_id`, `status`),
    KEY `idx_drill_rubric_versions_status_published` (`status`, `published_at`),
    CONSTRAINT `fk_drill_rubric_versions_rubric` FOREIGN KEY (`rubric_id`) REFERENCES `drill_rubrics` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drill_legacy_content_mappings` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `source_type` VARCHAR(64) NOT NULL,
    `source_id` VARCHAR(128) NOT NULL,
    `scenario_id` BIGINT UNSIGNED DEFAULT NULL,
    `scenario_version_id` BIGINT UNSIGNED DEFAULT NULL,
    `review_status` ENUM('pending', 'approved', 'rejected', 'skipped') NOT NULL DEFAULT 'pending',
    `migration_batch_id` BIGINT UNSIGNED DEFAULT NULL,
    `source_ref` VARCHAR(255) DEFAULT NULL,
    `source_snapshot_json` JSON DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_drill_legacy_content_mappings_source` (`source_type`, `source_id`),
    KEY `idx_drill_legacy_content_mappings_scenario` (`scenario_id`, `scenario_version_id`),
    KEY `idx_drill_legacy_content_mappings_batch_status` (`migration_batch_id`, `review_status`),
    CONSTRAINT `fk_drill_legacy_content_mappings_scenario` FOREIGN KEY (`scenario_id`) REFERENCES `drill_scenarios` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_drill_legacy_content_mappings_version` FOREIGN KEY (`scenario_version_id`) REFERENCES `drill_scenario_versions` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `drill_training_domains`
    (`domain_code`, `name`, `description`, `status`, `source_type`, `source_ref`)
VALUES
    ('new_signing', '新签训练', '面向新客户签约全流程的独立训练域', 'active', 'product_spec', 'sales-drill-rebuild:requirement-2'),
    ('renewal', '续费训练', '面向老客户续费流程的独立训练域，流程版本等待正式资料', 'active', 'product_spec', 'sales-drill-rebuild:requirement-2');

INSERT IGNORE INTO `drill_process_versions`
    (`domain_id`, `version_no`, `name`, `status`, `source_type`, `source_ref`, `published_at`)
SELECT `id`, 1, '新签标准流程 V1', 'published', 'product_spec', 'sales-drill-rebuild:requirement-2', CURRENT_TIMESTAMP
FROM `drill_training_domains`
WHERE `domain_code` = 'new_signing';

INSERT IGNORE INTO `drill_process_stages`
    (`process_version_id`, `stage_code`, `name`, `description`, `sort_order`, `required`, `status`, `source_type`, `source_ref`)
SELECT `id`, 'lead_preparation', '线索准备', '理解线索背景并完成沟通前准备', 1, 1, 'active', 'product_spec', 'sales-drill-rebuild:requirement-2'
FROM `drill_process_versions`
WHERE `domain_id` = (SELECT `id` FROM `drill_training_domains` WHERE `domain_code` = 'new_signing') AND `version_no` = 1
UNION ALL
SELECT `id`, 'invitation_confirmation', '邀约确认', '完成邀约沟通与到店确认', 2, 1, 'active', 'product_spec', 'sales-drill-rebuild:requirement-2'
FROM `drill_process_versions`
WHERE `domain_id` = (SELECT `id` FROM `drill_training_domains` WHERE `domain_code` = 'new_signing') AND `version_no` = 1
UNION ALL
SELECT `id`, 'arrival_reception', '到店接待', '建立初步信任并完成到店接待', 3, 1, 'active', 'product_spec', 'sales-drill-rebuild:requirement-2'
FROM `drill_process_versions`
WHERE `domain_id` = (SELECT `id` FROM `drill_training_domains` WHERE `domain_code` = 'new_signing') AND `version_no` = 1
UNION ALL
SELECT `id`, 'needs_diagnosis', '需求诊断', '识别客户目标、动机、障碍和决策条件', 4, 1, 'active', 'product_spec', 'sales-drill-rebuild:requirement-2'
FROM `drill_process_versions`
WHERE `domain_id` = (SELECT `id` FROM `drill_training_domains` WHERE `domain_code` = 'new_signing') AND `version_no` = 1
UNION ALL
SELECT `id`, 'assessment_experience', '体测与体验协同', '协同体测与体验环节并承接关键信息', 5, 1, 'active', 'product_spec', 'sales-drill-rebuild:requirement-2'
FROM `drill_process_versions`
WHERE `domain_id` = (SELECT `id` FROM `drill_training_domains` WHERE `domain_code` = 'new_signing') AND `version_no` = 1
UNION ALL
SELECT `id`, 'solution_value', '方案与价值呈现', '将客户需求映射为方案并呈现价值', 6, 1, 'active', 'product_spec', 'sales-drill-rebuild:requirement-2'
FROM `drill_process_versions`
WHERE `domain_id` = (SELECT `id` FROM `drill_training_domains` WHERE `domain_code` = 'new_signing') AND `version_no` = 1
UNION ALL
SELECT `id`, 'objection_signing_handoff', '异议及签约交接', '处理异议并完成签约或交接', 7, 1, 'active', 'product_spec', 'sales-drill-rebuild:requirement-2'
FROM `drill_process_versions`
WHERE `domain_id` = (SELECT `id` FROM `drill_training_domains` WHERE `domain_code` = 'new_signing') AND `version_no` = 1
UNION ALL
SELECT `id`, 'followup_referral', '未成交跟进与转介绍', '推进未成交客户跟进并建立转介绍机会', 8, 1, 'active', 'product_spec', 'sales-drill-rebuild:requirement-2'
FROM `drill_process_versions`
WHERE `domain_id` = (SELECT `id` FROM `drill_training_domains` WHERE `domain_code` = 'new_signing') AND `version_no` = 1;
