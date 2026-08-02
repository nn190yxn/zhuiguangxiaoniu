-- Move authentication, device, and WeCom identity schema out of request paths.

SET NAMES utf8mb4;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staffs' AND COLUMN_NAME = 'openid'),
    'SELECT 1',
    'ALTER TABLE staffs ADD COLUMN openid VARCHAR(128) NULL'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staffs' AND COLUMN_NAME = 'openid_bound_at'),
    'SELECT 1',
    'ALTER TABLE staffs ADD COLUMN openid_bound_at DATETIME NULL'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staffs' AND COLUMN_NAME = 'wecom_userid'),
    'SELECT 1',
    'ALTER TABLE staffs ADD COLUMN wecom_userid VARCHAR(128) NULL'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staffs' AND COLUMN_NAME = 'wecom_name'),
    'SELECT 1',
    'ALTER TABLE staffs ADD COLUMN wecom_name VARCHAR(100) NULL'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staffs' AND COLUMN_NAME = 'wecom_mobile'),
    'SELECT 1',
    'ALTER TABLE staffs ADD COLUMN wecom_mobile VARCHAR(32) NULL'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staffs' AND COLUMN_NAME = 'wecom_department_id'),
    'SELECT 1',
    'ALTER TABLE staffs ADD COLUMN wecom_department_id VARCHAR(128) NULL'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staffs' AND COLUMN_NAME = 'wecom_department_path'),
    'SELECT 1',
    'ALTER TABLE staffs ADD COLUMN wecom_department_path VARCHAR(255) NULL'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staffs' AND COLUMN_NAME = 'wecom_status'),
    'SELECT 1',
    'ALTER TABLE staffs ADD COLUMN wecom_status TINYINT NULL'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staffs' AND COLUMN_NAME = 'wecom_bound_at'),
    'SELECT 1',
    'ALTER TABLE staffs ADD COLUMN wecom_bound_at DATETIME NULL'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

CREATE TABLE IF NOT EXISTS login_audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NULL,
    staff_id INT UNSIGNED NULL,
    login_type VARCHAR(40) NOT NULL DEFAULT 'password',
    login_status VARCHAR(20) NOT NULL DEFAULT 'success',
    source VARCHAR(60) NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    message VARCHAR(255) NULL,
    device_id VARCHAR(120) NULL,
    device_fingerprint VARCHAR(120) NULL,
    is_new_device TINYINT(1) NOT NULL DEFAULT 0,
    risk_level VARCHAR(20) NOT NULL DEFAULT 'normal',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_created (created_at),
    KEY idx_staff_created (staff_id, created_at),
    KEY idx_status_created (login_status, created_at),
    KEY idx_source_created (source, created_at),
    KEY idx_device_created (device_fingerprint, created_at),
    KEY idx_risk_created (risk_level, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS device_logins (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    staff_id BIGINT UNSIGNED NOT NULL,
    openid VARCHAR(128) NULL,
    device_id VARCHAR(120) NULL,
    device_fingerprint VARCHAR(120) NOT NULL,
    device_name VARCHAR(120) NULL,
    device_model VARCHAR(120) NULL,
    os_version VARCHAR(120) NULL,
    app_version VARCHAR(60) NULL,
    screen_width INT NOT NULL DEFAULT 0,
    screen_height INT NOT NULL DEFAULT 0,
    login_count INT NOT NULL DEFAULT 0,
    is_trusted TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    first_login DATETIME NULL,
    last_login DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_staff_device (staff_id, device_fingerprint),
    KEY idx_last_login (last_login)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @migration_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_audit_logs' AND COLUMN_NAME = 'device_id'), 'SELECT 1', 'ALTER TABLE login_audit_logs ADD COLUMN device_id VARCHAR(120) NULL');
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_audit_logs' AND COLUMN_NAME = 'device_fingerprint'), 'SELECT 1', 'ALTER TABLE login_audit_logs ADD COLUMN device_fingerprint VARCHAR(120) NULL');
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_audit_logs' AND COLUMN_NAME = 'is_new_device'), 'SELECT 1', 'ALTER TABLE login_audit_logs ADD COLUMN is_new_device TINYINT(1) NOT NULL DEFAULT 0');
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_audit_logs' AND COLUMN_NAME = 'risk_level'), 'SELECT 1', 'ALTER TABLE login_audit_logs ADD COLUMN risk_level VARCHAR(20) NOT NULL DEFAULT ''normal''');
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'device_logins' AND COLUMN_NAME = 'openid'), 'SELECT 1', 'ALTER TABLE device_logins ADD COLUMN openid VARCHAR(128) NULL');
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'device_logins' AND COLUMN_NAME = 'device_id'), 'SELECT 1', 'ALTER TABLE device_logins ADD COLUMN device_id VARCHAR(120) NULL');
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'device_logins' AND COLUMN_NAME = 'device_fingerprint'), 'SELECT 1', 'ALTER TABLE device_logins ADD COLUMN device_fingerprint VARCHAR(120) NOT NULL DEFAULT ''''');
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'device_logins' AND COLUMN_NAME = 'device_name'), 'SELECT 1', 'ALTER TABLE device_logins ADD COLUMN device_name VARCHAR(120) NULL');
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'device_logins' AND COLUMN_NAME = 'device_model'), 'SELECT 1', 'ALTER TABLE device_logins ADD COLUMN device_model VARCHAR(120) NULL');
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'device_logins' AND COLUMN_NAME = 'os_version'), 'SELECT 1', 'ALTER TABLE device_logins ADD COLUMN os_version VARCHAR(120) NULL');
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'device_logins' AND COLUMN_NAME = 'app_version'), 'SELECT 1', 'ALTER TABLE device_logins ADD COLUMN app_version VARCHAR(60) NULL');
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'device_logins' AND COLUMN_NAME = 'screen_width'), 'SELECT 1', 'ALTER TABLE device_logins ADD COLUMN screen_width INT NOT NULL DEFAULT 0');
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'device_logins' AND COLUMN_NAME = 'screen_height'), 'SELECT 1', 'ALTER TABLE device_logins ADD COLUMN screen_height INT NOT NULL DEFAULT 0');
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'device_logins' AND COLUMN_NAME = 'login_count'), 'SELECT 1', 'ALTER TABLE device_logins ADD COLUMN login_count INT NOT NULL DEFAULT 0');
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'device_logins' AND COLUMN_NAME = 'is_trusted'), 'SELECT 1', 'ALTER TABLE device_logins ADD COLUMN is_trusted TINYINT(1) NOT NULL DEFAULT 0');
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'device_logins' AND COLUMN_NAME = 'is_active'), 'SELECT 1', 'ALTER TABLE device_logins ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1');
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'device_logins' AND COLUMN_NAME = 'first_login'), 'SELECT 1', 'ALTER TABLE device_logins ADD COLUMN first_login DATETIME NULL');
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'device_logins' AND COLUMN_NAME = 'last_login'), 'SELECT 1', 'ALTER TABLE device_logins ADD COLUMN last_login DATETIME NULL');
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'device_logins' AND COLUMN_NAME = 'device_fingerprint' AND CHARACTER_MAXIMUM_LENGTH < 120),
    'ALTER TABLE device_logins MODIFY COLUMN device_fingerprint VARCHAR(120) NOT NULL DEFAULT ''''',
    'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;
