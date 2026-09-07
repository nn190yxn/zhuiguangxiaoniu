-- 教案课程上下文：统一年龄段和班级阶段，用于建议匹配与组合搜索
SET @migration_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lesson_submissions' AND COLUMN_NAME = 'age_range'), 'SELECT 1', 'ALTER TABLE lesson_submissions ADD COLUMN age_range VARCHAR(32) NOT NULL DEFAULT ''全年龄段'' AFTER course_line');
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lesson_submissions' AND COLUMN_NAME = 'class_stage'), 'SELECT 1', 'ALTER TABLE lesson_submissions ADD COLUMN class_stage VARCHAR(32) NOT NULL DEFAULT ''初级'' AFTER age_range');
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;
