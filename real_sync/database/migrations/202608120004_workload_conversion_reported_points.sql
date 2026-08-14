-- Persist converted reported points independently from the source metric value.

SET NAMES utf8mb4;

SET @reported_points_column_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'workload_report_conversion_results'
      AND COLUMN_NAME = 'reported_points'
);

SET @reported_points_sql := IF(
    @reported_points_column_exists = 0,
    'ALTER TABLE workload_report_conversion_results ADD COLUMN reported_points DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER raw_value',
    'SELECT 1'
);

PREPARE reported_points_stmt FROM @reported_points_sql;
EXECUTE reported_points_stmt;
DEALLOCATE PREPARE reported_points_stmt;
