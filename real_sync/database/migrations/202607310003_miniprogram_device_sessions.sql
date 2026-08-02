-- Bind mini program sessions to a privacy-preserving WeChat identity digest.

SET @identity_hash_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'platform_sessions'
      AND COLUMN_NAME = 'identity_hash'
);

SET @identity_hash_sql = IF(
    @identity_hash_exists = 0,
    'ALTER TABLE platform_sessions ADD COLUMN identity_hash CHAR(64) NULL AFTER device_id',
    'SELECT 1'
);

PREPARE identity_hash_stmt FROM @identity_hash_sql;
EXECUTE identity_hash_stmt;
DEALLOCATE PREPARE identity_hash_stmt;
