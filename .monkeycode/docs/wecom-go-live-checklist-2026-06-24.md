# 企业微信上线执行清单

日期：2026-06-24

## 1. 目标

- 在现有网站内网主库上补齐企业微信身份层、同步日志层、消息日志层。
- 完成企业微信配置、同步 worker、提醒 worker 和真机联调前置准备。

## 2. 执行顺序

1. 先执行只读预检，确认现网基线。
2. 备份线上 `staffs` 表结构和数据。
3. 执行 `wecom-online-schema-2026-06-24.sql`。
4. 复核字段和表是否创建成功，并再次执行只读预检。
5. 补齐 `WECOM_*` 配置。
6. 复核 `/api/wecom/status.php`。
7. 手动执行一次通讯录同步 worker。
8. 复核 `wecom_sync_logs` 是否开始写入。
9. 手动执行提醒链路，复核 `wecom_message_logs` 是否开始写入。
10. 再进入 `wecomlogin` / `wecombind` 真机联调。
11. 最后进入消息送达与消息跳转真机联调。

## 3. 数据库执行

SQL 文件：`/.monkeycode/docs/wecom-online-schema-2026-06-24.sql`

执行后复核：

- `DESCRIBE staffs`
- `SHOW TABLES LIKE 'wecom_%'`

预期结果：

- `staffs` 已包含：
  - `wecom_userid`
  - `wecom_name`
  - `wecom_mobile`
  - `wecom_department_id`
  - `wecom_department_path`
  - `wecom_status`
  - `wecom_bound_at`
- 数据库已包含：
  - `wecom_sync_logs`
  - `wecom_message_logs`

## 4. 配置项

需要补齐：

- `WECOM_ENABLED`
- `WECOM_CORP_ID`
- `WECOM_AGENT_ID`
- `WECOM_APPID`
- `WECOM_AGENT_SECRET`
- `WECOM_MINI_PROGRAM_SECRET`
- `WECOM_SYNC_ROOT_DEPARTMENT_ID`

配置完成后复核入口：

- `GET /api/wecom/status.php`

预期结果：

- `enabled = true`
- `corp_id_configured = true`
- `agent_id_configured = true`
- `app_id_configured = true`
- `agent_secret_configured = true`
- `mini_program_secret_configured = true`

## 5. Worker 命令

### 通讯录同步

```bash
php /www/wwwroot/122.51.223.46/api/wecom/sync-worker.php
```

### 提醒 worker

```bash
php /www/wwwroot/122.51.223.46/api/reminder/reminder-worker.php
```

## 6. 联调入口

- `POST /api/auth-jwt.php?action=wecomlogin`
- `POST /api/auth-jwt.php?action=wecombind`

真机联调顺序：

1. 已绑定员工企业微信登录。
2. 未绑定员工首次绑定。
3. 停用员工拦截。
4. 工作量提醒送达。
5. 制度提醒送达。
6. 学习提醒送达。
7. 工作量、通知、制度、学习消息跳转。

## 7. 风险控制

- 企业微信改造继续复用网站内网主库，当前不新建独立企业微信业务库。
- 本轮数据库改造只增字段、只增表。
- 所有数据库变更都以现有网站和 H5 正常使用为门禁，先复核旧链路，再继续企业微信联调。
- 异常时优先关闭企业微信开关，保留账号密码登录链路。
- 同步异常时优先停通讯录同步 worker。
- 消息异常时优先停提醒 worker，保留站内提醒链路。

## 8. 回填要求

- 每完成一项，立即回填 `wecom-gap-tracking-2026-06-24.md`。
- 每完成一个阶段，立即回填 `wecom-mini-program-master-plan-2026-06-23.md` 的当前状态。

## 9. 现网站与 H5 复核门禁

执行数据库变更后，先复核以下旧链路，再进入企业微信联调：

1. H5 账号密码登录。
2. 原微信小程序登录与绑定。
3. 首页核心数据加载。
4. 学习中心、制度中心、通知、工作量日报接口返回。
5. 现有提醒链路和站内通知写入。

只有上述链路保持正常，才进入企业微信同步、企业微信登录和企业微信消息联调。

详细检查表：`./wecom-existing-site-regression-checklist-2026-06-24.md`

## 10. 备份与回滚

详细清单：`./wecom-backup-and-rollback-checklist-2026-06-24.md`

## 11. 执行命令模板

详细清单：`./wecom-execution-command-template-2026-06-24.md`

## 12. 现场执行记录模板

详细清单：`./wecom-live-execution-record-template-2026-06-24.md`

## 13. 只读预检

详细清单：`./wecom-preflight-checklist-2026-06-24.md`

基线样例：`./wecom-preflight-baseline-snapshot-2026-06-24.md`
