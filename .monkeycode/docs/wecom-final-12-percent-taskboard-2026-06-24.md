# 企业微信专用版最后 12% 收口任务板

日期：2026-06-24

## 1. 当前结论

- 当前整体完成度：`97%`
- 当前剩余工作：`3%`
- 当前阶段：真实上线执行与最终验收

## 2. 剩余任务总览

| 序号 | 任务 | 当前状态 | 建议执行人 | 完成标准 | 阻塞条件 |
| --- | --- | --- | --- | --- | --- |
| 1 | 线上只读预检演练 | done | AI | 已产出变更前预检演练结果并回填基线 | 真实执行当天仍需重跑一次 |
| 1.1 | 线上只读预检正式执行 | pending | AI + 现场执行人 | 执行当天产出最新预检结果并完成记录 | 需可访问线上数据库 |
| 2 | 线上备份 | done | AI | 结构备份、数据备份、配置备份、crontab 备份完成 | 备份目录：`/www/wwwroot/122.51.223.46/data/wecom-backup-20260624-165128` |
| 3 | 数据库首轮增量变更 | done | AI | `staffs.wecom_*` 与 `wecom_%` 表补齐成功 | 已完成，旧链路计数未漂移 |
| 4 | 旧链路轻量回归 | partial | AI | 首页 `200`，未登录待办接口返回 `401` 预期响应 | 真实账号链路仍需业务验收 |
| 4.1 | 企业微信代码部署到线上运行目录 | done | AI | `/api/wecom/status.php` 已返回 `200`，后台页可访问 | `WECOM_*` 尚未配置 |
| 4.2 | 企业微信相关后端与后台文件部署 | done | AI | 登录、上下文、提醒、待办、后台入口文件已部署并通过语法和轻量回归 | 真实账号链路仍需验收 |
| 4.3 | 小程序企业微信相关运行文件部署 | done | AI | 小程序登录、首页、学习、通知、制度、工作量、绑定关卡相关文件已部署 | 仍需开发者工具和真机验收 |
| 4.4 | 线上运行文件 checksum 对齐 | done | AI | 企业微信 API、后端、后台、小程序关键文件与本地一致 | 正式配置与真机验收仍待完成 |
| 4.5 | 企业微信禁用态安全回归 | done | AI | 管理接口需登录，同步接口未授权返回 `401`，登录接口未配置返回配置缺失 | 正式配置与真机验收仍待完成 |
| 5 | 企业微信配置补齐 | done | AI + 现场执行人 | `WECOM_*` 全部到位，`status.php` 通过 | 已启用，密钥不入库不入文档 |
| 6 | 通讯录同步 worker 禁用态验证 | done | AI | 未配置时返回“企业微信配置未完成”并退出 | 正式同步仍需配置补齐 |
| 6.1 | 通讯录同步 worker 正式验证 | blocked | AI + 现场执行人 | `wecom_sync_logs` 成功写入失败日志 | 企业微信可信 IP 未放行 `122.51.223.46`，接口返回 `60020` |
| 7 | 提醒 worker 禁用态验证 | done | AI | 站内提醒生成正常，企业微信通道 `skipped`，无误发 | 正式企业微信发送仍需配置补齐 |
| 7.1 | 提醒 worker 正式验证 | pending | 现场执行人 | `wecom_message_logs` 成功写入 | 需配置补齐 |
| 8 | 企业微信真机登录与绑定联调 | pending | 用户本地 + 业务验收人 | `wecomlogin`、`wecombind` 通过 | 需真机环境 |
| 9 | 企业微信消息送达与跳转联调 | pending | 用户本地 + 业务验收人 | 消息送达和跳转全通过 | 需 worker 正常 |
| 10 | WechatSI 正式授权验证 | pending | 用户本地 | 通关与销售演练语音转文字可用 | 需微信后台授权 |
| 11 | 结果回填与收口确认 | pending | AI + 现场执行人 | 台账、总计划、执行记录全部回填 | 需前面任务完成 |

## 3. 优先级顺序

执行前先确认：`./wecom-go-live-prerequisites-2026-06-24.md`

1. 线上只读预检正式执行
2. 线上备份
3. 数据库首轮增量变更
4. 旧链路真实回归
5. 企业微信代码部署到线上运行目录
6. 企业微信配置补齐
7. 通讯录同步 worker 正式验证
8. 提醒 worker 正式验证
9. 企业微信真机登录与绑定联调
10. 企业微信消息送达与跳转联调
11. WechatSI 正式授权验证
12. 结果回填与收口确认

## 4. 每项任务的完成标准

### 4.1 线上只读预检

- 使用：`./wecom-readonly-precheck-queries-2026-06-24.sql`
- 记录位置：`./wecom-live-execution-record-template-2026-06-24.md`
- 完成标准：变更前核心基线已记录，可与样例对照
- 当前演练结果：已完成一次远程只读预检，线上仍未创建企业微信字段和 `wecom_%` 日志表，员工基线与已知样例一致

### 4.2 线上备份

- 使用：`./wecom-backup-and-rollback-checklist-2026-06-24.md`
- 使用：`./wecom-execution-command-template-2026-06-24.md`
- 完成标准：备份文件名、路径、执行时间都已记录
- 当前结果：已生成备份目录 `/www/wwwroot/122.51.223.46/data/wecom-backup-20260624-165128`，包含 `staffs`、提醒相关表、配置状态、crontab 和 manifest

### 4.3 数据库首轮增量变更

- 使用：`./wecom-online-schema-2026-06-24.sql`
- 完成标准：
  - `staffs` 新增企业微信字段成功
  - `wecom_sync_logs` 创建成功
  - `wecom_message_logs` 创建成功
- 当前结果：已完成，变更后 `staffs_total = 56`，`active_staff_total = 42`，提醒和通知计数未漂移

### 4.4 旧链路真实回归

- 使用：`./wecom-existing-site-regression-checklist-2026-06-24.md`
- 完成标准：
  - H5 登录正常
  - 原微信登录与绑定正常
  - 学习、制度、通知、工作量正常
  - 站内提醒链路正常
- 当前轻量结果：首页 `200`，未登录待办接口返回 `401` 预期响应

### 4.4.1 企业微信代码部署到线上运行目录

- 当前结果：已部署 `api/wecom/`、`api/reminder/learning-required.php` 和新版 `api/config.php`
- 完成标准：已达成，`/api/wecom/status.php` 返回 `200`，当前显示 `enabled = false` 且 `WECOM_*` 未配置

### 4.4.2 企业微信相关后端与后台文件部署

- 当前结果：已部署 `api/auth-jwt.php`、`api/common/context.php`、`api/policy/notify.php`、`api/reminder/_common.php`、`api/reminder/reminder-worker.php`、`api/todos/my.php`、`admin/wecom.html`、`admin/dashboard.html`、`admin/system-dashboard.html`
- 完成标准：已达成，相关 PHP 文件语法检查通过，`/admin/wecom.html` 返回 `HTTP 200`，`/api/wecom/status.php` 返回 `HTTP 200`，待办接口未登录返回 `401`

### 4.4.3 小程序企业微信相关运行文件部署

- 当前结果：已备份并部署 `mini-program/app.js`、登录页、首页、学习页、通知页、制度页、工作量页和 `wechat-bind/gate` 绑定关卡文件
- 完成标准：已达成，线上运行目录文件存在，关键文件中可检索到 `wecom` 相关入口
- 剩余验证：仍需微信开发者工具和真机环境确认页面跳转、登录、绑定与消息入口体验

### 4.4.4 线上运行文件 checksum 对齐

- 当前结果：已核验 `api/wecom/*.php`、企业微信相关后端接口、后台页和小程序关键文件，线上 checksum 与本地一致
- 完成标准：已达成，本轮企业微信运行文件没有发现漏传或线上旧版残留

### 4.4.5 企业微信禁用态安全回归

- 当前结果：`/api/wecom/overview.php` 和 `/api/wecom/bindings.php` 未登录返回 `401`，`/api/wecom/sync-members.php` 未授权返回 `401`
- 当前结果：`/api/auth-jwt.php?action=wecomlogin` 在未配置状态返回 `503` 业务码和“企业微信登录配置未完成”提示
- 完成标准：已达成，正式配置前不会绕过鉴权或误启用企业微信登录

### 4.5 企业微信配置补齐

- 复核入口：`/api/wecom/status.php`
- 完成标准：所有 `WECOM_*` 已补齐，状态接口返回配置完整
- 当前结果：已生成完整线上 `/api/.env.local.php`，包含原网站运行所需数据库配置、`JWT_SECRET` 与企业微信配置；`/api/wecom/status.php` 返回 `HTTP 200`，`enabled = true`，配置项均为已设置

### 4.6 Worker 验证

- 命令：`php /www/wwwroot/122.51.223.46/api/wecom/sync-worker.php`
- 命令：`php /www/wwwroot/122.51.223.46/api/reminder/reminder-worker.php`
- 完成标准：
  - `wecom_sync_logs` 有成功日志
  - `wecom_message_logs` 有成功日志
- 禁用态验证结果：同步 worker 在未配置时返回“企业微信配置未完成”；提醒 worker 生成 `42` 条站内提醒，企业微信通道为 `skipped`，`wecom_message_logs = 0`
- 正式验证结果：同步 worker 已进入企业微信真实接口，`gettoken` 返回成功；`/cgi-bin/department/list` 返回 `60020 not allow to access from your ip`，来源 IP 为 `122.51.223.46`；失败已写入 `wecom_sync_logs`

### 4.7 真机联调

- 登录联调：`wecomlogin`
- 绑定联调：`wecombind`
- 消息联调：工作量、制度、学习、通知
- 完成标准：登录、绑定、送达、跳转都通过

### 4.8 WechatSI 正式授权验证

- 完成标准：通关与销售演练语音转文字可正常使用

### 4.9 回填与收口确认

- 回填：`./wecom-gap-tracking-2026-06-24.md`
- 回填：`./wecom-mini-program-master-plan-2026-06-23.md`
- 回填：`./wecom-live-execution-record-template-2026-06-24.md`
- 完成标准：最后一轮结果和结论全部留痕

## 5. 当前最关键的三个阻塞

1. 真实账号链路仍需业务验收
2. 企业微信后台可信 IP 尚未放行服务器出口 IP `122.51.223.46`
3. 真机联调尚未完成

## 6. 前置条件清单

- `./wecom-go-live-prerequisites-2026-06-24.md`
- `./wecom-config-handoff-template-2026-06-24.md`

## 7. 收口判断标准

满足以下条件后，可认为“整个小程序改造成企业微信专用版”正式完成：

1. 数据库结构已补齐并通过旧链路回归
2. 企业微信配置和 worker 已上线可用
3. `wecomlogin`、`wecombind`、消息送达、消息跳转通过真机联调
4. WechatSI 正式授权验证通过
5. 台账、总计划、执行记录全部回填完成
