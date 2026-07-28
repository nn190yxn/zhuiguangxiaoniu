-- Drill attempt snapshots, stage progress, and resumable conversation state.

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_attempts' AND COLUMN_NAME = 'process_snapshot_json'),
    'SELECT 1',
    'ALTER TABLE drill_attempts ADD COLUMN process_snapshot_json JSON NULL AFTER persona_snapshot_hash'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_attempts' AND COLUMN_NAME = 'process_snapshot_hash'),
    'SELECT 1',
    'ALTER TABLE drill_attempts ADD COLUMN process_snapshot_hash CHAR(64) NULL AFTER process_snapshot_json'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_attempts' AND COLUMN_NAME = 'scenario_snapshot_hash'),
    'SELECT 1',
    'ALTER TABLE drill_attempts ADD COLUMN scenario_snapshot_hash CHAR(64) NULL AFTER scenario_snapshot_json'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_attempts' AND COLUMN_NAME = 'rubric_snapshot_hash'),
    'SELECT 1',
    'ALTER TABLE drill_attempts ADD COLUMN rubric_snapshot_hash CHAR(64) NULL AFTER rubric_snapshot_json'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_attempts' AND COLUMN_NAME = 'calibration_snapshot_json'),
    'SELECT 1',
    'ALTER TABLE drill_attempts ADD COLUMN calibration_snapshot_json JSON NULL AFTER rubric_snapshot_hash'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_attempts' AND COLUMN_NAME = 'calibration_snapshot_hash'),
    'SELECT 1',
    'ALTER TABLE drill_attempts ADD COLUMN calibration_snapshot_hash CHAR(64) NULL AFTER calibration_snapshot_json'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_attempts' AND COLUMN_NAME = 'session_goal_snapshot_hash'),
    'SELECT 1',
    'ALTER TABLE drill_attempts ADD COLUMN session_goal_snapshot_hash CHAR(64) NULL AFTER session_goal_json'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

UPDATE drill_attempts
SET process_snapshot_json = JSON_OBJECT('version_id', process_version_id, 'source', 'migration_backfill')
WHERE process_snapshot_json IS NULL;

UPDATE drill_attempts
SET calibration_snapshot_json = JSON_OBJECT('version_id', calibration_version_id, 'source', 'migration_backfill')
WHERE calibration_snapshot_json IS NULL;

UPDATE drill_attempts
SET process_snapshot_hash = SHA2(CAST(process_snapshot_json AS CHAR), 256),
    scenario_snapshot_hash = SHA2(CAST(scenario_snapshot_json AS CHAR), 256),
    rubric_snapshot_hash = SHA2(CAST(rubric_snapshot_json AS CHAR), 256),
    calibration_snapshot_hash = SHA2(CAST(calibration_snapshot_json AS CHAR), 256),
    session_goal_snapshot_hash = SHA2(CAST(session_goal_json AS CHAR), 256)
WHERE process_snapshot_hash IS NULL
   OR scenario_snapshot_hash IS NULL
   OR rubric_snapshot_hash IS NULL
   OR calibration_snapshot_hash IS NULL
   OR session_goal_snapshot_hash IS NULL;

ALTER TABLE drill_attempts MODIFY COLUMN process_snapshot_json JSON NOT NULL;
ALTER TABLE drill_attempts MODIFY COLUMN process_snapshot_hash CHAR(64) NOT NULL;
ALTER TABLE drill_attempts MODIFY COLUMN scenario_snapshot_hash CHAR(64) NOT NULL;
ALTER TABLE drill_attempts MODIFY COLUMN rubric_snapshot_hash CHAR(64) NOT NULL;
ALTER TABLE drill_attempts MODIFY COLUMN calibration_snapshot_json JSON NOT NULL;
ALTER TABLE drill_attempts MODIFY COLUMN calibration_snapshot_hash CHAR(64) NOT NULL;
ALTER TABLE drill_attempts MODIFY COLUMN session_goal_snapshot_hash CHAR(64) NOT NULL;

CREATE TABLE IF NOT EXISTS `drill_attempt_stage_progress` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `attempt_id` BIGINT UNSIGNED NOT NULL,
    `stage_id` BIGINT UNSIGNED NOT NULL,
    `sort_order` SMALLINT UNSIGNED NOT NULL,
    `status` ENUM('pending', 'active', 'completed', 'skipped') NOT NULL DEFAULT 'pending',
    `started_at` DATETIME DEFAULT NULL,
    `completed_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_drill_attempt_stage_progress_stage` (`attempt_id`, `stage_id`),
    UNIQUE KEY `uk_drill_attempt_stage_progress_order` (`attempt_id`, `sort_order`),
    KEY `idx_drill_attempt_stage_progress_status` (`attempt_id`, `status`, `sort_order`),
    CONSTRAINT `fk_drill_attempt_stage_progress_attempt` FOREIGN KEY (`attempt_id`) REFERENCES `drill_attempts` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_drill_attempt_stage_progress_stage` FOREIGN KEY (`stage_id`) REFERENCES `drill_process_stages` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_drill_attempt_stage_progress_order` CHECK (`sort_order` > 0),
    CONSTRAINT `chk_drill_attempt_stage_progress_times` CHECK ((`status` = 'pending' AND `started_at` IS NULL AND `completed_at` IS NULL) OR (`status` = 'active' AND `started_at` IS NOT NULL AND `completed_at` IS NULL) OR (`status` IN ('completed', 'skipped') AND `completed_at` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_attempts' AND CONSTRAINT_NAME = 'chk_drill_attempts_status'),
    'SELECT 1',
    'ALTER TABLE drill_attempts ADD CONSTRAINT chk_drill_attempts_status CHECK (status IN (''created'', ''active'', ''paused'', ''turn_finalizing'', ''evaluating'', ''speaker_confirmation_required'', ''evaluated'', ''completed'', ''failed''))'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;
