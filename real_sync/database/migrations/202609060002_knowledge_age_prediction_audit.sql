SET @knowledge_age_prediction_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'knowledge_item_age_ranges' AND COLUMN_NAME = 'decision_source'),
    'SELECT 1',
    'ALTER TABLE knowledge_item_age_ranges ADD COLUMN decision_source VARCHAR(24) NOT NULL DEFAULT ''import'' AFTER review_status, ADD COLUMN review_note VARCHAR(1000) DEFAULT NULL AFTER decision_source'
);
PREPARE knowledge_age_prediction_statement FROM @knowledge_age_prediction_sql;
EXECUTE knowledge_age_prediction_statement;
DEALLOCATE PREPARE knowledge_age_prediction_statement;
