-- Seed idempotent reminder rules for daily workload makeup and overdue penalties.

SET NAMES utf8mb4;

INSERT INTO mini_reminder_rules
    (rule_code, rule_name, scene_code, channel_code, recipient_scope, target_roles, schedule_time, enabled, config_json)
VALUES
    ('workload_makeup_employee', '工作量补齐提醒', 'workload', 'station+wechat', 'staff', 'sales,coach,manager', '09:00', 1, JSON_OBJECT('phase', 'makeup')),
    ('workload_makeup_manager', '门店工作量补齐跟进', 'workload', 'station+wechat', 'manager', 'manager', '09:00', 1, JSON_OBJECT('phase', 'makeup')),
    ('workload_penalty_employee', '工作量逾期处理结果', 'workload', 'station+wechat', 'staff', 'sales,coach,manager', '00:05', 1, JSON_OBJECT('phase', 'penalty')),
    ('workload_penalty_manager', '门店工作量逾期跟进', 'workload', 'station+wechat', 'manager', 'manager', '00:05', 1, JSON_OBJECT('phase', 'penalty')),
    ('workload_penalty_hq', '工作量逾期处罚待处理汇总', 'workload', 'station+wechat', 'headquarter', 'operation,finance,admin,ceo', '00:05', 1, JSON_OBJECT('phase', 'penalty'))
ON DUPLICATE KEY UPDATE
    rule_name = VALUES(rule_name),
    scene_code = VALUES(scene_code),
    channel_code = VALUES(channel_code),
    recipient_scope = VALUES(recipient_scope),
    target_roles = VALUES(target_roles),
    schedule_time = VALUES(schedule_time),
    enabled = VALUES(enabled),
    config_json = VALUES(config_json);
