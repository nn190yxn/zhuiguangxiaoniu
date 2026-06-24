# 企业微信只读预检基线样例

日期：2026-06-24

## 1. 用途

- 本文件用于记录当前已知线上基线。
- 现场执行前先对照本文件，再执行最新一轮只读预检。
- 如现场预检结果与本文件不一致，以现场最新只读预检结果为准，并记录差异原因。

## 2. 当前已知结构基线

### 2.1 `staffs`

- 表存在。
- 当前仅确认包含原内网字段与：
  - `openid`
  - `openid_bound_at`
- 当前未确认存在：
  - `wecom_userid`
  - `wecom_name`
  - `wecom_mobile`
  - `wecom_department_id`
  - `wecom_department_path`
  - `wecom_status`
  - `wecom_bound_at`

### 2.2 企业微信相关表

- 当前未确认存在：
  - `wecom_sync_logs`
  - `wecom_message_logs`

## 3. 当前已知员工主数据基线

- `staffs_total = 56`
- `active_staff_total = 42`
- 活跃员工 `staffs.user_id` 缺口：`0`
- `mini_reminder_rules = 4`
- `mini_reminder_jobs = 0`
- `mini_user_notifications = 0`
- `policy_notifications = 0`

### 3.1 当前已知活跃角色分布

| role | total |
| --- | --- |
| `coach` | 28 |
| `manager` | 6 |
| `sales` | 4 |
| `operation` | 2 |
| `finance` | 1 |
| `ceo` | 1 |

## 4. 当前已知配置基线

- `WECOM_ENABLED = null`
- `WECOM_CORP_ID = null`
- `WECOM_AGENT_ID = null`
- `WECOM_APPID = null`
- `WECOM_AGENT_SECRET = null`
- `WECOM_MINI_PROGRAM_SECRET = null`
- `WECOM_SYNC_ROOT_DEPARTMENT_ID = null`
- 当前线上不存在：`/www/wwwroot/122.51.223.46/.env.local.php`

## 5. 当前已知调度基线

- 当前 `crontab -l` 中未确认存在企业微信同步相关 cron。
- 当前 `crontab -l` 中未确认存在企业微信提醒相关 cron。
- 当前保留的已知任务包括：
  - `wp-cron.php`
  - `cron_monthly_stats.php`
  - `api/skill/skill-worker.php`

## 6. 当前已知旧链路风险判断

- 现网站与 H5 仍以原登录链路为主。
- 企业微信配置当前未启用，数据库结构当前也未补齐。
- 本轮数据库变更前，旧链路应继续保持现状。

## 7. 现场执行时的对照要求

1. 先执行 `wecom-readonly-precheck-queries-2026-06-24.sql`。
2. 对照 `staffs_total`、`active_staff_total` 和活跃角色分布。
3. 对照 `staffs` 是否仍未出现企业微信字段。
4. 对照 `wecom_%` 表是否仍未创建。
5. 如存在差异，先记录在 `wecom-live-execution-record-template-2026-06-24.md`，再决定是否继续。

## 8. 2026-06-24 只读预检演练结果

- 执行方式：远程只读查询线上数据库。
- 执行结论：当前线上仍处于企业微信数据库结构未变更状态，符合执行前基线预期。
- `staffs_table = yes`
- `wecom_sync_logs_table = no`
- `wecom_message_logs_table = no`
- `staffs_wecom_columns = empty`
- `staffs_total = 56`
- `active_staff_total = 42`
- `active_staff_without_user_id = 0`
- `mini_reminder_rules = 4`
- `mini_reminder_jobs = 0`
- `mini_user_notifications = 0`
- `policy_notifications = 0`
- `WECOM_* = null`
