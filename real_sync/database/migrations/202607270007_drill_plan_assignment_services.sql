-- Training plan publication and employee assignment service constraints.

CREATE TABLE IF NOT EXISTS `drill_plan_item_reference_bindings` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `plan_item_id` BIGINT UNSIGNED NOT NULL,
    `material_version_id` BIGINT UNSIGNED NOT NULL,
    `purpose_code` VARCHAR(64) NOT NULL DEFAULT 'training_reference',
    `required` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_drill_plan_item_reference` (`plan_item_id`, `material_version_id`, `purpose_code`),
    KEY `idx_drill_plan_item_reference_material` (`material_version_id`, `plan_item_id`),
    CONSTRAINT `fk_drill_plan_item_reference_item` FOREIGN KEY (`plan_item_id`) REFERENCES `drill_plan_items` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_drill_plan_item_reference_material` FOREIGN KEY (`material_version_id`) REFERENCES `drill_reference_material_versions` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drill_assignment_prerequisite_snapshots` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `assignment_id` BIGINT UNSIGNED NOT NULL,
    `evaluation_status` ENUM('eligible', 'blocked') NOT NULL,
    `policy_hash` CHAR(64) NOT NULL,
    `policy_snapshot_json` JSON NOT NULL,
    `evaluation_result_json` JSON NOT NULL,
    `evaluated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_drill_assignment_prerequisite_history` (`assignment_id`, `evaluated_at`, `id`),
    KEY `idx_drill_assignment_prerequisite_status` (`evaluation_status`, `evaluated_at`),
    CONSTRAINT `fk_drill_assignment_prerequisite_assignment` FOREIGN KEY (`assignment_id`) REFERENCES `drill_assignments` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_assignment_prerequisite_snapshots' AND INDEX_NAME = 'uk_drill_assignment_prerequisite'),
    'ALTER TABLE drill_assignment_prerequisite_snapshots DROP INDEX uk_drill_assignment_prerequisite',
    'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_assignment_prerequisite_snapshots' AND INDEX_NAME = 'idx_drill_assignment_prerequisite_history'),
    'SELECT 1',
    'ALTER TABLE drill_assignment_prerequisite_snapshots ADD KEY idx_drill_assignment_prerequisite_history (assignment_id, evaluated_at, id)'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_assignment_prerequisite_snapshots' AND INDEX_NAME = 'idx_drill_assignment_prerequisite_status'),
    'SELECT 1',
    'ALTER TABLE drill_assignment_prerequisite_snapshots ADD KEY idx_drill_assignment_prerequisite_status (evaluation_status, evaluated_at)'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

INSERT INTO drill_assignment_prerequisite_snapshots (
    assignment_id,
    evaluation_status,
    policy_hash,
    policy_snapshot_json,
    evaluation_result_json
)
SELECT
    assignment.id,
    CASE
        WHEN JSON_LENGTH(COALESCE(
            JSON_EXTRACT(JSON_UNQUOTE(JSON_EXTRACT(plan_snapshot.snapshot_json, '$.prerequisite_policy_json')), '$.conditions'),
            JSON_EXTRACT(plan.prerequisite_policy_json, '$.conditions'),
            JSON_ARRAY()
        )) = 0 THEN 'eligible'
        ELSE 'blocked'
    END,
    SHA2(CAST(COALESCE(
        JSON_EXTRACT(JSON_UNQUOTE(JSON_EXTRACT(plan_snapshot.snapshot_json, '$.prerequisite_policy_json')), '$'),
        plan.prerequisite_policy_json,
        JSON_ARRAY()
    ) AS CHAR), 256),
    COALESCE(
        JSON_EXTRACT(JSON_UNQUOTE(JSON_EXTRACT(plan_snapshot.snapshot_json, '$.prerequisite_policy_json')), '$'),
        plan.prerequisite_policy_json,
        JSON_ARRAY()
    ),
    JSON_OBJECT(
        'eligible', JSON_LENGTH(COALESCE(
            JSON_EXTRACT(JSON_UNQUOTE(JSON_EXTRACT(plan_snapshot.snapshot_json, '$.prerequisite_policy_json')), '$.conditions'),
            JSON_EXTRACT(plan.prerequisite_policy_json, '$.conditions'),
            JSON_ARRAY()
        )) = 0,
        'conditions', JSON_ARRAY(),
        'source', 'migration_backfill'
    )
FROM drill_assignments assignment
INNER JOIN drill_plan_publications publication ON publication.id = assignment.publication_id
INNER JOIN drill_plans plan ON plan.id = publication.plan_id
LEFT JOIN drill_publication_snapshots plan_snapshot ON plan_snapshot.publication_id = publication.id
    AND plan_snapshot.snapshot_type = 'plan'
    AND plan_snapshot.snapshot_key = 'plan'
WHERE NOT EXISTS (
    SELECT 1
    FROM drill_assignment_prerequisite_snapshots existing
    WHERE existing.assignment_id = assignment.id
);

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_plan_publications' AND COLUMN_NAME = 'publication_key'),
    'SELECT 1',
    'ALTER TABLE drill_plan_publications ADD COLUMN publication_key VARCHAR(64) NULL AFTER publication_no'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_plan_publications' AND COLUMN_NAME = 'publication_request_hash'),
    'SELECT 1',
    'ALTER TABLE drill_plan_publications ADD COLUMN publication_request_hash CHAR(64) NULL AFTER publication_key'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

UPDATE drill_plan_publications
SET publication_key = CONCAT('legacy-', publication_no)
WHERE publication_key IS NULL OR TRIM(publication_key) = '';

UPDATE drill_plan_publications
SET publication_request_hash = SHA2(CONCAT('legacy-publication:', id), 256)
WHERE publication_request_hash IS NULL OR TRIM(publication_request_hash) = '';

ALTER TABLE drill_plan_publications MODIFY COLUMN publication_key VARCHAR(64) NOT NULL;
ALTER TABLE drill_plan_publications MODIFY COLUMN publication_request_hash CHAR(64) NOT NULL;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_plan_publications' AND INDEX_NAME = 'uk_drill_plan_publications_key'),
    'SELECT 1',
    'ALTER TABLE drill_plan_publications ADD UNIQUE KEY uk_drill_plan_publications_key (plan_id, publication_key)'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_plans' AND CONSTRAINT_NAME = 'chk_drill_plans_status'),
    'SELECT 1',
    'ALTER TABLE drill_plans ADD CONSTRAINT chk_drill_plans_status CHECK (status IN (''draft'', ''published'', ''archived''))'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_plan_publications' AND CONSTRAINT_NAME = 'chk_drill_plan_publications_status'),
    'SELECT 1',
    'ALTER TABLE drill_plan_publications ADD CONSTRAINT chk_drill_plan_publications_status CHECK (status IN (''draft'', ''published'', ''cancelled''))'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_assignments' AND CONSTRAINT_NAME = 'chk_drill_assignments_status'),
    'SELECT 1',
    'ALTER TABLE drill_assignments ADD CONSTRAINT chk_drill_assignments_status CHECK (status IN (''assigned'', ''in_progress'', ''ai_evaluating'', ''awaiting_review'', ''retry_available'', ''coaching_required'', ''passed'', ''cancelled''))'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_attempts' AND INDEX_NAME = 'uk_drill_attempts_id_assignment'),
    'SELECT 1',
    'ALTER TABLE drill_attempts ADD UNIQUE KEY uk_drill_attempts_id_assignment (id, assignment_id)'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'drill_assignments' AND CONSTRAINT_NAME = 'fk_drill_assignments_current_attempt_scope'),
    'SELECT 1',
    'ALTER TABLE drill_assignments ADD CONSTRAINT fk_drill_assignments_current_attempt_scope FOREIGN KEY (current_attempt_id, id) REFERENCES drill_attempts (id, assignment_id) ON DELETE RESTRICT'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;
