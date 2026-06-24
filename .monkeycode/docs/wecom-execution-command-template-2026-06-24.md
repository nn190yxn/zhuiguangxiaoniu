# 企业微信执行命令模板

日期：2026-06-24

## 1. 说明

- 本模板用于企业微信数据库改造上线前后的现场执行。
- 所有命令都服务于“现网站和 H5 正常使用优先”的门禁。
- 先备份，后变更；先复核旧链路，后联调企业微信。

## 2. 只读预检命令模板

```bash
# 执行数据库变更前的只读预检
mysql -u <DB_USER> -p'<DB_PASSWORD>' -h <DB_HOST> <DB_NAME> < /www/wwwroot/122.51.223.46/.monkeycode/docs/wecom-readonly-precheck-queries-2026-06-24.sql
```

## 3. 数据库备份命令模板

```bash
# 导出 staffs 表结构
mysqldump -u <DB_USER> -p'<DB_PASSWORD>' -h <DB_HOST> --no-data <DB_NAME> staffs > staffs-schema-before-wecom.sql

# 导出 staffs 表数据
mysqldump -u <DB_USER> -p'<DB_PASSWORD>' -h <DB_HOST> --no-create-info <DB_NAME> staffs > staffs-data-before-wecom.sql

# 导出提醒相关表结构
mysqldump -u <DB_USER> -p'<DB_PASSWORD>' -h <DB_HOST> --no-data <DB_NAME> mini_reminder_rules mini_reminder_jobs mini_user_notifications policy_notifications > reminder-related-schema-before-wecom.sql

# 导出提醒相关表最近数据
mysqldump -u <DB_USER> -p'<DB_PASSWORD>' -h <DB_HOST> --no-create-info <DB_NAME> mini_reminder_rules mini_reminder_jobs mini_user_notifications policy_notifications > reminder-related-data-before-wecom.sql
```

## 4. 执行 SQL 命令模板

```bash
# 执行企业微信首批结构变更
mysql -u <DB_USER> -p'<DB_PASSWORD>' -h <DB_HOST> <DB_NAME> < /www/wwwroot/122.51.223.46/.monkeycode/docs/wecom-online-schema-2026-06-24.sql
```

## 5. 结构复核命令模板

```bash
# 查看 staffs 字段
mysql -u <DB_USER> -p'<DB_PASSWORD>' -h <DB_HOST> -D <DB_NAME> -e "DESCRIBE staffs"

# 查看企业微信日志表
mysql -u <DB_USER> -p'<DB_PASSWORD>' -h <DB_HOST> -D <DB_NAME> -e "SHOW TABLES LIKE 'wecom_%'"
```

```bash
# 结构变更后再次执行只读预检
mysql -u <DB_USER> -p'<DB_PASSWORD>' -h <DB_HOST> <DB_NAME> < /www/wwwroot/122.51.223.46/.monkeycode/docs/wecom-readonly-precheck-queries-2026-06-24.sql
```

## 6. 配置复核命令模板

```bash
# 只读检查 wecom 状态接口
curl -s "https://supercalf.com/api/wecom/status.php"
```

## 7. 旧链路复核命令模板

```bash
# H5 登录接口可达性
curl -s -X POST "https://supercalf.com/api/auth-jwt.php?action=login" -H "Content-Type: application/json" -d '{"username":"<USERNAME>","password":"<PASSWORD>","device_id":"browser-check","device_fingerprint":"browser-check"}'

# 学习待办接口可达性
curl -s "https://supercalf.com/api/todos/my.php"
```

说明：

- 真实登录验证建议使用浏览器或本地环境完成，避免在命令行保留正式账号密码痕迹。
- 命令行接口检查更适合作为可达性和返回结构快速复核。

## 8. Worker 命令模板

```bash
# 通讯录同步
php /www/wwwroot/122.51.223.46/api/wecom/sync-worker.php

# 提醒 worker
php /www/wwwroot/122.51.223.46/api/reminder/reminder-worker.php
```

```bash
# 挂 worker 前再次执行只读预检
mysql -u <DB_USER> -p'<DB_PASSWORD>' -h <DB_HOST> <DB_NAME> < /www/wwwroot/122.51.223.46/.monkeycode/docs/wecom-readonly-precheck-queries-2026-06-24.sql
```

## 9. 日志与数据回写复核命令模板

```bash
# 查看最新同步日志
mysql -u <DB_USER> -p'<DB_PASSWORD>' -h <DB_HOST> -D <DB_NAME> -e "SELECT id, sync_type, status, users_total, matched_total, updated_total, unbound_total, deactivated_total, created_at FROM wecom_sync_logs ORDER BY id DESC LIMIT 5"

# 查看最新消息日志
mysql -u <DB_USER> -p'<DB_PASSWORD>' -h <DB_HOST> -D <DB_NAME> -e "SELECT id, source_type, source_key, target_staff_id, target_wecom_userid, status, error_message, created_at FROM wecom_message_logs ORDER BY id DESC LIMIT 5"

# 查看 staffs 企业微信字段填充情况
mysql -u <DB_USER> -p'<DB_PASSWORD>' -h <DB_HOST> -D <DB_NAME> -e "SELECT id, employee_no, name, phone, wecom_userid, wecom_name, wecom_mobile, wecom_department_id, wecom_status, wecom_bound_at FROM staffs ORDER BY id ASC LIMIT 20"
```

## 10. 止损命令模板

```bash
# 停企业微信同步 worker 对应 cron 前先备份 crontab
crontab -l > crontab-before-wecom-stop.txt

# 查看当前 crontab
crontab -l
```

说明：

- 具体停 cron 动作需要按现场实际条目人工处理。
- 止损优先级固定为：停企业微信配置开关、停企业微信 worker、复核旧网站和 H5。

## 11. 执行记录要求

每执行完一组命令，记录：

1. 执行时间
2. 执行人
3. 命令类别
4. 输出结果
5. 是否允许继续下一步

建议回填：

- `wecom-gap-tracking-2026-06-24.md`
- `wecom-go-live-checklist-2026-06-24.md`
- `wecom-live-execution-record-template-2026-06-24.md`
