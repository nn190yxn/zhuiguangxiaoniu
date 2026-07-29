-- Drill knowledge graph governance and learning service constraints.

SET NAMES utf8mb4;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_content_gaps' AND COLUMN_NAME = 'source_attempt_id'),
    'SELECT 1',
    'ALTER TABLE drill_content_gaps ADD COLUMN source_attempt_id BIGINT UNSIGNED NULL AFTER knowledge_point_id'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_content_gaps' AND COLUMN_NAME = 'gap_fingerprint'),
    'SELECT 1',
    'ALTER TABLE drill_content_gaps ADD COLUMN gap_fingerprint CHAR(64) NULL AFTER gap_type'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

UPDATE `drill_content_gaps`
SET `gap_fingerprint` = SHA2(CONCAT_WS('|', `domain_id`, `mapping_version_id`, `rubric_version_id`, `dimension_code`, `criterion_code`, COALESCE(`knowledge_point_id`, 0), `gap_type`), 256)
WHERE `gap_fingerprint` IS NULL;

UPDATE `drill_content_gaps` duplicate_gap
INNER JOIN `drill_content_gaps` canonical_gap
    ON canonical_gap.gap_fingerprint = duplicate_gap.gap_fingerprint
    AND canonical_gap.status = 'open'
    AND duplicate_gap.status = 'open'
    AND canonical_gap.id < duplicate_gap.id
SET duplicate_gap.status = 'waived';

ALTER TABLE `drill_content_gaps` MODIFY COLUMN `gap_fingerprint` CHAR(64) NOT NULL;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_content_gaps' AND COLUMN_NAME = 'open_gap_fingerprint'),
    'SELECT 1',
    'ALTER TABLE drill_content_gaps ADD COLUMN open_gap_fingerprint CHAR(64) NULL'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

UPDATE `drill_content_gaps`
SET `open_gap_fingerprint` = CASE WHEN `status` = 'open' THEN `gap_fingerprint` ELSE NULL END;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_content_gaps' AND INDEX_NAME = 'uk_drill_content_gaps_open'),
    'SELECT 1',
    'ALTER TABLE drill_content_gaps ADD UNIQUE KEY uk_drill_content_gaps_open (open_gap_fingerprint)'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = 'trg_drill_content_gaps_open_insert'),
    'SELECT 1',
    'CREATE TRIGGER trg_drill_content_gaps_open_insert BEFORE INSERT ON drill_content_gaps FOR EACH ROW SET NEW.open_gap_fingerprint = IF(NEW.status = ''open'', NEW.gap_fingerprint, NULL)'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = 'trg_drill_content_gaps_open_update'),
    'SELECT 1',
    'CREATE TRIGGER trg_drill_content_gaps_open_update BEFORE UPDATE ON drill_content_gaps FOR EACH ROW SET NEW.open_gap_fingerprint = IF(NEW.status = ''open'', NEW.gap_fingerprint, NULL)'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_content_gaps' AND INDEX_NAME = 'idx_drill_content_gaps_attempt'),
    'SELECT 1',
    'ALTER TABLE drill_content_gaps ADD KEY idx_drill_content_gaps_attempt (source_attempt_id, domain_id)'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_content_gaps' AND CONSTRAINT_NAME = 'fk_drill_content_gaps_attempt'),
    'SELECT 1',
    'ALTER TABLE drill_content_gaps ADD CONSTRAINT fk_drill_content_gaps_attempt FOREIGN KEY (source_attempt_id, domain_id) REFERENCES drill_attempts (id, domain_id) ON DELETE RESTRICT'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_learning_progress' AND COLUMN_NAME = 'mapping_version_id'),
    'SELECT 1',
    'ALTER TABLE drill_learning_progress ADD COLUMN mapping_version_id BIGINT UNSIGNED NULL AFTER domain_id'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_learning_progress' AND COLUMN_NAME = 'knowledge_point_id'),
    'SELECT 1',
    'ALTER TABLE drill_learning_progress ADD COLUMN knowledge_point_id BIGINT UNSIGNED NULL AFTER mapping_version_id'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_learning_progress' AND COLUMN_NAME = 'knowledge_point_version_id'),
    'SELECT 1',
    'ALTER TABLE drill_learning_progress ADD COLUMN knowledge_point_version_id BIGINT UNSIGNED NULL AFTER knowledge_point_id'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_learning_progress' AND INDEX_NAME = 'idx_drill_learning_progress_knowledge'),
    'SELECT 1',
    'ALTER TABLE drill_learning_progress ADD KEY idx_drill_learning_progress_knowledge (staff_id, domain_id, knowledge_point_version_id, status)'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_learning_progress' AND CONSTRAINT_NAME = 'fk_drill_learning_progress_mapping'),
    'SELECT 1',
    'ALTER TABLE drill_learning_progress ADD CONSTRAINT fk_drill_learning_progress_mapping FOREIGN KEY (mapping_version_id, domain_id) REFERENCES drill_knowledge_mapping_versions (id, domain_id) ON DELETE RESTRICT'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_learning_progress' AND CONSTRAINT_NAME = 'fk_drill_learning_progress_point'),
    'SELECT 1',
    'ALTER TABLE drill_learning_progress ADD CONSTRAINT fk_drill_learning_progress_point FOREIGN KEY (knowledge_point_version_id, knowledge_point_id, domain_id) REFERENCES drill_knowledge_point_versions (id, knowledge_point_id, domain_id) ON DELETE RESTRICT'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;
