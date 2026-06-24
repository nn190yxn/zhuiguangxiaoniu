# 企业微信现场执行记录模板

日期：2026-06-24

## 1. 基本信息

- 执行日期：
- 执行时间段：
- 执行人：
- 复核人：
- 执行环境：
- 当前分支：`main`

## 2. 执行前确认

| 检查项 | 结果 | 备注 |
| --- | --- | --- |
| 现网站可正常访问 |  |  |
| H5 可正常访问 |  |  |
| 原微信小程序可正常登录 |  |  |
| 已完成变更前只读预检 |  |  |
| 已完成执行前备份 |  |  |
| 已准备回滚联系人和回滚窗口 |  |  |

## 3. 只读预检记录

| 阶段 | 执行时间 | 结果 | 备注 |
| --- | --- | --- | --- |
| 变更前预检 |  |  |  |
| 变更后预检 |  |  |  |
| 挂 worker 前预检 |  |  |  |

## 4. 备份记录

| 备份项 | 文件名或位置 | 结果 | 备注 |
| --- | --- | --- | --- |
| `staffs` 表结构备份 |  |  |  |
| `staffs` 表数据备份 |  |  |  |
| 提醒相关表结构备份 |  |  |  |
| 提醒相关表数据备份 |  |  |  |
| 当前 crontab 备份 |  |  |  |
| 当前配置状态记录 |  |  |  |

### 2026-06-24 备份结果

| 备份项 | 文件名或位置 | 结果 | 备注 |
| --- | --- | --- | --- |
| 备份目录 | `/www/wwwroot/122.51.223.46/data/wecom-backup-20260624-165128` | done | 目录大小 `64K` |
| `staffs` 表结构备份 | `staffs-schema.sql` | done | `29` 行 |
| `staffs` 表数据备份 | `staffs-data.sql` | done | `56` 行 |
| 提醒相关表结构备份 | `mini_reminder_*`、`mini_user_notifications`、`policy_notifications` schema 文件 | done | 已生成 |
| 提醒相关表数据备份 | `mini_reminder_*`、`mini_user_notifications`、`policy_notifications` data 文件 | done | 空表 data 为 `0` 行，与预检一致 |
| 当前 crontab 备份 | `crontab-before-wecom.txt` | done | `7` 行 |
| 当前配置状态记录 | `wecom-config-status.json` | done | `8` 行 |
| 备份清单 | `backup-manifest.json` | done | `10` 行 |

## 5. SQL 执行记录

| 步骤 | 执行时间 | 结果 | 备注 |
| --- | --- | --- | --- |
| 执行 `wecom-online-schema-2026-06-24.sql` |  |  |  |
| `DESCRIBE staffs` 复核 |  |  |  |
| `SHOW TABLES LIKE 'wecom_%'` 复核 |  |  |  |

### 2026-06-24 SQL 执行结果

| 步骤 | 执行时间 | 结果 | 备注 |
| --- | --- | --- | --- |
| 执行 `wecom-online-schema-2026-06-24.sql` | `16:52` | done | 返回 `schema-updated` |
| `staffs.wecom_*` 复核 | `16:52` | done | 字段已包含 `wecom_userid,wecom_name,wecom_mobile,wecom_department_id,wecom_department_path,wecom_status,wecom_bound_at` |
| `wecom_%` 表复核 | `16:52` | done | `wecom_sync_logs`、`wecom_message_logs` 已存在 |
| 旧链路计数复核 | `16:52` | done | `staffs_total = 56`，`active_staff_total = 42`，提醒和通知计数未漂移 |

## 6. 现网站与 H5 回归记录

| 检查项 | 结果 | 备注 |
| --- | --- | --- |
| H5 账号密码登录 |  |  |
| 原微信 `wxlogin` |  |  |
| 原微信 `wxbind` |  |  |
| 首页核心数据加载 |  |  |
| 学习中心 |  |  |
| 制度中心 |  |  |
| 通知列表 |  |  |
| 工作量日报 |  |  |
| 原提醒 worker |  |  |
| 员工后台 |  |  |
| 工作量后台 |  |  |
| 学习后台 |  |  |

### 2026-06-24 轻量回归结果

| 检查项 | 结果 | 备注 |
| --- | --- | --- |
| 首页可达性 | done | `HTTP 200` |
| 待办接口未登录响应 | done | 返回 `401 请先登录`，符合未登录预期 |
| 企业微信状态接口 | blocked | `/api/wecom/status.php` 返回 `404`，线上运行目录缺少 `api/wecom` 目录 |

### 2026-06-24 企业微信 API 部署结果

| 检查项 | 结果 | 备注 |
| --- | --- | --- |
| `api/wecom/` 部署 | done | 新增企业微信 API 目录 |
| `api/reminder/learning-required.php` 部署 | done | 新增学习提醒接口 |
| `api/config.php` 备份 | done | 备份到 `/www/wwwroot/122.51.223.46/data/wecom-backup-20260624-165128/config.php.before-wecom` |
| `api/config.php` 部署 | done | 新版配置文件语法通过 |
| `/api/wecom/status.php` 复核 | done | 返回 `HTTP 200`，`enabled = false`，配置项未补齐 |
| 首页可达性复核 | done | `HTTP 200` |
| 待办接口未登录响应复核 | done | 返回 `401 请先登录` |

### 2026-06-24 相关后端与后台文件部署结果

| 检查项 | 结果 | 备注 |
| --- | --- | --- |
| 线上原文件备份 | done | 备份到 `/www/wwwroot/122.51.223.46/data/wecom-backup-20260624-165128/code-before-wecom` |
| 登录接口部署 | done | `api/auth-jwt.php` 已部署并通过 `php -l` |
| 上下文接口部署 | done | `api/common/context.php` 已部署并通过 `php -l` |
| 制度通知接口部署 | done | `api/policy/notify.php` 已部署并通过 `php -l` |
| 提醒公共逻辑部署 | done | `api/reminder/_common.php` 已部署并通过 `php -l` |
| 提醒 worker 部署 | done | `api/reminder/reminder-worker.php` 已部署并通过 `php -l` |
| 待办接口部署 | done | `api/todos/my.php` 已部署并通过 `php -l` |
| 企业微信后台页部署 | done | `/admin/wecom.html` 返回 `HTTP 200` |
| 状态接口复核 | done | `/api/wecom/status.php` 返回 `HTTP 200`，配置仍未启用 |
| 待办接口复核 | done | 未登录返回 `401 请先登录` |

### 2026-06-24 小程序运行文件部署结果

| 检查项 | 结果 | 备注 |
| --- | --- | --- |
| 线上原小程序文件备份 | done | 备份到 `/www/wwwroot/122.51.223.46/data/wecom-backup-20260624-165128/mini-program-before-wecom` |
| `mini-program/app.js` 部署 | done | 文件存在，包含企业微信消息入口逻辑 |
| 登录页部署 | done | `pages/login/login.js`、`pages/login/login.wxml` 已部署 |
| 绑定关卡部署 | done | `pages/wechat-bind/gate.js`、`pages/wechat-bind/gate.wxml` 已部署 |
| 首页部署 | done | `pages/index/index.js`、`pages/index/index.wxml` 已部署 |
| 学习页部署 | done | `pages/learning/list.js`、`pages/learning/list.wxml` 已部署 |
| 通知页部署 | done | `pages/notifications/list.js`、`pages/notifications/list.wxml` 已部署 |
| 制度页部署 | done | `pages/policy/list.js`、`pages/policy/list.wxml` 已部署 |
| 工作量页部署 | done | `pages/workload/index.js`、`pages/workload/index.wxml` 已部署 |
| 文件存在性校验 | done | 所有目标文件均非空 |

### 2026-06-24 线上运行文件对齐核验结果

| 检查项 | 结果 | 备注 |
| --- | --- | --- |
| `api/wecom/*.php` checksum | done | 全部与本地 `real_sync/api/wecom/*.php` 一致 |
| 后端接口 checksum | done | `config.php`、`auth-jwt.php`、`context.php`、`policy/notify.php`、提醒、待办、员工导入接口一致 |
| 后台页面 checksum | done | `admin/wecom.html`、`admin/dashboard.html`、`admin/system-dashboard.html` 一致 |
| 小程序关键文件 checksum | done | 登录、首页、学习、通知、制度、工作量、绑定关卡和 `app.js` 一致 |

### 2026-06-24 企业微信禁用态安全回归结果

| 检查项 | 结果 | 备注 |
| --- | --- | --- |
| `/api/wecom/overview.php` 鉴权 | done | 未登录返回 `401 请先登录` |
| `/api/wecom/bindings.php` 鉴权 | done | 未登录返回 `401 请先登录` |
| `/api/wecom/sync-members.php` 鉴权 | done | 未授权返回 `401 请先登录` |
| `wecomlogin` 禁用态 | done | 返回业务码 `503` 和“企业微信登录配置未完成，请联系管理员处理” |

## 7. 配置与状态复核

| 检查项 | 结果 | 备注 |
| --- | --- | --- |
| `WECOM_ENABLED` |  |  |
| `WECOM_CORP_ID` |  |  |
| `WECOM_AGENT_ID` |  |  |
| `WECOM_APPID` |  |  |
| `WECOM_AGENT_SECRET` |  |  |
| `WECOM_MINI_PROGRAM_SECRET` |  |  |
| `WECOM_SYNC_ROOT_DEPARTMENT_ID` |  |  |
| `/api/wecom/status.php` 复核 |  |  |

## 8. Worker 与日志记录

| 检查项 | 结果 | 备注 |
| --- | --- | --- |
| 手动执行通讯录同步 worker |  |  |
| `wecom_sync_logs` 写入 |  |  |
| 手动执行提醒 worker |  |  |
| `wecom_message_logs` 写入 |  |  |

### 2026-06-24 Worker 禁用态验证结果

| 检查项 | 结果 | 备注 |
| --- | --- | --- |
| 通讯录同步 worker 禁用态 | done | 返回“企业微信配置未完成”，退出码 `1` |
| `wecom_sync_logs` 写入 | done | `0` 条，未产生误同步日志 |
| 提醒 worker 禁用态 | done | `learning_required` 生成 `42` 条站内提醒任务 |
| `mini_user_notifications` 写入 | done | `42` 条 |
| 企业微信消息通道 | done | `channel_wechat_status = skipped`，说明为“企业微信消息未启用” |
| `wecom_message_logs` 写入 | done | `0` 条，未产生误发消息日志 |

## 9. 企业微信联调记录

| 检查项 | 结果 | 备注 |
| --- | --- | --- |
| `wecomlogin` |  |  |
| `wecombind` |  |  |
| 停用员工拦截 |  |  |
| 工作量提醒送达 |  |  |
| 制度提醒送达 |  |  |
| 学习提醒送达 |  |  |
| 工作量跳转 |  |  |
| 通知跳转 |  |  |
| 制度跳转 |  |  |
| 学习跳转 |  |  |

## 10. 异常与止损记录

| 时间 | 异常现象 | 止损动作 | 当前结果 |
| --- | --- | --- | --- |
|  |  |  |  |

## 11. 最终结论

- 是否允许继续灰度：
- 是否允许挂正式 cron：
- 是否需要回滚：
- 执行结论：

## 12. 回填位置

- `wecom-gap-tracking-2026-06-24.md`
- `wecom-mini-program-master-plan-2026-06-23.md`
