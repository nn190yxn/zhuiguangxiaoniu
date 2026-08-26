-- 202608260003 二期知识卡导入 manifest 完整性（幂等、增量）
SET NAMES utf8mb4;

SET @knowledge_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'knowledge_import_batches' AND COLUMN_NAME = 'manifest_sha256'),
    'SELECT 1',
    'ALTER TABLE knowledge_import_batches ADD COLUMN manifest_sha256 CHAR(64) NULL AFTER manifest_path'
);
PREPARE knowledge_statement FROM @knowledge_sql;
EXECUTE knowledge_statement;
DEALLOCATE PREPARE knowledge_statement;

SET @knowledge_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'knowledge_import_batches' AND CONSTRAINT_NAME = 'chk_knowledge_import_batches_manifest_sha256'),
    'SELECT 1',
    'ALTER TABLE knowledge_import_batches ADD CONSTRAINT chk_knowledge_import_batches_manifest_sha256 CHECK (manifest_sha256 IS NULL OR manifest_sha256 REGEXP ''^[a-f0-9]{64}$'')'
);
PREPARE knowledge_statement FROM @knowledge_sql;
EXECUTE knowledge_statement;
DEALLOCATE PREPARE knowledge_statement;
