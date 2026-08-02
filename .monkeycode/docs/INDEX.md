# 追光小牛企业内网项目文档

本目录记录追光小牛企业内网的系统架构、接口约定、开发流程、工作量治理、企业微信和历史修复资料。代码主目录为 `real_sync/`，线上业务基线位于 `/www/wwwroot/122.51.223.46/`。

## 核心文档

| 文档 | 内容 |
| --- | --- |
| [ARCHITECTURE.md](./ARCHITECTURE.md) | 系统边界、技术栈、身份权限、核心子系统和员工组织数据模型 |
| [INTERFACES.md](./INTERFACES.md) | HTTP 约定、认证、API 目录、员工管理和数据库接口 |
| [DEVELOPER_GUIDE.md](./DEVELOPER_GUIDE.md) | 环境要求、开发工作流、迁移、测试、小程序和部署规范 |

## 当前专题

全站多端架构升级专题位于：

```text
.monkeycode/specs/2026-07-31-full-site-multi-client-architecture-upgrade/
```

该专题通过 `real_sync/scripts/platform_inventory.mjs` 将功能矩阵的 89 个组级功能 ID 与页面、API、Worker、Cron、迁移、PWA、小程序、文件和 AI 代码资产关联。统一 API Kernel 位于 `real_sync/api/kernel/`，首批核心类型提供零配置依赖的请求上下文、统一响应和业务异常契约。认证公共层已完成 PWA 刷新安全、多标签协调和小程序短期设备会话；多端同步公共层已提供 A/B/C 等级、ETag、签名增量游标、墓碑、服务端草稿和权威状态 409 恢复数据。PWA 已将 Manifest、登录恢复和浏览器兼容入口统一收口到 `/mobile/`，受控启动器只接受同源白名单页面，工作量、演练、学习和我的共享手机、平板、桌面三档应用壳，并支持横屏低高度回流与浏览器 200% 缩放；核心交互使用原生控件或 ARIA 模式，自定义对话框统一提供初始焦点、Tab 循环、Escape 关闭和焦点恢复。统一 ApiClient 提供认证刷新协调、超时、请求 ID、错误分类、幂等键、状态版本、ETag、增量游标和权威状态冲突恢复；DraftStore 按用户、员工、业务域、对象和 schema 版本隔离本地草稿，并在网络或会话恢复时核对服务端版本；工作量 H5 对跨设备差异执行显式版本选择；Service Worker v5 使用显式公共路径白名单、专用离线壳、waiting 更新确认、旧缓存清理和更新后会话恢复。会话、同步与 PWA 契约测试覆盖刷新轮换、令牌复用、账号状态失效、并发 401、跨标签协调、受控草稿、设备会话、三档断点、缩放能力、键盘焦点、幂等属性 1 和多端状态属性 4。数据库治理已建立 42 个迁移的独立 catalog、固定 checksum、数据核对分类、API 轻量 readiness 和部署前 CLI 门禁；历史入口治理通过 29 条冻结 consumer 基线、幂等调用收据、聚合计数、具名权限、双人审批、观察窗零调用、替代入口健康、回滚计划和完整证据控制 `deprecated` 状态，实际收缩留给独立批准批次。任务公共层已提供事务内幂等入队、规范载荷摘要、单一 dispatcher、Handler registry、Worker ID、租约、心跳、fencing token、有限指数退避、dead-letter、逐次运行摘要、transactional outbox、幂等副作用回执、人工重放和独立补偿状态。固定种子任务恢复测试覆盖租约竞争、进程中断、最新 fencing token 提交权、重复副作用、指数退避、dead-letter 和人工重放，验证正确性属性 9、10。提醒、企微、技能复盘、演练音频、工作量导出和预警及招聘简历处理已通过 Adapter 接入共享队列。身份审计、企微投递、提醒投递、技能复盘、周年活动、暑期评估、工作量预警运行日志和招聘录用转换结构已迁入版本化增量 SQL，请求与 Worker 路径只执行结构就绪检查。expand-migrate-contract 验证器阻断字段删除与重命名，并校验新增字段保留语义、N/N-1 写入适配器、状态降级映射和功能开关。迁移重放工具基于有界变更日志、outbox 和副作用回执生成 `dry-run`、`verify` 与保留式 `rollback-plan` 证据，全程保持只读。分层健康端点 `api/platform/health.php` 已提供 live、ready、dependencies 和任务最老积压秒数检查，并通过 Kernel 返回脱敏状态；`scripts/platform_sli.mjs` 对四条 Tier-1 旅程执行每分钟双探测并按自然月聚合 99.9% 可用率，`scripts/platform_release_gate.mjs` 执行 15/30/60 分钟发布观察和七类停止阈值判定，固定种子属性测试覆盖样本、边界、计划维护和决策确定性。小程序兼容历史 Bearer Token，能力端点 `1.3.0` 同时发布设备会话、同步协议和小程序功能最低版本支持。

小程序请求与上传现已收口到 `real_sync/mini-program/utils/api.js`，统一请求 ID、15/60 秒超时、错误分类、设备会话刷新队列、幂等键、状态版本、上传 SHA-256、进度和失败恢复。导航工具统一 Tab 与普通页语义，提醒授权支持稍后设置，能力白名单按最低客户端版本控制首页入口。小程序现登记 32 个页面；机器可读矩阵覆盖首页、认证、档案、积分、排行、商城、打卡、知识、证书和反馈十域，统一视图状态覆盖读取、提交、成功、离线、冲突及恢复动作。积分聚合页接入积分概览、服务端权威排行、事务兑换和每日签到，离线写入重试复用稳定幂等键。`real_sync/scripts/check_miniprogram_contracts.mjs` 聚合七类基础静态契约，十域矩阵、视图状态和积分 API 另有定向契约测试，并通过 `mini_program_contracts` 接入平台预检；小程序相关回归 45/45、全量 Node 自动测试 970/970 通过。

平台文件公共层现已建立四类资产策略、统一元数据、范围 ACL、生命周期与访问审计契约。`PlatformPrivateFileStorage` 增加实际 MIME、大小与摘要验证、Web 根目录外随机存储键、规范化路径边界、多对象流式下载和幂等留存清理；`DrillMediaStorageAdapter` 已将演练音频分片迁入私有存储，下载采用二次鉴权 URL 并记录允许或拒绝审计，到期治理执行物理清理。工作量新导出通过 `WorkloadPlatformFileAdapter` 使用临时导出策略写入平台私有根目录。招聘新简历通过 `RecruitmentPlatformFileAdapter` 登记为 `sensitive_source` 私有资产，原件预览执行领域权限与平台资产二次鉴权；历史 `storage_key` 保留受控兼容。文件契约矩阵覆盖 MIME 伪装、绝对路径、父目录、反斜杠、重复分隔符、点段、控制字符、缺失对象、符号链接越界、`0700/0600` 权限、下载到期、精确留存边界和重复清理。

统一 AI 公共层现已提供五类生产能力、版本化请求与结果、可注入供应商和审批决策、稳定错误分类、总超时内有限重试、已审批 fallback 以及脱敏调用摘要。`api/ai-runtime.php` 已成为权威装配入口，根目录副本已收缩为薄兼容入口；体测、Drill v2 和招聘已接入统一能力。招聘固定使用百度 `ocr.extract` 提取文字、DeepSeek `text.generate` 生成 16 字段结构，两个 Adapter 均要求可审计外部处理审批，审批缺失时调用关闭。`image.generate` 固定为零供应商调用扩展点。

功能矩阵波次 5 已完成身份、组织、学习、知识、考试和制度六域的首批收口。`PlatformBusinessDomainRegistry` 统一登记稳定功能 ID、代表端点、历史消费者与能力，原 URL 保留为 Kernel 兼容控制器并转交稳定领域服务。学习首次奖励、知识服务端可见范围、考试草稿状态版本、制度阅读确认事务与 `policy.notify_send` 权限已形成自动契约。

第二批业务域收口已覆盖演练 `BIZ-006`、技能复盘 `BIZ-009`、提醒 `MSG-003`、企微 `MSG-001` 及问卷、活动、夏令营和体测专题 `BIZ-019` 至 `BIZ-022`。五个代表入口已通过 Kernel 兼容控制器统一认证、权限、请求 ID、异常、审计与迁移元数据；提醒和企微手工动作进入统一任务队列，技能新录音进入私有存储。运营域定向回归 89/89 通过。

工作量域已登记 `BIZ-001` 至 `BIZ-005`。`my-report.php` 与 `save-report.php` 保留历史业务字段，并通过工作量平台 Adapter 接入 Kernel、兼容元数据、等级 A 同步对象和持久化状态版本；导出与预警复用既有领域服务并由统一任务 Handler 调度。任务 12.3 的 Adapter 契约 7/7、平台与迁移定向回归 38/38、工作量回归 311/311 通过。

招聘域已登记 `BIZ-010` 至 `BIZ-013`。候选人核心读写入口接入 Kernel、具名权限、兼容元数据、审计和状态版本；简历 AI、OCR、私有文件、平台任务与提醒 outbox 通过薄 Adapter 复用既有领域服务。录用转员工使用持久化审批、幂等转换记录和单事务员工创建闭环，同一请求重放首次稳定结果。任务 12.4 的 Adapter 契约 9/9、招聘回归 47/47、平台定向回归 50/50 通过。

全站功能覆盖由 `real_sync/scripts/platform_function_coverage.mjs` 提供权威清单。清单与 inventory 的 89 个稳定功能 ID 双向校验，并为每组记录端面、生命周期、目标生命周期、可执行项、自动测试、静态证据、生产路径和发布验证状态；任一数量、ID、生命周期、证据或外部边界漂移都会阻断平台预检。当前生命周期统计为 deployed 60、implemented 27、planned 2，目标生命周期为 verified 87、planned 2；发布验证状态保留 approval_required 59、blocked_external 30。逐组本地回归覆盖 45 个测试文件、241/241 通过，全量 Node 回归 964/964，全仓 458 个 PHP 文件语法通过。

全站发布预检由 `real_sync/scripts/platform_regression_preflight.mjs` 统一编排。机器配置将波次 0 至 6 映射到 89 项覆盖、全量 Node/PHP、迁移、平台、权限、同步、文件、任务、AI、历史入口、小程序十域、文档链接和补丁格式共 17 个阶段；本地失败保持非零退出，数据库环境边界与生产审批分别输出 `blocked_external` 和 `approval_required`。最终检查点完整执行通过 193 个 Node 测试文件 983/983、465 个 PHP 文件、89/89 功能覆盖、42 个迁移版本兼容性检查、57 个 Markdown 文件及 33 条本地链接，阻断测试为 0；清理数据库连接环境后的 readiness dry-run 因缺少 `DB_PASSWORD` 保持外部阻断，生产数据库核对、备份恢复、真实供应商、生产角色旅程、浏览器/真机、Worker/Cron 和发布观察继续等待受控审批。

员工组织与工作量治理专题位于：

```text
.monkeycode/specs/2026-07-24-workload-governance-mini-program-launch/
```

| 文件 | 内容 |
| --- | --- |
| `requirements.md` | 工作量、员工组织、权限和移动端需求 |
| `design.md` | 数据模型、服务、API、迁移和测试设计 |
| `tasklist.md` | 分阶段实施任务与完成状态 |
| `UI_DEVELOPMENT_PLAN.md` | H5、小程序和后台 UI 规范 |
| `DEVELOPMENT_PLAN.md` | 开发顺序和验收计划 |
| `issues.md` | 审查问题和待确认事项 |

当前已建立员工组织、工作量治理和操作审计增量迁移：

```text
real_sync/database/migrations/202607240001_staff_organization.sql
real_sync/database/migrations/202607240002_workload_governance.sql
real_sync/database/migrations/202607240003_admin_operation_audit.sql
real_sync/database/migrations/202607240004_staff_employee_number_sequence.sql
real_sync/database/migrations/202607240005_workload_audit_task_history.sql
real_sync/database/migrations/202607240006_workload_audit_resubmission.sql
real_sync/database/migrations/202607240007_workload_metric_relations.sql
real_sync/database/migrations/202607240008_workload_standard_management.sql
real_sync/database/migrations/202607240009_workload_standard_import.sql
real_sync/database/migrations/202607270001_drill_api_foundation.sql
real_sync/database/migrations/202607270002_drill_content_domain.sql
real_sync/database/migrations/202607270003_drill_execution_domain.sql
real_sync/database/migrations/202607270004_drill_knowledge_growth_domain.sql
real_sync/database/migrations/202607270005_drill_content_governance_services.sql
real_sync/database/migrations/202607270006_drill_learning_services.sql
real_sync/database/migrations/202607270007_drill_plan_assignment_services.sql
real_sync/database/migrations/202607280001_workload_store_offline_actions.sql
real_sync/database/migrations/202607310002_platform_sessions.sql
real_sync/database/migrations/202607310003_miniprogram_device_sessions.sql
```

工作量治理专题任务 1 至 26 和任务 28.1 已完成。岗位标准配置支持启用岗位字典、日报义务开关、草稿与项目维护、复制差异、独立模板发布、日期区间切换、截止停用、幂等写入、操作审计和精确缓存失效；批量导入支持 CSV/XLSX、中文与英文表头、逐行预检、岗位隔离差异、原子草稿生成及定时发布。店长每日线下运营动作已按门店口径接入，点亮、收藏、好评、上翻和视频号动作均启用拍照或截图凭证；同店同日教练或销售上传的点亮凭证可汇总提示店长门店点亮项完成。工作量后台提供数据驾驶舱、审核队列、经营漏斗、预警建议、岗位标准和导入记录六个工作区，统一使用日期、组织、岗位、员工、项目、来源和权限筛选口径。完整质量门禁已通过 144 个 PHP 文件和 106 个 Node 测试文件；新版标准导入、真实基线隔离数据库迁移以及微信后台、真机验收作为发布前环境门禁保留。

销售演练重构专题位于 `.monkeycode/specs/2026-07-27-sales-drill-rebuild/`。任务 1 至 8 和任务 9.1、9.2、9.3 已完成：员工端与管理端 v2 公共入口采用统一响应和身份上下文，管理端使用八项具名权限；创建类请求具备持久化幂等基础；13 个旧演练端点及其 ID 空间、调用风险和源代码信号已形成可执行基线；内容、执行、知识与成长域建立不可变版本、完整证据链、学习推荐、参考资料、评分校准和成长快照；内容治理与学习服务提供发布预检、受控导入、映射发布、证据化推荐和内容缺口去重；计划服务支持专项练习和综合认证编排、岗位及门店等四类目标范围、有效复核人、发布定义幂等哈希和完整内容快照；员工任务支持正式状态图、时间窗、可信前置条件重评、失败上限、通知和审计；演练实例支持创建、恢复、文本轮次、暂停、恢复和结束；员工端音频资源、分片上传、受控读取、临时转写与最终合并接口已复用 v2 幂等契约，校验实例归属、格式、大小、序号、base64 内容和 SHA-256 摘要，并支持乱序分片重排、缺片提示、重复分片重放、冲突重传、真实录音授权门禁、访问范围判定和默认 180 天到期处理。任务 9.3 媒体测试 16/16、销售演练回归 99/99 通过；任务 9.3 相关 PHP 文件通过语法检查，两个既有 PHP 文件继续保留语法阻断记录。真实 MySQL 迁移和事务并发验证保留到隔离数据库执行。

## 核心概念

| 概念 | 内容 |
| --- | --- |
| [员工身份与任职](./专有概念/员工身份与任职.md) | 登录账号、员工档案、门店、岗位、角色和任职历史 |
| [工作量日报](./专有概念/工作量日报.md) | 日报、项目值、凭证、审核和统计事实 |

## 模块导航

| 模块 | 内容 |
| --- | --- |
| [API](./模块/API.md) | PHP API 结构、公共模式和修改检查 |
| [微信小程序](./模块/微信小程序.md) | 小程序结构、请求层和提审检查 |
| [数据库迁移](./模块/数据库迁移.md) | 版本化迁移、员工组织迁移和测试 |
| [受控文件服务](./模块/受控文件服务.md) | 文件资产、私有存储、流式下载、留存清理和 Drill Adapter |
| [统一 AI 能力](./模块/统一 AI 能力.md) | 能力请求、审批、路由、错误、恢复和脱敏调用摘要 |

## 业务文档

| 主题 | 文档 |
| --- | --- |
| 后台管理 | [backend-admin-spec.md](./backend-admin-spec.md) |
| 工作量范围 | [normalized-workload-ops-scope.md](./normalized-workload-ops-scope.md) |
| 工作量 API | [normalized-workload-ops-api.md](./normalized-workload-ops-api.md) |
| 工作量二期 | [workload-system-phase2-master-plan-2026-05-09.md](./workload-system-phase2-master-plan-2026-05-09.md) |
| 工作量审计 | [workload-audit-2026-05-09.md](./workload-audit-2026-05-09.md) |
| 凭证验收 | [workload-evidence-acceptance-and-test-2026-05-17.md](./workload-evidence-acceptance-and-test-2026-05-17.md) |
| 小程序提醒 | [mini-program-reminder-and-data-consistency-2026-06-21.md](./mini-program-reminder-and-data-consistency-2026-06-21.md) |
| 小程序代码审计 | [mini-program-dead-code-audit-2026-06-21.md](./mini-program-dead-code-audit-2026-06-21.md) |
| 制度治理 | [policy-center-governance-plan-2026-06-12.md](./policy-center-governance-plan-2026-06-12.md) |

## 企业微信

| 文档 | 用途 |
| --- | --- |
| [wecom-mini-program-master-plan-2026-06-23.md](./wecom-mini-program-master-plan-2026-06-23.md) | 企业微信与小程序总体规划 |
| [wecom-gap-tracking-2026-06-24.md](./wecom-gap-tracking-2026-06-24.md) | 缺口与进度台账 |
| [wecom-final-12-percent-taskboard-2026-06-24.md](./wecom-final-12-percent-taskboard-2026-06-24.md) | 收口任务板 |
| [wecom-preflight-checklist-2026-06-24.md](./wecom-preflight-checklist-2026-06-24.md) | 上线前只读检查 |
| [wecom-preflight-baseline-snapshot-2026-06-24.md](./wecom-preflight-baseline-snapshot-2026-06-24.md) | 已知线上基线 |
| [wecom-backup-and-rollback-checklist-2026-06-24.md](./wecom-backup-and-rollback-checklist-2026-06-24.md) | 备份与回滚 |
| [wecom-go-live-checklist-2026-06-24.md](./wecom-go-live-checklist-2026-06-24.md) | 上线执行顺序 |
| [wecom-existing-site-regression-checklist-2026-06-24.md](./wecom-existing-site-regression-checklist-2026-06-24.md) | 旧链路回归 |

## 架构与修复

| 文档 | 用途 |
| --- | --- |
| [architecture-stability-audit-2026-06-12.md](./architecture-stability-audit-2026-06-12.md) | 架构稳定性和公共层审计 |
| [agent-handoff-latest-changes-2026-06-09.md](./agent-handoff-latest-changes-2026-06-09.md) | 历史接手记录 |
| [full-remediation-master-plan-2026-05-15.md](./full-remediation-master-plan-2026-05-15.md) | 全面修复总计划 |
| [full-remediation-execution-schedule-2026-05-15.md](./full-remediation-execution-schedule-2026-05-15.md) | 修复执行日程 |
| [remediation-batch-mapping-2026-05-15.md](./remediation-batch-mapping-2026-05-15.md) | 修复批次映射 |
| [final-acceptance-checklist-2026-05-17.md](./final-acceptance-checklist-2026-05-17.md) | 全量验收清单 |

## 快速验证

在 `real_sync/` 目录执行：

```bash
node --test scripts/staff_organization_migration.test.mjs
node --test scripts/workload_governance_migration.test.mjs
node --test scripts/migration_runner.test.mjs
node --test scripts/migration_idempotency.test.mjs
node --test scripts/drill_api_foundation.test.mjs
node --test scripts/drill_legacy_baseline.test.mjs
node --test scripts/drill_idempotency.property.test.mjs
node scripts/snapshot-drill-api.mjs --check
node --test scripts/staff_lifecycle_service.test.mjs
node --test scripts/staff_directory_service.test.mjs
node --test scripts/staff_employee_number.property.test.mjs
node --test scripts/staff_account_identity.property.test.mjs
node --test scripts/staff_directory_field_allowlist.property.test.mjs
node --test scripts/organization_position_service.test.mjs
node --test scripts/organization_store_service.test.mjs
node --test scripts/organization_assignment_service.test.mjs
node --test scripts/organization_tree_service.test.mjs
node --test scripts/organization_integration.test.mjs
node --test scripts/staff_primary_assignment.property.test.mjs
node --test scripts/staff_assignment_history.property.test.mjs
node --test scripts/staff_update_service.test.mjs
node --test scripts/staff_offboard_service.test.mjs
node --test scripts/staff_restore_service.test.mjs
node --test scripts/staff_purge_check_service.test.mjs
node --test scripts/staff_purge_service.test.mjs
node --test scripts/staff_lifecycle_integration.test.mjs
node --test scripts/staff_session_invalidation.property.test.mjs
node --test scripts/staff_purge_association.property.test.mjs
node --test scripts/identity_consistency_service.test.mjs
node --test scripts/password_policy_service.test.mjs
node --test scripts/admin_permission_service.test.mjs
node --test scripts/privileged_role_guard.test.mjs
node --test scripts/role_password_permission_integration.test.mjs
node --test scripts/staff_role_consistency.property.test.mjs
node --test scripts/staff_management_permission.property.test.mjs
node --test scripts/staff_import_service.test.mjs
node --test scripts/staff_directory_export.test.mjs
node --test scripts/staff_data_health_service.test.mjs
node --test scripts/staff_profile_service.test.mjs
node --test scripts/staff_bulk_health_profile_integration.test.mjs
node --test scripts/staff_import_idempotency.property.test.mjs
node --test scripts/staff_directory_ui.test.mjs
node --test scripts/staff_create_drawer_ui.test.mjs
node --test scripts/staff_detail_risk_ui.test.mjs
node --test scripts/organization_management_ui.test.mjs
node --test scripts/staff_import_health_ui.test.mjs
node --test scripts/staff_admin_interactions.test.mjs
node --test scripts/staff_admin_accessibility.test.mjs
node --test scripts/workload_obligation_service.test.mjs
node --test scripts/workload_obligation_backfill_service.test.mjs
node --test scripts/workload_report_state_service.test.mjs
node --test scripts/workload_obligation_lifecycle.test.mjs
node --test scripts/workload_obligation_uniqueness.property.test.mjs
node --test scripts/workload_report_uniqueness.property.test.mjs
node --test scripts/workload_completion_state_exclusivity.property.test.mjs
node --test scripts/workload_monday_exemption.property.test.mjs
node --test scripts/workload_business_day_obligation_count.property.test.mjs
node --test scripts/workload_employee_lock_persistence.property.test.mjs
node --test scripts/workload_source_policy_service.test.mjs
node --test scripts/workload_metric_version_service.test.mjs
node --test scripts/workload_role_rule_version_service.test.mjs
node --test scripts/workload_source_rule_version_integration.test.mjs
node --test scripts/workload_synthetic_source_zero_contribution.property.test.mjs
node --test scripts/workload_audit_task_history.test.mjs
node --test scripts/workload_effective_value_service.test.mjs
node --test scripts/workload_audit_resubmission.test.mjs
node --test scripts/workload_audit_effective_value.test.mjs
node --test scripts/workload_effective_selection_numerator.property.test.mjs
node --test scripts/workload_audit_value_traceability.property.test.mjs
node --test scripts/workload_analytics_query_service.test.mjs
node --test scripts/workload_analytics_aggregate_service.test.mjs
node --test scripts/workload_analytics_filter_aggregation.test.mjs
node --test scripts/workload_store_required_detail_conservation.property.test.mjs
node --test scripts/workload_completion_status_count_conservation.property.test.mjs
node --test scripts/workload_selection_rate_numerator.property.test.mjs
node --test scripts/workload_store_completion_analytics.test.mjs
node --test scripts/workload_metric_selection_analytics.test.mjs
node --test scripts/workload_staff_profile_analytics.test.mjs
```

这些命令验证员工组织与工作量治理结构、迁移运行器、幂等与保留式回滚模型、员工新增、离职与恢复事务、可重试批量导入、员工目录导出、员工数据健康检查、本人档案和更正申请、跨服务权限与问题关闭、误建清理关联预检、短时确认令牌、受控身份链清理及并发状态变化、高权限角色二次确认与最后管理员保护、角色同步与密码权限组合场景、缺省工号生成、冲突响应、员工目录字段白名单、员工目录组合筛选与响应式双视图、新增员工三步抽屉与防重复提交、员工详情抽屉与高风险操作区、组织树形与列表双视图、门店和岗位设置抽屉、批量导入模板、文件解析、字段映射、逐行预检查与原批次失败重试、七类数据健康问题与修复后复检、员工后台筛选、分页、新增、详情、高风险操作、组织树、导入和错误重试交互链、模态抽屉初始焦点、焦点恢复、Tab 循环、Escape 关闭、键盘状态语义、窄屏共享卡片和可测试异步状态、停用引用阻断、窄屏设置卡片、岗位与门店字典启停和引用检查、主岗与兼岗生效区间、历史保护、当前组织树聚合、周一公休日、周二至周日日报义务、业务日期任职、角色规范化、义务幂等、完成状态保护、历史日报组织快照、有效任职缺交回填、数据库权威时间、日报义务原子同步、24:00 锁定、管理更正快照、调店转岗快照、事务失败回滚、生产与合成来源隔离和统一统计口径版本，以及义务唯一性属性 1、日报唯一性属性 2、完成状态互斥属性 3、周一免交属性 13、营业日在职义务计数属性 14、员工锁定持续性属性 15、工号唯一性属性 19、员工账号一对一属性 20、主岗唯一性属性 21、旧会话失效属性 22、角色身份一致性属性 23、受控清理关联属性 24、历史任职不可变属性 25、员工管理权限矩阵属性 26、角色字段裁剪属性 29 和导入幂等属性 30。当前全量 Node 自动测试共 332 项；任务 9.1 至 9.10 及 10.1、10.2 已完成，任务 10.2 通过 6 项定向测试及全部 332 项回归测试。

岗位项目规则版本测试覆盖闭区间生效选择、最低四项兼容、岗位必填、零值、数值范围、凭证边界、模板约束和历史日报版本绑定。当前全量 Node 自动测试共 338 项；任务 10.3 通过 6 项定向测试、79 项工作量回归及全部 338 项回归测试。

来源、规则和跨版本集成测试覆盖 H5 与小程序生产来源、可审计合成来源、最低四项规则、带生效日期的岗位必填与凭证约束，以及历史日报的口径和规则双版本绑定。当前全量 Node 自动测试共 344 项；任务 10.4 通过 6 项定向测试、85 项工作量回归及全部 344 项回归测试。

正确性属性 10 使用固定种子随机混合日报序列验证所有合成来源对默认经营合计保持零贡献，同时保留按来源审计查询能力，并核对全部经营统计入口共享来源策略。当前全量 Node 自动测试共 348 项；任务 10.5 通过 4 项定向测试、89 项工作量回归及全部 348 项回归测试，任务 10 已完成。

审核任务历史测试覆盖首次提交、重新提交版本递增、前序任务关联、原审核意见保留、指标移除后的历史保留、历史任务只读、认证审核入口、当前统计过滤和增量迁移契约。任务 11.1 通过 6 项定向测试、95 项工作量回归及全部 354 项 Node 回归测试。

三值计算器统一原始值、待审核值和有效值公式，优先使用日报绑定的岗位项目规则版本，并只关联未废止的当前审核任务。驾驶舱、总部汇总、门店汇总、员工明细、员工活动、后台汇总和审核积压均复用该计算器；现有排名与分数字段继续表示有效值。任务 11.2 通过 10 项定向测试、105 项工作量回归及全部 364 项 Node 回归测试。

审核闭环将后台 `pending` 任务连接到批准、驳回和补凭证状态。员工可为本人当前 `needs_resubmit` 任务追加凭证并重新送审；服务使用审核时凭证数量作为新增凭证基线，废止旧任务后创建递增版本的 `pending` 后继任务，重复请求返回同一后继任务。本人日报接口同步返回审核意见、凭证状态和下一动作。任务 11.3 通过 9 项定向测试、114 项工作量回归及全部 373 项 Node 回归测试。

审核状态与有效值单元测试精确覆盖 `pending`、`approved`、`rejected`、补凭证重审、日报重新提交和管理更正。管理更正现已在同一事务中废止旧审核任务、保留历史和真实操作人，并为更正值创建递增版本的 `pending` 任务。任务 11.4 通过 7 项定向测试、121 项工作量回归及全部 380 项 Node 回归测试。

正确性属性 7 使用 128 个固定种子、每个 256 份随机已提交日报，持续验证有效选取率正数分子小于或等于原始选取率正数分子。测试覆盖全量与非全量审核、任务缺失、全部审核状态、正负零和两位小数舍入边界，并锁定 PHP 行级计算与 SQL 表达式只能将有效值映射为原始值或零。任务 11.5 通过 3 项定向测试、124 项工作量回归及全部 387 项 Node 回归测试。

正确性属性 8 使用 128 个固定种子、每个 256 步随机审核状态转换，持续验证任务版本、前序链、当前任务唯一性、废止日志和历史决策恢复。统一计算器增加 `rejected_value`；审核列表通过废止日志返回 `trace_status`，使历史任务的待审核值、已审核有效值和驳回值可按原决策完整重放。任务 11.6 通过 3 项定向测试、127 项工作量回归及全部 390 项 Node 回归测试，任务 11 已完成。

统一统计查询内核集中处理日期、门店、岗位、员工、项目、日报、审核和来源筛选，并将筛选条件与员工本人、店长有效管理门店或总部全量权限取交集。最细事实固定为业务日期、门店、员工、岗位和项目组合，返回日报与审核状态、凭证数量和四值，供后续统计端点与导出共享。任务 12.1 通过 5 项定向测试、132 项工作量回归及全部 395 项 Node 回归测试。

统一统计聚合内核只消费已提交事实，按项目计算四值总量、五类比例、三类均值、样本量和低样本状态，并披露每项计算的分子、分母、口径版本、筛选范围、权限范围和数据截止时间。任务 12.2 通过 4 项定向测试、136 项工作量回归及全部 399 项 Node 回归测试。

统计过滤与聚合守恒测试验证空数据和低样本边界、九维交集筛选、默认经营来源隔离、日报历史组织快照、审核状态四值映射、互斥门店分区可加守恒及重复事实去重。任务 12.3 通过 8 项定向测试、144 项工作量回归及全部 407 项 Node 回归测试。

正确性属性 4 使用固定种子随机义务序列和组合下钻筛选，验证门店应交汇总始终等于 `required_status=required` 的明细义务单元数量，并排除公休日豁免和范围外快照。任务 12.4 通过 4 项定向测试、148 项工作量回归及全部 411 项 Node 回归测试。

正确性属性 5 使用固定种子随机义务与完成状态转换序列，验证任意日期、门店、员工和岗位组合范围内，`missing`、`draft`、`submitted`、`locked_missing` 与 `corrected` 数量之和始终等于 `required_status=required` 的应交义务数量，公休日 `exempt` 不进入分母。任务 12.5 通过 4 项定向测试、152 项工作量回归及全部 415 项 Node 回归测试。

正确性属性 6 使用固定种子随机混合指标事实，验证每个指标两位小数舍入后的原始正数日报集合始终属于按日报 ID 去重后的已提交日报集合。测试覆盖草稿、无效事实、重复日报、指标隔离、数值边界与空输入。任务 12.6 通过 3 项定向测试、155 项工作量回归及全部 418 项 Node 回归测试，统一统计查询内核任务 12 已完成。

门店周期完成接口已接入统一统计内核，按权限返回门店与日期完成汇总、五类状态明细、公休日历和下钻令牌。门店项目矩阵按岗位义务分母计算完成率、每应交人日均值、义务员工人均值与覆盖率，补齐全部适用项目和应交员工的零值单元，并返回四值及门店间有效值、原始值排名。任务 13.1 通过 5 项定向测试、160 项工作量回归及全部 423 项 Node 回归测试。

项目选取、覆盖和排名接口复用统一事实与聚合内核，按岗位和项目返回五类比例、四值、三类均值、覆盖及数值排名；门店层提供全部门店均值和前 25% 有效值参考，员工层提供门店内与全部门店同岗位双层排名。适用项目、门店和应交员工均补齐零值行，待审核事实通过显式标志披露。任务 13.2 通过 5 项定向测试、165 项工作量回归及全部 428 项 Node 回归测试。

员工周期画像接口将日报义务、日报主信息、全部项目四值、凭证、审核意见、日周月趋势和同岗位排名组合为统一响应。对比口径排除周一，返回等营业日上期、变化状态、过去四期均值和样本状态；本人、店长授权门店与总部全量范围继续复用统一权限。任务 13.3 通过 6 项定向测试、171 项工作量回归及全部 434 项 Node 回归测试。

经营漏斗接口复用统一事实和项目聚合，返回销售新增资源、实际邀约、实际到店、成交人数和新签金额五阶段四值，按生效关系版本计算三段销售转化率及教练耗课、沟通计划完成率。关系结果披露原始值与有效值比率、样本、低样本、待审核和零分母状态；增量迁移提供版本头与关系明细。任务 13.4 通过 6 项定向测试、177 项工作量回归及全部 440 项 Node 回归测试。

门店、项目、员工和漏斗集成测试使用统一事实集验证跨统计表面守恒、历史任职门店快照、全部审核状态四值一致性、筛选后低样本重算和统计截止日关系版本切换，并锁定四个生产统计服务对统一事实内核和版本关系契约的复用。任务 13.5 通过 6 项定向测试、183 项工作量回归及全部 446 项 Node 回归测试，门店、项目和员工统计接口任务 13 已完成。

营业日周期解析器统一支持日、周二至周日业务周、月累计、完整月、季度和自定义周期，排除周一并返回完整自然周期与等营业日比较窗口。完整月和季度保留日历边界，比较窗口按两期较少营业日数截齐并披露截断状态。任务 14.1 通过 6 项定向测试、189 项工作量回归及全部 452 项 Node 回归测试。

通用环比与基准服务统一本期、上期、变化量、变化率、可解释状态、过去四期均值、两期样本和低样本输出，并集中计算带分子分母的均值与前 25% 参考值。员工画像和项目排名已接入该服务。任务 14.2 通过 6 项定向测试、195 项工作量回归及全部 458 项 Node 回归测试。

通用交叉分析接口支持门店、项目、员工和时间任意两个互异维度，时间可按日、业务周、月和季度聚合。矩阵统一返回四值、应交与完成人日、完成率、义务员工覆盖率、选取率、每应交人日均值、样本、低样本、排名和最细事实下钻参数，并按岗位适用项目补齐零值单元。任务 14.3 通过 6 项定向测试、201 项工作量回归及全部 464 项 Node 回归测试。

周期与交叉聚合集成测试验证周一、跨月、跨季度、低样本、上期为零、历史门店快照和门店员工交叉守恒。任务 14.4 通过 6 项定向测试、207 项工作量回归及全部 470 项 Node 回归测试。

正确性属性 16 通过固定种子随机场景验证日、业务周、月累计、完整月、季度和自定义周期的本期与上期比较窗口始终拥有相同营业日数量。任务 14.5 通过 1 项属性测试、208 项工作量回归及全部 471 项 Node 回归测试。

正确性属性 17 通过固定种子随机数据验证全部交叉维度组合的矩阵汇总与最细事实聚合守恒。任务 14.6 通过 1 项属性测试、209 项工作量回归及全部 472 项 Node 回归测试。

任务 15.1 已建立统一工作量权限范围服务，输出本人、授权门店和全量数据范围，以及排名可见范围和系统管理员配置能力；全量 Node 回归为 473/473。

任务 15.2 已将统一权限接入统计、排名、交叉分析和明细下钻，并提供导出权限契约；工作量回归为 211/211，全量 Node 回归为 474/474。

任务 15.3 已完成工作量权限矩阵集成测试，覆盖本人、单店、多店、总部、管理员和历史门店快照；工作量回归为 213/213。

任务 15.4 已完成权限范围正确性属性测试，固定种子场景验证本人、授权门店和全量数据范围；全量 Node 回归基线为 477/477。

任务 17.1 已实现门店周期完成和项目选取度 UTF-8 CSV 导出，复用统计筛选、权限与口径版本；工作量回归为 218/218，全量 Node 回归为 481/481。

任务 17.2 已实现个人全数据和项目全维度 CSV 导出，包含日报、项目、凭证、审核及四类值字段；工作量回归为 219/219，全量 Node 回归为 482/482。

任务 17.3 已实现 20,000 行以上异步导出任务、CLI worker 和受控下载，生成及下载阶段重复校验权限；工作量回归为 222/222，全量 Node 回归为 485/485。

全站多端架构升级检查点任务 8 已完成。员工权限断言与公共权限矩阵保持一致，员工工号测试兼容迁移中的唯一索引名称，工作量预警后台使用 `evidence` 展示结构化事实证据，并通过 `event_id`、`comment` 提交处理请求。工作量页面定向回归为 9/9，全量 Node 回归为 866/866。
