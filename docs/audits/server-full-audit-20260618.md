# 追光小牛服务器完整审计报告

审计时间：2026-06-18

审计基线：`122.51.223.46:/www/wwwroot/122.51.223.46/`

审计方式：只读审计线上真实运行目录，不写入服务器

## 1. 审计结论

当前线上项目是一个长期演进形成的混合系统，不是单一应用。

系统由以下几层共同组成：

1. WordPress 站点与文章模板层
2. 自定义 PHP API 层
3. 员工 H5 内网页面层
4. 总部后台页面层
5. 微信小程序层
6. 制度文档与站内阅读层
7. AI 分析与录音复盘工具层
8. 大量运行期备份、测试脚本和历史副本

接下来任何功能优化，都必须按“真实运行入口 -> 认证链 -> API -> 数据表 -> H5/后台/小程序三端一致性 -> 备份回滚点”这一条链路推进。

## 2. 运行基线与目录结构

线上 Web 根目录：`/www/wwwroot/122.51.223.46/`

核心运行目录和入口如下：

- `api/`：自定义业务接口主目录
- `mobile/`：员工 H5 页面，同时混入一套小程序样式目录树
- `mini-program/`：正式微信小程序工程
- `admin -> _admin_internal/`：后台入口是软链接，真实文件在 `_admin_internal/`
- `js/`：Web 端认证与请求公共脚本
- `docs/`：制度/知识类文档源数据
- `logs/`：API 日志输出目录
- `database/`：数据导入和结构调整脚本
- `scripts/`：审计、测试、核查、批处理脚本
- `backup-*`：多轮线上改动前的备份目录
- `backups/`、`_codex_backups/`：额外备份目录
- `site_current/`：历史副本
- `mini-program-backup-*`：小程序历史副本

根目录还混有大量直接运行页面：

- `internal.html`：员工内网首页
- `doc-viewer.html`：制度与知识站内阅读器
- `search.html`：全局搜索页
- `summer-camp-assessment.html` / `summer-camp-assessment-app.html` / `summer-camp-history.html`
- `skill-review.html`
- `周年庆数据看板-V5.html`
- `ai-analyzer.html` / `ai-drill.html`

## 3. 认证与权限主链

### 3.1 服务端配置与身份来源

核心文件：`api/config.php`

关键结论：

- 数据库与 JWT 密钥优先从环境变量读取。
- PHP-FPM 场景下，支持从 `api/.env.local.php` 回退读取配置。
- 当前用户身份来源有两层：
  - `Authorization: Bearer <jwt>`
  - `$_SESSION['wp_user_id']`
- 业务系统用户与 WordPress 用户表 `wp_users`、员工表 `staffs` 共同构成登录链。

### 3.2 统一权限上下文

核心文件：`api/common/context.php`

该文件承担：

- 角色标准化映射
- 总部角色与门店角色判断
- 店长/总部/本人数据权限边界
- 统一返回员工上下文 `appGetCurrentStaffContext()`
- 统一登录拦截 `appRequireStaffContext()`

当前系统统一角色口径包括：

- `sales`
- `coach`
- `manager`
- `operation`
- `finance`
- `admin`
- `ceo`

### 3.3 登录与 JWT

核心文件：

- `api/auth-jwt.php`
- `api/auth/me.php`

当前支持：

- 密码登录
- 微信 `wxlogin`
- 微信 `wxbind`
- JWT 验证与刷新
- 首次登录默认密码变更提醒

`api/auth/me.php` 是 Web 与 H5 的当前用户事实接口，返回：

- 角色
- 是否总部
- 是否管理员
- 是否店长
- 权限集合
- 员工资料
- 是否需要强制改密

### 3.4 前端统一鉴权

核心文件：

- `internal-auth.js`
- `js/app-auth.js`

关键结论：

- `internal-auth.js` 负责页面级守卫、统一导航、未登录提示和 `/api/auth/me.php` 拉取。
- `js/app-auth.js` 负责 token 读取、过期判断、统一 `Authorization` 注入。
- 员工端多个页面直接依赖这两层脚本。

### 3.5 小程序统一鉴权

核心文件：

- `mini-program/app.js`
- `mini-program/utils/api.js`

关键结论：

- 小程序 API 基址统一为 `https://supercalf.com/api`
- token 过期时自动跳转登录页
- 登录后会上报设备信息到 `statistics/device.php`

## 4. 页面与业务模块地图

### 4.1 员工 H5 主链

主要入口：

- `internal.html`：内网首页
- `mobile/login.html`：手机号登录
- `mobile/mine.html`：个人中心
- `mobile/learning.html`：学习中心
- `mobile/knowledge.html`：知识库
- `mobile/policy-search.html`：制度中心
- `mobile/workload.html` / `mobile/workload-v2.html`：工作量填报

### 4.2 总部后台主链

真实文件目录：`_admin_internal/`

主要页面：

- `dashboard.html`
- `learning.html`
- `operation-logs.html`
- `security-devices.html`
- `security-login-audit.html`
- `staffs.html`
- `system-dashboard.html`
- `system-errors.html`
- `workload.html`

说明：`/admin/*` 入口通过软链接映射到 `_admin_internal/`。

### 4.3 微信小程序正式工程

正式工程目录：`mini-program/`

`app.json` 当前注册页面覆盖：

- 首页
- 登录
- 协议页
- 制度中心
- 学习中心
- 考试
- 知识库
- 演练
- 通关
- 通知
- 积分
- 问卷
- 工作量
- 暑假班评估中心
- 录音复盘
- 我的

### 4.4 主要业务域

#### 工作量系统

前端入口：

- `mobile/workload-v2.html`
- `mobile/workload.html`
- `_admin_internal/workload.html`
- `mini-program/pages/workload/*`

后端入口：`api/workload/*`

关键结论：

- `api/workload/_common.php` 在运行时负责建表、补列、种子初始化、规则初始化。
- 当前主岗位是 `sales` 与 `coach`。
- 审核、凭证、日报、模板、驾驶舱、门店汇总、员工明细都在同一模块内。
- 需凭证指标统一收口为最多 10 张图。
- `save-report.php` 明确限制未来日期、非本人岗位、非本人门店和非当天提交。

#### 制度中心

前端入口：

- `制度标准/index.html`
- `mobile/policy-search.html`
- `mobile/policy-detail.html`
- `doc-viewer.html`

后端入口：

- `api/policy/search.php`
- `api/policy/detail.php`
- `api/doc-content.php`

关键结论：

- 制度检索与制度正文阅读已经形成“搜索 -> doc_key -> doc-viewer”链路。
- H5 页面与站内阅读器并行存在。

#### 全局搜索

前端入口：`search.html`

后端入口：

- `api/search/global.php`
- `api/search/search-service.php`

搜索覆盖：

- 知识
- 制度
- 课程
- 员工
- 演练
- 培训
- 考试
- 话术

#### 暑假班评估

前端入口：

- `summer-camp-assessment.html`
- `summer-camp-assessment-app.html`
- `summer-camp-history.html`

后端入口：`api/summer-camp/assessment-api.php`

关键结论：

- 一个接口文件承担保存评估、保存报告、取列表、取详情四类动作。
- 操作主体当前按“登录教练本人”约束。

#### 录音复盘与 AI

前端入口：

- `skill-review.html`
- `ai-analyzer.html`
- `ai-drill.html`

后端入口：

- `api/skill/upload-recording.php`
- `api/skill/review-list.php`
- `api/ai-services.php`
- `api/ai-runtime.php`

关键结论：

- `upload-recording.php` 负责录音上传和异步分析任务落库。
- `api/ai-runtime.php` 是 AI 配置和外部模型调用底层。

#### 周年庆经营看板

前端入口：`周年庆数据看板-V5.html`

后端入口：

- `api/campaign/summary.php`
- `api/campaign/save.php`

关键结论：

- 当前权限判断已经部分接入 `context.php`，但仍保留较重的自定义角色拼装逻辑。

#### 培训 / 演练 / 通关 / 学习 / 知识

对应后端子模块：

- `api/drill/`
- `api/pass/`
- `api/learning/`
- `api/knowledge/`
- `api/exam/`
- `api/points/`
- `api/survey/`

这些模块共同构成员工成长与培训体系。

## 5. 数据层与运行脚本

### 5.1 database 目录

当前包含 5 个数据导入或批量调整脚本：

- `adjust_training_cards.php`
- `import_feedback_dimension.php`
- `import_sales_seven_steps.php`
- `import_sales_ten_questions.php`
- `update_brand_manual.php`

### 5.2 scripts 目录

当前存在大量一次性或半长期脚本，类型包括：

- 审计脚本
- 工作量专项测试脚本
- 登录与权限核查脚本
- 风险扫描脚本
- 员工导入脚本
- 密码重置脚本
- 活动看板验证脚本

说明：

- `scripts/` 目录更像“线上问题处理工具箱”，不是稳定产品代码。
- 这里有高风险运维脚本，例如 `reset_staff_passwords.php`、`backup_database_from_config.php`。

## 6. 备份与历史副本现状

线上根目录存在大量按功能命名的备份目录，最近集中在 2026-06-09 到 2026-06-12：

- `backup-workload-*`
- `backup-policy-center-*`
- `backup-doc-*`
- `backup-auth-*`
- `backup-summer-camp-*`
- `backup-search-optimization-*`
- `backup-mini-presubmit-*`

此外还存在：

- `mini-program-backup-20260504_fix`
- `mini-program-backup-20260605-skill`
- `site_current/`
- `_codex_backups/`
- 各类 `.bak-*` 文件

这说明当前线上长期采用“先备份目录，再热修文件”的变更方式。

## 7. 实际运行痕迹

当日日志 `logs/api-2026-06-18.log` 已确认有真实访问流量，当前活跃度最高的是工作量模块：

- `workload.template`
- `workload.dashboard`
- `workload.store_summary`
- `workload.staff_detail`

说明：

- 工作量系统当前处于真实使用中。
- 后续优化工作量相关功能时必须优先按生产活跃链路处理。

## 8. 当前审计发现的结构性风险

### 8.1 运行目录混合度高

根目录同时承载：

- WordPress
- 自定义业务页
- H5
- 后台
- 小程序源码
- 导入脚本
- 调试脚本
- 备份目录

优化前必须先明确每次改动对应的是哪一层。

### 8.2 后台入口是软链接

`/admin` 指向 `_admin_internal/`。

涉及后台页面优化时，必须确认改的是软链接目标目录，避免改错路径。

### 8.3 存在两套小程序形态

- `mini-program/`：正式工程
- `mobile/pages/`：一套近似小程序结构的重复树

任何小程序相关优化都必须先确认目标是“正式微信小程序”还是“mobile 内历史兼容树”。

### 8.4 部分模块技术风格不统一

已观察到：

- `PDO` 与 `mysqli` 混用
- 新模块大量走 `context.php`，旧模块仍使用早期风格
- 部分接口通过共享 helper 输出，部分接口直接 `echo json_encode`

优化时要避免在同一链路继续扩大风格分裂。

### 8.5 运行时建表与规则初始化耦合在请求链路

`api/workload/_common.php` 会在真实请求里执行建表、补列、种子和规则初始化。

这让工作量模块具备“自恢复”能力，也让每次优化都要考虑结构迁移副作用。

### 8.6 AI 运行时对配置源依赖较重

`api/ai-runtime.php` 会从：

- 环境变量
- `config.php`
- `.env.local.php`
- `ai_settings` 数据表
- `ai-config.php`

多层读取配置。

AI 功能优化前，必须先确认实际生效配置来源。

### 8.7 线上保留大量测试/核查/密码类脚本

这些文件有运维价值，也增加了误触、误读和误发布风险。

后续仓库与服务器治理时，应考虑分层隔离。

## 9. 优化前的强制前置检查

后续任何服务器功能优化，建议固定按下面顺序执行：

1. 明确目标入口页面和真实文件路径
2. 确认该页面依赖的认证链和公共脚本
3. 找到对应 API 与数据表
4. 确认 H5、后台、小程序是否共用同一接口
5. 在服务器上建立单独备份目录
6. 修改后做语法检查、接口验证、页面回归、日志回看

## 10. 当前可直接作为优化基线的重点文件

### 认证与权限

- `api/config.php`
- `api/auth-jwt.php`
- `api/auth/me.php`
- `api/common/context.php`
- `api/common/helpers.php`
- `internal-auth.js`
- `js/app-auth.js`

### 工作量

- `api/workload/_common.php`
- `api/workload/save-report.php`
- `api/workload/dashboard.php`
- `api/workload/staff-detail.php`
- `mobile/workload-v2.html`
- `_admin_internal/workload.html`
- `mini-program/pages/workload/*`

### 制度 / 搜索 / 文档

- `api/policy/search.php`
- `api/policy/detail.php`
- `api/search/global.php`
- `api/search/search-service.php`
- `doc-viewer.html`
- `制度标准/index.html`
- `search.html`

### 暑假班 / AI / 复盘

- `api/summer-camp/assessment-api.php`
- `summer-camp-assessment.html`
- `summer-camp-assessment-app.html`
- `summer-camp-history.html`
- `api/skill/upload-recording.php`
- `api/ai-runtime.php`
- `skill-review.html`

### 后台 / 活动看板

- `_admin_internal/dashboard.html`
- `_admin_internal/learning.html`
- `_admin_internal/security-login-audit.html`
- `_admin_internal/workload.html`
- `api/admin/common.php`
- `api/campaign/summary.php`
- `api/campaign/save.php`
- `周年庆数据看板-V5.html`

## 11. 当前审计结论对应的下一步

当前我已经完成了“服务器真实运行态完整地图”的第一轮结构化审计，已经足够支持后续功能优化进入“按模块做精确方案”的阶段。

后续进入具体优化时，优先顺序建议为：

1. 先指定目标模块
2. 再补该模块的深度链路审计
3. 输出完整修改计划
4. 执行小批次、可回滚优化
