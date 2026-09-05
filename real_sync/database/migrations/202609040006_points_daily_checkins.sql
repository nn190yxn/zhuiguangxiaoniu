ALTER TABLE points_records
    ADD COLUMN business_date DATE NULL AFTER source_id;

UPDATE points_records records
INNER JOIN points_rules rules ON rules.id = records.rule_id AND rules.code = 'daily_checkin'
SET records.business_date = DATE(records.created_at)
WHERE records.business_date IS NULL;

ALTER TABLE points_records
    ADD UNIQUE KEY uq_points_records_user_business_date (user_id, business_date);
