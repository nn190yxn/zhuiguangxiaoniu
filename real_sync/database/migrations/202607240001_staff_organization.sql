-- Staff and organization schema foundation.
-- This migration is additive so existing staff, store, authentication, and workload reads remain valid.

SET NAMES utf8mb4;

SET @migration_sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'staffs'
          AND COLUMN_NAME = 'lifecycle_status'
    ),
    'SELECT 1',
    'ALTER TABLE staffs ADD COLUMN lifecycle_status ENUM(''active'', ''inactive'', ''offboarded'') NOT NULL DEFAULT ''active'' AFTER status'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staffs' AND COLUMN_NAME = 'offboarded_at'
    ),
    'SELECT 1',
    'ALTER TABLE staffs ADD COLUMN offboarded_at DATETIME NULL AFTER lifecycle_status'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staffs' AND COLUMN_NAME = 'offboard_reason'
    ),
    'SELECT 1',
    'ALTER TABLE staffs ADD COLUMN offboard_reason VARCHAR(500) NULL AFTER offboarded_at'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staffs' AND COLUMN_NAME = 'offboarded_by'
    ),
    'SELECT 1',
    'ALTER TABLE staffs ADD COLUMN offboarded_by BIGINT UNSIGNED NULL AFTER offboard_reason'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staffs' AND COLUMN_NAME = 'session_version'
    ),
    'SELECT 1',
    'ALTER TABLE staffs ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER offboarded_by'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staffs' AND COLUMN_NAME = 'primary_position_id'
    ),
    'SELECT 1',
    'ALTER TABLE staffs ADD COLUMN primary_position_id BIGINT UNSIGNED NULL AFTER session_version'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stores' AND COLUMN_NAME = 'store_code'
    ),
    'SELECT 1',
    'ALTER TABLE stores ADD COLUMN store_code VARCHAR(64) NULL AFTER id'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stores' AND COLUMN_NAME = 'manager_staff_id'
    ),
    'SELECT 1',
    'ALTER TABLE stores ADD COLUMN manager_staff_id BIGINT UNSIGNED NULL AFTER store_code'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

UPDATE staffs
SET lifecycle_status = CASE WHEN status = 1 THEN 'active' ELSE 'inactive' END
WHERE offboarded_at IS NULL;

UPDATE stores
SET store_code = CONCAT('STORE-', LPAD(id, 4, '0'))
WHERE store_code IS NULL OR TRIM(store_code) = '';

SET @migration_sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'staffs'
          AND COLUMN_NAME = 'employee_no'
          AND NON_UNIQUE = 0
    ),
    'SELECT 1',
    'ALTER TABLE staffs ADD UNIQUE KEY uq_staffs_employee_no (employee_no)'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'staffs'
          AND COLUMN_NAME = 'user_id'
          AND NON_UNIQUE = 0
    ),
    'SELECT 1',
    'ALTER TABLE staffs ADD UNIQUE KEY uq_staffs_user_id (user_id)'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(
        SELECT INDEX_NAME
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'stores'
          AND INDEX_NAME = 'uq_stores_store_code'
          AND NON_UNIQUE = 0
        GROUP BY INDEX_NAME
        HAVING COUNT(*) = 1
           AND MAX(CASE WHEN COLUMN_NAME = 'store_code' THEN 1 ELSE 0 END) = 1
    ),
    'SELECT 1',
    'ALTER TABLE stores ADD UNIQUE KEY uq_stores_store_code (store_code)'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

CREATE TABLE IF NOT EXISTS organization_positions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    position_code VARCHAR(64) NOT NULL,
    position_name VARCHAR(100) NOT NULL,
    applicable_roles_json LONGTEXT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_organization_positions_code (position_code),
    KEY idx_organization_positions_status_sort (status, sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS staff_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    staff_id BIGINT UNSIGNED NOT NULL,
    store_id BIGINT UNSIGNED NOT NULL,
    position_id BIGINT UNSIGNED NOT NULL,
    system_role VARCHAR(50) NOT NULL,
    assignment_type ENUM('primary', 'secondary') NOT NULL DEFAULT 'primary',
    start_date DATE NOT NULL,
    end_date DATE NULL,
    change_reason VARCHAR(500) NULL,
    operator_staff_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_staff_assignments_staff_effective (staff_id, start_date, end_date, assignment_type),
    KEY idx_staff_assignments_store_effective (store_id, start_date, end_date),
    KEY idx_staff_assignments_position_effective (position_id, start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS staff_import_batches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_key CHAR(36) NOT NULL,
    file_name VARCHAR(255) NULL,
    file_sha256 CHAR(64) NULL,
    requested_by_staff_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    total_rows INT UNSIGNED NOT NULL DEFAULT 0,
    succeeded_rows INT UNSIGNED NOT NULL DEFAULT 0,
    failed_rows INT UNSIGNED NOT NULL DEFAULT 0,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_staff_import_batches_key (batch_key),
    KEY idx_staff_import_batches_requester_created (requested_by_staff_id, created_at),
    KEY idx_staff_import_batches_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS staff_import_rows (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_id BIGINT UNSIGNED NOT NULL,
    row_number INT UNSIGNED NOT NULL,
    raw_summary_json LONGTEXT NULL,
    validation_result_json LONGTEXT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    staff_id BIGINT UNSIGNED NULL,
    retry_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_staff_import_rows_batch_row (batch_id, row_number),
    KEY idx_staff_import_rows_status (batch_id, status),
    KEY idx_staff_import_rows_staff (staff_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS staff_profile_correction_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    staff_id BIGINT UNSIGNED NOT NULL,
    change_summary_json LONGTEXT NOT NULL,
    request_reason VARCHAR(500) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    handled_by_staff_id BIGINT UNSIGNED NULL,
    handler_comment VARCHAR(500) NULL,
    handled_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_staff_profile_corrections_staff_created (staff_id, created_at),
    KEY idx_staff_profile_corrections_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO organization_positions (
    position_code,
    position_name,
    applicable_roles_json,
    sort_order,
    status
)
VALUES
    ('sales', '销售', '["sales"]', 10, 1),
    ('coach', '教练', '["coach"]', 20, 1),
    ('manager', '店长', '["manager"]', 30, 1),
    ('operation', '总部运营', '["operation"]', 40, 1),
    ('finance', '财务', '["finance"]', 50, 1),
    ('ceo', '负责人', '["ceo"]', 60, 1),
    ('admin', '系统管理员', '["admin"]', 70, 1)
ON DUPLICATE KEY UPDATE
    position_name = VALUES(position_name),
    applicable_roles_json = VALUES(applicable_roles_json);

INSERT INTO organization_positions (
    position_code,
    position_name,
    applicable_roles_json,
    sort_order,
    status
)
SELECT DISTINCT
    CONCAT('legacy-', LEFT(SHA2(CONCAT(COALESCE(NULLIF(TRIM(s.role), ''), 'staff'), '|', TRIM(s.job_title)), 256), 16)),
    TRIM(s.job_title),
    CONCAT('["', REPLACE(COALESCE(NULLIF(TRIM(s.role), ''), 'staff'), '"', '\\"'), '"]'),
    1000,
    1
FROM staffs s
WHERE s.job_title IS NOT NULL
  AND TRIM(s.job_title) <> ''
ON DUPLICATE KEY UPDATE
    position_name = VALUES(position_name),
    applicable_roles_json = VALUES(applicable_roles_json);

INSERT INTO organization_positions (
    position_code,
    position_name,
    applicable_roles_json,
    sort_order,
    status
)
SELECT DISTINCT
    CONCAT('role-', LEFT(SHA2(COALESCE(NULLIF(TRIM(s.role), ''), 'staff'), 256), 16)),
    COALESCE(NULLIF(TRIM(s.role), ''), '员工'),
    CONCAT('["', REPLACE(COALESCE(NULLIF(TRIM(s.role), ''), 'staff'), '"', '\\"'), '"]'),
    1100,
    1
FROM staffs s
LEFT JOIN organization_positions known_position
    ON known_position.position_code = TRIM(s.role)
WHERE (s.job_title IS NULL OR TRIM(s.job_title) = '')
  AND known_position.id IS NULL
ON DUPLICATE KEY UPDATE
    position_name = VALUES(position_name),
    applicable_roles_json = VALUES(applicable_roles_json);

UPDATE staffs s
JOIN organization_positions p
    ON p.position_code = CASE
        WHEN s.job_title IS NOT NULL AND TRIM(s.job_title) <> ''
            THEN CONCAT('legacy-', LEFT(SHA2(CONCAT(COALESCE(NULLIF(TRIM(s.role), ''), 'staff'), '|', TRIM(s.job_title)), 256), 16))
        WHEN TRIM(s.role) IN ('sales', 'coach', 'manager', 'operation', 'finance', 'ceo', 'admin')
            THEN TRIM(s.role)
        ELSE CONCAT('role-', LEFT(SHA2(COALESCE(NULLIF(TRIM(s.role), ''), 'staff'), 256), 16))
    END
SET s.primary_position_id = p.id
WHERE s.primary_position_id IS NULL;

INSERT INTO staff_assignments (
    staff_id,
    store_id,
    position_id,
    system_role,
    assignment_type,
    start_date,
    change_reason,
    operator_staff_id
)
SELECT
    s.id,
    s.store_id,
    s.primary_position_id,
    COALESCE(NULLIF(TRIM(s.role), ''), 'staff'),
    'primary',
    COALESCE(NULLIF(s.entry_date, '0000-00-00'), DATE(s.created_at), CURRENT_DATE),
    'Initial assignment migrated from staffs',
    NULL
FROM staffs s
WHERE s.primary_position_id IS NOT NULL
  AND s.store_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1
      FROM staff_assignments existing_assignment
      WHERE existing_assignment.staff_id = s.id
        AND existing_assignment.assignment_type = 'primary'
  );
