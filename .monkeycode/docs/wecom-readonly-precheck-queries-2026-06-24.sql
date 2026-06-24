SHOW TABLES LIKE 'staffs';
SHOW TABLES LIKE 'wecom_%';

DESCRIBE staffs;

SELECT COUNT(*) AS staffs_total FROM staffs;

SELECT COUNT(*) AS active_staff_total
FROM staffs
WHERE status = 1;

SELECT status, role, COUNT(*) AS total
FROM staffs
GROUP BY status, role
ORDER BY status DESC, role ASC;

SELECT COUNT(*) AS active_staff_without_user_id
FROM staffs
WHERE status = 1 AND (user_id IS NULL OR user_id = 0);

SELECT id, employee_no, name, role, phone, store_id, user_id, status
FROM staffs
ORDER BY status DESC, id ASC
LIMIT 20;

SELECT COUNT(*) AS reminder_rule_total
FROM mini_reminder_rules;

SELECT COUNT(*) AS reminder_job_total
FROM mini_reminder_jobs;

SELECT COUNT(*) AS mini_notification_total
FROM mini_user_notifications;

SELECT COUNT(*) AS policy_notification_total
FROM policy_notifications;

SELECT rule_code, enabled, trigger_time, receiver_scope, receiver_roles
FROM mini_reminder_rules
ORDER BY id ASC;

SELECT id, rule_code, reminder_date, target_staff_id, target_user_id, status, created_at
FROM mini_reminder_jobs
ORDER BY id DESC
LIMIT 20;

SELECT id, source_type, source_key, user_id, staff_id, created_at
FROM mini_user_notifications
ORDER BY id DESC
LIMIT 20;

SELECT id, type, target_user_id, policy_id, is_read, is_confirmed, created_at
FROM policy_notifications
ORDER BY id DESC
LIMIT 20;
