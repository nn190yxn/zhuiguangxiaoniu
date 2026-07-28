# 追光小牛企业内网开发指南

## 项目目的

本仓库维护追光小牛企业内网的 PHP API、员工 H5、管理后台、微信小程序、企业微信集成、培训内容和数据库脚本。业务运行基线位于线上目录 `/www/wwwroot/122.51.223.46/`，本地主要代码目录为 `real_sync/`。

## 环境要求

完整本地运行环境需要：

- 支持 PDO MySQL 的 PHP CLI 与 PHP Web 运行环境
- MySQL 数据库
- 包含 `wp_users`、`wp_usermeta` 的 WordPress 数据结构
- 可提供静态文件和 PHP 的 Web Server
- Node.js 18 或更高版本，用于迁移契约测试
- 微信开发者工具，用于小程序页面与真机验证

仓库未提供 Composer、npm 构建清单、Docker 编排、完整 WordPress 核心或 Web Server 配置。环境初始化需结合部署配置完成。

## 配置

`real_sync/api/config.php` 从部署环境读取数据库、JWT、CORS 和外部 API 配置。PHP-FPM 部署可使用项目既有的本地配置加载方式。

配置原则：

- 数据库密码、JWT Secret、微信 Secret 和企业微信 Secret 由部署环境注入。
- 示例文件使用 `<PLACEHOLDER>`。
- 日志和文档保留配置项名称及状态，屏蔽真实值。
- 线上配置变更前完成备份、预检和回滚记录。

## 代码入口

| 目标 | 位置 |
| --- | --- |
| 网站首页 | `real_sync/index.html` |
| 员工工作台 | `real_sync/internal.html` |
| 员工 H5 | `real_sync/mobile/` |
| 总部后台 | `real_sync/admin/` |
| PHP API | `real_sync/api/` |
| 微信小程序 | `real_sync/mini-program/` |
| 企业微信 | `real_sync/api/wecom/` |
| 数据库迁移 | `real_sync/database/migrations/` |
| 测试与维护脚本 | `real_sync/scripts/` |

## 开发工作流

1. 从 `.monkeycode/specs/` 确认当前需求、设计和任务边界。
2. 核对线上基线文档与本地代码路径。
3. 一次实施一个任务，采用最小兼容改动。
4. 为新增行为编写自动测试。
5. 执行相关测试与 `git diff --check`。
6. 更新任务清单和 `.monkeycode/docs/`。
7. 生产部署作为独立受控动作执行。

## API 开发

新增或修改 API 时沿用以下模式：

- 引入 `real_sync/api/config.php` 或业务公共文件获取数据库连接。
- 使用 `real_sync/api/common/context.php` 获取员工身份和数据范围。
- 后台接口复用 `real_sync/api/admin/common.php` 的授权入口。
- 使用 PDO 预处理语句绑定用户输入。
- 使用统一 JSON 响应函数返回 `code`、`message` 和 `data`。
- 写操作使用事务并在异常路径回滚。
- 日志记录业务上下文并脱敏手机号、OpenID、Token 和密码。

## 数据库迁移

版本化迁移放在 `real_sync/database/migrations/`，命名格式为：

```text
YYYYMMDDNNNN_descriptive_name.sql
```

员工组织迁移：

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
```

迁移运行器入口：

```bash
php scripts/migrate.php status
php scripts/migrate.php apply --dry-run
php scripts/migrate.php apply
php scripts/migrate.php verify
php scripts/migrate.php rollback-plan
```

`apply` 输出迁移版本、结构差异、行数差异和核验结果。`verify` 按 `database/migration_manifest.php` 检查表、字段、索引和迁移校验值。

应用 `202607270005` 后，可由具备演练内容管理权限的员工将新签受控内容包导入草稿与待审核区：

```bash
php scripts/import_drill_new_sign_content.php <actor_staff_id>
```

导入命令以内容包摘要保持批次幂等，评分规则、画像和校准锚点保持草稿状态，参考资料保持待审核状态。课包数量、品牌数字、效果表达、案例授权和资料有效期对应的开放核验问题全部解决后，参考资料才具备发布及评分绑定资格。

迁移编写要求：

- 使用增量结构变更保留现有表和数据。
- 对历史数据库差异使用 `information_schema` 核验。
- 新索引上线前先查询重复值与无效引用。
- 历史数据回填使用确定性映射，并提供可核对计数。
- 已部署迁移保持内容稳定，后续调整创建新版本。
- 生产执行前备份目标表结构和数据。

当前迁移会建立唯一索引。执行前重点检查：

```sql
SELECT employee_no, COUNT(*) AS total
FROM staffs
WHERE employee_no IS NOT NULL
GROUP BY employee_no
HAVING COUNT(*) > 1;

SELECT user_id, COUNT(*) AS total
FROM staffs
WHERE user_id IS NOT NULL
GROUP BY user_id
HAVING COUNT(*) > 1;
```

## 自动测试

员工组织迁移契约测试：

```bash
node --test scripts/staff_organization_migration.test.mjs
node --test scripts/workload_governance_migration.test.mjs
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
node --test scripts/workload_standard_management.test.mjs
node --test scripts/workload_standard_version_interval.property.test.mjs
node --test scripts/workload_standard_import.test.mjs
node --test scripts/workload_standard_import_difference.property.test.mjs
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
node --test scripts/workload_operating_funnel_analytics.test.mjs
node --test scripts/workload_admin_closure.test.mjs
node --test scripts/workload_admin_ui.test.mjs
node --test scripts/workload_admin_interactions.test.mjs
node --test scripts/workload_analytics_surfaces_integration.test.mjs
node --test scripts/workload_business_period_service.test.mjs
node --test scripts/workload_comparison_service.test.mjs
node --test scripts/workload_cross_analysis.test.mjs
node --test scripts/workload_period_cross_analysis_integration.test.mjs
node --test scripts/workload_business_period_alignment.property.test.mjs
node --test scripts/workload_cross_analysis_conservation.property.test.mjs
node --test scripts/migration_runner.test.mjs
node --test scripts/migration_idempotency.test.mjs
node --test scripts/drill_api_foundation.test.mjs
node --test scripts/drill_legacy_baseline.test.mjs
node --test scripts/drill_idempotency.property.test.mjs
node --test scripts/drill_content_domain.test.mjs
node --test scripts/drill_content_versioning.test.mjs
node --test scripts/drill_content_reference.property.test.mjs
node --test scripts/drill_execution_domain.test.mjs
node --test scripts/drill_execution_constraints.property.test.mjs
node --test scripts/drill_knowledge_growth_domain.test.mjs
node --test scripts/drill_knowledge_growth_constraints.property.test.mjs
node --test scripts/drill_content_governance_services.test.mjs
node --test scripts/drill_content_governance.property.test.mjs
node --test scripts/drill_learning_services.test.mjs
node --test scripts/drill_learning_services.property.test.mjs
node --test scripts/drill_plan_assignment_services.test.mjs
node --test scripts/drill_plan_assignment_services.property.test.mjs
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
node --test scripts/staff_directory_ui.test.mjs
node --test scripts/staff_create_drawer_ui.test.mjs
node --test scripts/staff_detail_risk_ui.test.mjs
node --test scripts/organization_management_ui.test.mjs
node --test scripts/staff_import_health_ui.test.mjs
node --test scripts/staff_admin_interactions.test.mjs
node --test scripts/staff_admin_accessibility.test.mjs
```

测试覆盖：

- 销售演练 v2 员工与管理公共入口、统一响应、请求 ID、具名权限和跨域请求头契约
- 13 个旧演练端点、实体 ID 空间、反馈断链、媒体路径、重复完成和并发轮次风险基线
- 幂等请求的规范化哈希、首次执行、相同请求重放、不同请求冲突、事务回滚和迁移唯一键
- 销售演练双训练域、流程版本、新签八板块、场景与评分规则版本唯一性、来源和归档结构
- 内容版本草稿、审核、发布和归档状态机，发布态写保护与递增版本修订
- 正确性属性 2：后续版本发布后，历史实例的场景版本、画像参数快照和评分版本引用保持不变
- 销售演练计划、目标范围、发布批次、复核人、发布快照和员工任务唯一性
- 演练参与者、评分对象、资料绑定、音频分片、轮次、转写和带角色时间戳分段的数据契约
- 评分证据、结构化报告、SMART 任务、人工复核、失败辅导、正式认证、通知和审计完整性
- 随机任务及轮次写入、分片重试、跨实例证据隔离、认证必填身份和活动辅导任务唯一性属性
- 知识点、移动学习资源、参考资料与评分校准的稳定身份、版本内容哈希、审核发布和有效期约束
- 评分关键项、知识点与资源的发布映射完整性，推荐与同一演练、评分、证据、映射及资源版本归属
- 当前评分版本下最近成绩与有效最佳成绩、60/70/80/90 共同门槛、必修板块与完整流程双达标及待重新评估状态
- 内容管理权限、流程排序、受控画像、场景人工审核、发布版本不可变、修订与归档过滤
- 混合评分结构、八维能力到八板块映射、评分上下文路由和新签续费训练域隔离
- 新签实操录音版与培训演练版草稿、FAB、定价、案例、参考资料、校准锚点及五类发布阻断项
- AI 候选人工审核属性与参考资料授权、有效期、内容哈希和开放阻断项发布资格属性

- 员工生命周期和会话字段
- 门店编码和负责人字段
- 五张员工组织新表
- 工号、账号、门店编码和岗位编码唯一约束
- 任职历史查询索引
- 导入重试和资料更正字段
- 历史岗位与主任职回填
- 破坏性 SQL 语句检查
- 日报义务唯一性、状态和查询索引
- 生产与合成来源策略
- 统计口径和岗位项目规则版本绑定
- 历史日报义务确定性回填
- 上海业务日期、周一公休日、周二至周日日报义务生成和次日 `00:00:00` 截止时间
- 业务日期任职、员工生命周期、启用门店、启用岗位、销售与教练角色筛选
- 重复任职折叠、历史角色别名兼容、义务唯一键幂等和已完成日报状态保护
- 历史日报门店与角色快照、历史任职区间补建、回填来源、日期范围和管理更正状态保护
- 数据库权威时间、23:59 与 00:00 边界、日报和义务原子同步、到期锁定、员工状态响应及管理更正快照
- 截止后员工草稿与提交操作持续锁定、重复锁定幂等和管理更正授权迁移
- 来源登记校验、生产与合成来源分类、经营统计默认来源过滤及来源范围披露
- 统计口径生效版本选择、响应与导出元数据、权限隔离缓存键、审计上下文及日报版本绑定
- 岗位规则闭区间生效版本选择、最低四项兼容、必填与零值、数值范围、凭证边界及历史日报规则绑定
- 审核任务版本递增、前序关联、审核意见保留、历史任务只读、认证审核入口和当前积压过滤
- 已提交事实按项目聚合、日报去重、五类比例、三类均值、四值总量、低样本标记和统一口径元数据
- 九维统计筛选交集、默认经营来源隔离、历史组织快照、审核四值映射、互斥门店分区守恒和重复事实去重
- 待审核任务批准、驳回与补凭证转换、意见必填、员工凭证授权、审核时凭证基线、重审版本递增和重复请求幂等
- 批准、驳回、待审核、重审、重新提交与管理更正路径下的原始值、待审核值和有效值精确结果
- 营业周完整义务、上海时区 UTC 等价截止点、调店与转岗日期快照、日报义务同步失败回滚及管理更正审计失败回滚
- 预警、导出和管理更正治理结构
- 迁移版本、校验值、状态、结构差异和计数差异契约
- 空应用基线、历史数据、重复执行、部分结构和注入失败后的幂等重试模型
- 员工创建字段、组织引用、身份冲突、事务顺序、回滚和权限契约
- 缺省工号序列锁、配置规则、冲突字段和脱敏摘要契约
- 员工列表筛选、分页、字段白名单、详情聚合和敏感字段权限契约
- 正确性属性 1：随机义务写入序列下同一日期、门店、员工和规范化岗位最多存在一个义务
- 正确性属性 2：随机日报保存序列下同一日期、门店、员工和规范化岗位最多存在一份日报主记录
- 正确性属性 3：随机状态转换序列下同一义务的草稿、已提交和缺交状态始终互斥
- 正确性属性 4：任意组合筛选下门店应交汇总等于对应明细义务单元数量
- 正确性属性 5：任意组合范围内五类应交完成状态数量之和等于应交义务数量
- 正确性属性 6：任意指标的原始正数日报数小于或等于去重后的已提交日报数
- 项目、门店与员工三层选取统计，覆盖零值补齐、门店基准、项目隔离排名和员工双层同岗位排名
- 员工周期画像，覆盖完整义务与日报、凭证审核、日周月趋势、等营业日对比、过去四期均值和权限范围
- 版本化项目关系、销售五阶段四值、三段转化率、教练计划完成率、零分母状态和低样本提示
- 正确性属性 13：任意周一的应交义务数始终等于零，适用候选仅生成公休日豁免标记
- 正确性属性 14：任意周二至周日的应交义务数始终等于当日在职适用任职候选数
- 正确性属性 10：任意混合来源日报序列中合成来源对默认经营合计始终保持零贡献并可按来源审计
- 正确性属性 7：任意已提交日报集合的有效正数日报数始终小于或等于原始正数日报数
- 正确性属性 8：任意审核版本链的待审核值、已审核有效值和驳回值均可从当前状态或废止日志恢复
- 正确性属性 19：随机创建序列下同一工号最多对应一个员工档案
- 正确性属性 20：员工档案与 WordPress 系统账号保持双向一对一关系
- 正确性属性 29：任意角色的员工列表响应字段始终属于该角色允许的字段集合
- 岗位字典字段校验、角色规范化、稳定排序、唯一冲突、启停、引用检查和操作审计契约
- 门店字典字段校验、负责人兼容、稳定排序、唯一冲突、停用归属检查和导入门店锁契约
- 主岗闭区间切换、同日幂等与冲突、计划主岗边界、兼岗重叠、历史只读、快速字段同步和任职审计契约
- 组织树当前生效筛选、总部到员工层级、空门店保留、兼岗多节点、列表摘要和敏感字段白名单
- 组织字典、任职区间与组织树跨域联动，覆盖启停引用、历史保留、调店、转岗、兼岗和同日冲突
- 正确性属性 21：随机主岗变更序列始终保持每位员工任一业务日期最多一个有效主岗
- 正确性属性 25：随机组织操作始终保持所有已结束主岗和兼岗记录的完整快照不变
- 员工编辑字段与唯一性校验、离职只读、状态联动、组织变更嵌套事务、回滚和审计快照
- 离职日期、原因与确认校验，员工和 WordPress 账号停用，任职关闭，会话与设备订阅撤销，失败回滚和审计快照
- 离职员工恢复校验，账号重新启用，会话版本递增，新主岗与兼岗创建，外层事务复用和失败回滚
- 误建员工固定关联白名单、结构兼容状态、主任职基线、零关联资格判断和 5 分钟确认令牌绑定契约
- 受控清理权限、二次确认、事务锁、关联复检、令牌防篡改与状态绑定、身份链删除计数、完整审计和失败回滚
- 员工在职与停用离职、离职恢复、有关联与零关联、令牌过期、状态变化、关联竞态和双清理并发的跨服务状态模型
- JWT 会话版本绑定、员工启停与离职版本递增，以及旧会话永久失效的正确性属性 22
- 八类业务关联随机组合、身份基线边界和结构完整性对应的正确性属性 24
- 员工角色、WordPress 能力和会话版本的事务一致性及失败回滚
- 创建、管理员重置和本人改密的统一密码策略与会话轮换
- 总部运营与系统管理员的具名员工管理权限矩阵及系统设置隔离
- 高权限角色确认令牌的签名、时效、状态绑定、另一管理员授权、最后管理员事务保护和权限审计快照
- 角色同步、密码策略和权限矩阵组合场景，包括总部运营、系统管理员、最后管理员、自我提权及角色或密码变化后的旧会话访问
- 正确性属性 23：随机连续角色变化后员工角色、WordPress 角色、WordPress 等级和会话版本保持事务一致，分阶段失败恢复完整快照
- 正确性属性 26：总部运营和系统管理员对任意员工、门店、生命周期及管理动作保持相同的全量员工管理范围
- 批量导入批次键、逐行独立创建、部分失败、失败行重试、完成批次幂等、旧响应兼容和敏感字段排除
- 员工目录导出筛选与权限复用、流式分页、行数上限、固定 CSV 字段和公式注入防护
- 员工数据健康的重复标识、无效组织引用、角色能力差异、双向孤立身份、实时重算和敏感字段策略
- 本人档案字段投影、当前员工隔离、更正字段白名单、待处理申请幂等、后台处理冲突和事务审计
- 导入、导出、健康检查与更正申请组合场景，包括问题修复关闭、员工间数据隔离、后台越权和敏感数据边界
- 正确性属性 30：128 组固定种子批次各重放 256 次，验证同批次结果稳定、成功行不重建和跨批次员工唯一性
- 员工目录页面的视觉连续性、统一目录数据链、组合筛选、默认离职范围、桌面表格、窄屏卡片、分页、状态摘要和可测试请求状态
- 新增员工三步抽屉的字段覆盖、启用组织字典、逐步校验、脱敏摘要、冲突反馈、防重复提交和创建后详情跳转
- 员工详情抽屉的账号绑定、任职历史、业务摘要、操作与登录审计、离职只读态和高风险操作流程
- 组织工作区标签隔离、树形与列表双视图、六项组织摘要、门店与岗位设置字段、停用引用提示、写请求锁和窄屏卡片
- 批量导入工作区的 CSV/JSON 选择与拖放、UTF-8 模板、字段映射、逐行预检查、提交锁、结果展示和原批次失败重试
- 数据健康工作区的七类实时问题、分类计数、影响员工与修复上下文入口、修复后复检和窄屏布局
- 员工后台筛选、重置、分页、新增、详情、离职、恢复、受控清理、组织树、批量导入和错误重试的完整交互契约
- 员工后台抽屉初始焦点、焦点恢复、Tab 循环、Escape 关闭、键盘状态语义、可见焦点、触控目标、减少动效、窄屏共享卡片和异步状态节点

义务唯一性和日报唯一性属性测试均运行 128 组固定种子、每组 256 次混合写入，并在每一步验证日期、门店、员工和规范化岗位四元组唯一；义务测试覆盖生成任务、日报同步、岗位别名和重复执行，日报测试覆盖草稿原位更新、提交后身份稳定、岗位别名、重复保存和生产行锁。工号属性测试使用固定种子的命令生成器，运行 128 组、每组 256 次创建尝试，混合缺省工号、显式工号、重复输入和事务回滚，并在每一步检查员工档案工号唯一性。账号身份属性测试同样运行 128 组、每组 256 次绑定尝试，在每一步检查员工到账号、账号到员工的双向映射，覆盖两侧重复绑定与回滚后的身份复用。导入幂等属性测试运行 128 组、每组 256 次同批次重放，并覆盖部分失败重试和跨批次重复员工，验证正确性属性 30。员工目录字段属性测试运行 32,768 组随机字段投影和 16,384 组角色敏感值策略组合，验证未知数据库字段不会进入响应，受限角色获得脱敏手机号、用户名和邮箱。主岗唯一性、历史不可变、会话失效、角色一致性、权限矩阵和受控清理关联属性测试分别运行 128 组、每组 256 次随机变更；对应验证正确性属性 21、25、22、23、26 和 24。组织服务测试通过源码契约和内存状态模型覆盖岗位、门店、主岗、兼岗、区间边界、唯一性、负责人、启停、幂等冲突、历史任职保留和当前组织树聚合；员工编辑模型验证基础资料与组织变更的原子提交和失败回滚，离职与恢复契约测试覆盖账号、任职、会话、设备、订阅、外层事务复用和审计事务；误建清理测试覆盖关联分类、兼容状态、基线计数、管理权限、令牌作用域、防篡改与过期校验、事务锁、身份链删除计数、完整审计和失败回滚；身份一致性、密码策略、具名权限和高权限角色保护测试覆盖角色同步、会话轮换、替换令牌、权限矩阵、系统设置隔离、另一管理员授权、最后管理员保护与事务失败回滚；组合集成测试覆盖总部运营、系统管理员、最后管理员、自我提权以及角色和密码变化后的旧会话拒绝；导入测试覆盖批次并发保护、部分成功、失败行修正重试、完成批次重放、旧响应字段和敏感摘要；导出测试覆盖筛选与权限复用、流式分页、字段白名单、行数上限和公式注入防护；数据健康测试覆盖重复标识、无效引用、角色能力差异、双向孤立身份、只读重算和具名权限；本人档案测试覆盖字段投影、当前员工隔离、申请幂等、处理状态与管理权限；组合测试覆盖数据健康问题关闭、员工申请隔离、后台越权和跨服务敏感数据边界；员工目录 UI 契约测试覆盖统一 API、筛选参数、离职范围、共享记录双视图、分页摘要、请求状态和页面角色门禁；新增员工抽屉 UI 契约测试覆盖三步字段、启用字典、客户端校验、脱敏确认、冲突详情、防重和成功后刷新；员工详情 UI 契约测试覆盖可访问抽屉、离职只读、目标操作审计、日期与原因、密码复杂度、恢复重确认、清理资格和短时令牌；组织管理 UI 契约测试覆盖工作区标签、树表切换、六项摘要、字典设置字段、停用引用提示、写入防重与响应式卡片；批量导入与数据健康 UI 契约测试覆盖工作区隔离、CSV/JSON 解析、模板、双语字段映射、逐行预检查、原批次失败重试、七类问题、修复入口、复检与响应式布局；员工后台交互契约测试将筛选、分页、新增、详情、离职、恢复、清理、组织树、导入和错误重试串成完整操作链；后台响应式和无障碍契约测试覆盖三个模态抽屉的焦点进入、焦点恢复、Tab 循环与 Escape 关闭，并核对键盘状态语义、可见焦点、44px 触控目标、减少动效、760px/520px 卡片切换和异步状态节点；日报义务服务测试覆盖上海时区、周一豁免、周二至周日应交、任职边界、组织状态、员工生命周期、角色规范化、事务视图、重复任职折叠、历史角色别名和完成状态保护；历史回填测试覆盖已结束日期范围、日报组织快照、任职区间、日报覆盖、回填来源、事务幂等和管理更正状态保护；日报状态服务测试覆盖数据库时间、事务顺序、义务同步、23:59 与 00:00 边界、锁定状态保护、员工状态响应和管理更正快照；营业日义务生命周期测试覆盖完整营业周、UTC 与上海截止时间等价性、调店与转岗快照、日报义务同步失败回滚和管理更正审计失败回滚；员工锁定持续性属性测试验证截止后任意草稿与提交操作均保持锁定、重复 Worker 幂等以及管理更正授权迁移；来源策略测试验证来源登记、生产与合成分类、保存校验、经营统计过滤和来源范围响应；口径版本测试验证生效版本选择、响应与导出元数据、权限范围缓存键、统计查询审计传播和日报版本绑定。当前全量 Node 自动测试共 332 项，任务 9.1 至 9.10 及 10.1、10.2 已完成，任务 10.2 通过 6 项定向测试及全部回归测试。真实 PHP 语法、PDO 事务和 MySQL 结构需在具备 PHP/MySQL 的隔离环境执行。

岗位项目规则版本测试验证闭区间生效版本选择、同日确定性优先级、最低四个正数项目兼容、岗位必填、零值、数值范围、凭证上下限、模板响应和历史日报版本绑定。当前全量 Node 自动测试共 338 项；任务 10.3 通过 6 项定向测试、79 项工作量回归及全部 338 项回归测试。

来源、规则和跨版本集成测试将来源策略、口径版本和岗位规则版本放入同一日报模型，覆盖生产来源计入经营合计、合成来源保留审计且默认零贡献、旧四项规则、新岗位规则以及版本升级后的历史绑定稳定性。当前全量 Node 自动测试共 344 项；任务 10.4 通过 6 项定向测试、85 项工作量回归及全部 344 项回归测试。

合成来源零贡献属性测试运行 128 组固定种子、每组 256 次生产与合成来源混合写入，并在每一步核对经营合计和单条合成日报贡献；另运行 128 组纯合成来源随机数据集，验证任意值分布下默认经营合计恒为零。测试保留合成日报按来源审计可见性，并核对初始来源迁移与七个经营统计入口的统一过滤契约。当前全量 Node 自动测试共 348 项；任务 10.5 通过 4 项定向测试、89 项工作量回归及全部 348 项回归测试，任务 10 已完成。

审核任务历史测试使用内存状态模型和源码契约验证首次创建、重新提交、版本递增、前序关联、旧意见与状态保留、指标移除后的历史留存、历史任务只读、审核身份认证、统计过滤和迁移清单。任务 11.1 通过 6 项定向测试、95 项工作量回归及全部 354 项 Node 回归测试；真实 PDO 行锁、事务竞争和 MySQL 增量迁移仍需在隔离数据库复核。

三值计算器测试验证公共服务接口、全量审核的待处理与批准公式、缺失任务零贡献、日报绑定规则版本优先级、当前审核任务过滤，以及驾驶舱、总部、门店、员工明细、后台汇总和审核积压的统一接入契约。任务 11.2 通过 10 项定向测试、105 项工作量回归及全部 364 项 Node 回归测试；真实 PHP 语法、PDO 查询和 MySQL 聚合结果仍需在具备 PHP 与 MySQL 的隔离环境复核。

审核重审测试使用内存状态模型和源码契约验证 `pending` 单向审核转换、驳回终态、意见必填、已提交日报凭证授权、所有权隔离、事务锁、审核时凭证基线、岗位规则复核、版本递增、前序关联和重复重审幂等。任务 11.3 通过 9 项定向测试、114 项工作量回归及全部 373 项 Node 回归测试；真实 PHP 语法、PDO 行锁、并发事务和 MySQL 增量迁移仍需在具备 PHP 与 MySQL 的隔离环境复核。

审核状态与有效值测试使用统一内存状态模型和 PHP 源码契约，精确验证待审核值隔离、批准值生效、驳回值归零、补凭证重审、日报重新提交及管理更正后的版本链和三值结果。管理更正契约同时验证审核任务替换位于提交事务内、日志使用真实管理操作人及业务异常状态码透传。任务 11.4 通过 7 项定向测试、121 项工作量回归及全部 380 项 Node 回归测试；真实 PHP 语法、PDO 行锁和 MySQL 事务结果仍需在具备 PHP 与 MySQL 的隔离环境复核。

有效选取率分子属性测试运行 128 组固定种子、每组 256 份随机已提交日报，并在每次追加后验证有效正数日报数小于或等于原始正数日报数。测试覆盖非全量审核、全量审核、审核任务缺失、`pending`、`approved`、`rejected`、`needs_resubmit`、`superseded`、正负零和舍入边界，同时核对 PHP 与 SQL 有效值公式只产生原始值或零。任务 11.5 通过 3 项定向测试、124 项工作量回归及全部 387 项 Node 回归测试。

审核值追溯属性测试运行 128 组固定种子、每组 256 步随机提交、批准、驳回、补凭证、替换和重审操作，并在每一步验证版本连续、前序关系、当前任务唯一性、废止日志和四值映射。生产契约验证 `rejected_value` 贯穿 PHP、SQL 与聚合计算，审核列表通过最后一条废止日志恢复 `trace_status`。任务 11.6 通过 3 项定向测试、127 项工作量回归及全部 390 项 Node 回归测试；当前环境缺少 PHP 可执行文件，真实 PHP 语法、PDO 查询和 MySQL 结果需在具备 PHP 与 MySQL 的隔离环境复核。

统一统计查询服务契约测试验证全部筛选维度、来源登记与默认经营来源、员工本人/店长授权门店/总部全量权限、请求筛选与权限范围交集、当前审核任务唯一选择、参数化 SQL 和最细事实字段。任务 12.1 通过 5 项定向测试、132 项工作量回归及全部 395 项 Node 回归测试；当前环境缺少 PHP 可执行文件，真实 PHP 语法、PDO 查询、当前任职权限和 MySQL 事实结果需在具备 PHP 与 MySQL 的隔离环境复核。

统计聚合服务契约测试验证只纳入已提交日报、按项目和日报去重、样本量、五类比例、三类均值、四值总量、低样本门槛和统一口径元数据。任务 12.2 通过 4 项定向测试、136 项工作量回归及全部 399 项 Node 回归测试；当前环境缺少 PHP 可执行文件，真实 PHP 语法、PDO 查询和 MySQL 聚合结果需在具备 PHP 与 MySQL 的隔离环境复核。

统计过滤与聚合守恒测试覆盖空事实、9/10 份日报和 2/3 名员工低样本边界、九维组合筛选、默认经营来源隔离、日报历史门店与岗位快照、五类审核状态四值映射、互斥门店分区可加守恒及重复日报事实去重。任务 12.3 通过 8 项定向测试、144 项工作量回归及全部 407 项 Node 回归测试；当前环境缺少 PHP 可执行文件，真实 PHP 语法、PDO 查询和 MySQL 聚合结果需在具备 PHP 与 MySQL 的隔离环境复核。

门店应交汇总与明细守恒属性测试运行 128 组固定种子、每组 256 步随机义务新增或完成状态变化，并逐步使用日期、门店、员工、岗位和完成状态组合筛选验证正确性属性 4。测试同时覆盖公休日豁免、历史组织快照、空范围、义务唯一结构和应交计数契约。任务 12.4 通过 4 项定向测试、148 项工作量回归及全部 411 项 Node 回归测试；门店周期外部接口将在后续任务接入，真实 MySQL 查询结果需在隔离数据库复核。

完成状态计数守恒属性测试运行 128 组固定种子、每组 256 步随机义务写入或状态转换，并逐步使用日期、门店、员工和岗位组合范围验证正确性属性 5。测试覆盖五类应交完成状态、公休日豁免、空范围、草稿保存、提交、到期锁定、管理更正及生产字段和状态写入契约。任务 12.5 通过 4 项定向测试、152 项工作量回归及全部 415 项 Node 回归测试；门店周期外部接口将在后续任务接入，真实 PHP、PDO 和 MySQL 查询结果需在隔离环境复核。

项目选取率分子边界属性测试运行 128 组固定种子、每组 256 步随机混合指标事实，并在每一步验证原始正数日报数小于或等于按指标和日报 ID 去重后的已提交日报数。测试同时覆盖草稿、无效事实、重复日报、指标隔离、正负零、两位小数舍入边界、空输入及生产聚合顺序。任务 12.6 通过 3 项定向测试、155 项工作量回归及全部 418 项 Node 回归测试，任务 12 已完成；真实 PHP 聚合行为需在具备 PHP 的隔离环境复核。

门店周期完成与项目矩阵契约测试验证 GET 方法、认证上下文、统一权限范围、结构化异常、审计事件、五类义务状态守恒、经营来源排除、岗位义务分母、四值及均值、义务员工覆盖率、全部员工零值单元格、下钻令牌和按项目隔离的有效值与原始值密集排名。任务 13.1 通过 5 项定向测试、160 项工作量回归及全部 423 项 Node 回归测试；当前环境缺少 PHP 可执行文件，真实 PHP 语法、PDO 查询、MySQL 聚合和权限范围需在具备 PHP 与 MySQL 的隔离环境复核。

项目选取、覆盖和排名契约测试验证 GET 方法、认证上下文、统一事实与项目聚合复用、五类比例、四值、三类均值、低样本状态、适用项目与应交员工零值补齐、门店全部均值和前 25% 参考，以及员工门店内和全部门店同岗位双层排名。任务 13.2 通过 5 项定向测试、165 项工作量回归及全部 428 项 Node 回归测试；当前环境缺少 PHP 可执行文件，真实 PHP 语法、PDO 查询、MySQL 聚合和权限范围需在具备 PHP 与 MySQL 的隔离环境复核。

员工完整画像、趋势和排名契约测试验证 GET 方法、员工参数、认证上下文、结构化异常、审计事件、统一事实和排名复用、义务与日报合并、全部项目零值补齐、凭证与审核意见、四值、日周月趋势、周一排除、等营业日上期、过去四期均值、低样本状态及员工本人、店长授权门店和总部全量权限。任务 13.3 通过 6 项定向测试、171 项工作量回归及全部 434 项 Node 回归测试；当前环境缺少 PHP 可执行文件，真实 PHP 语法、PDO 查询、MySQL 聚合、历史门店权限和周期边界需在具备 PHP 与 MySQL 的隔离环境复核。

经营漏斗契约测试验证 GET 方法、认证上下文、统一事实与项目聚合复用、关系版本闭区间选择、销售五阶段四值、三段销售转化率、耗课与沟通计划完成率、分子分母样本、待审核标志及 `comparable|new|empty` 零分母状态。新增迁移已纳入迁移清单和幂等回归。任务 13.4 通过 6 项定向测试、177 项工作量回归及全部 440 项 Node 回归测试；当前环境缺少 PHP 可执行文件，真实 PHP 语法、PDO 查询、MySQL 迁移和关系版本边界需在具备 PHP 与 MySQL 的隔离环境复核。

门店、项目、员工和漏斗集成测试使用同一内存事实集验证不同门店规模下的应交、完成、项目与员工汇总守恒，历史任职门店的义务和日报组织快照，`pending`、`approved`、`rejected`、`needs_resubmit` 与 `not_required` 审核状态的四值一致性，筛选后的低样本重算，以及按统计截止日选择关系版本。源码契约同时验证四个统计服务共享统一查询和聚合内核。任务 13.5 通过 6 项定向测试、183 项工作量回归及全部 446 项 Node 回归测试，任务 13 已完成；真实 PHP 语法、PDO 查询、MySQL 聚合和关系版本边界需在具备 PHP 与 MySQL 的隔离环境复核。

营业日周期解析测试覆盖日、周二至周日业务周、月累计、完整月、季度和自定义周期，验证周一排除、周一锚点业务周归属、闰月、跨月与跨季度边界、自定义周期校验，以及完整自然周期与等营业日比较窗口的双周期输出。任务 14.1 通过 6 项定向测试、189 项工作量回归及全部 452 项 Node 回归测试；当前环境缺少 PHP 可执行文件，真实 PHP 语法和日期边界需在具备 PHP 的隔离环境复核。

通用环比与基准测试覆盖正上期变化率、零上期的 `new|flat`、下降到零、两期样本与低样本传播、均值和前 25% 参考值，并验证员工画像与项目排名复用同一服务。任务 14.2 通过 6 项定向测试、195 项工作量回归及全部 458 项 Node 回归测试；当前环境缺少 PHP 可执行文件，真实 PHP 语法和运行时类型行为需在具备 PHP 的隔离环境复核。

通用交叉分析契约测试覆盖 GET 与认证、四类主次维度、四类时间粒度、营业日周期解析、岗位项目零值补齐、四值、应交与完成人日、完成率、义务员工覆盖率、选取率、每应交人日均值、样本、低样本、密集排名和最细事实下钻参数。任务 14.3 通过 6 项定向测试、201 项工作量回归及全部 464 项 Node 回归测试；当前环境缺少 PHP 可执行文件，真实 PHP 语法、PDO 查询和 MySQL 聚合结果需在具备 PHP 与 MySQL 的隔离环境复核。

周期与交叉聚合集成测试覆盖周一排除、跨月和跨季度等长窗口、两期低样本传播、上期为零的 `new|flat`、历史门店快照和互斥门店员工单元守恒。任务 14.4 通过 6 项定向测试、207 项工作量回归及全部 470 项 Node 回归测试；当前环境缺少 PHP 可执行文件，真实 PHP 语法、PDO 查询和 MySQL 聚合结果需在具备 PHP 与 MySQL 的隔离环境复核。

正确性属性 16 使用 128 个固定种子、每个 256 个随机周期场景，验证六种周期的本期与上期比较窗口拥有相同营业日数量，并覆盖周一、闰年、跨月和跨季度边界。任务 14.5 通过 1 项属性测试、208 项工作量回归及全部 471 项 Node 回归测试；当前环境缺少 PHP 可执行文件，真实 PHP 日期实现需在具备 PHP 的隔离环境复核。

正确性属性 17 使用 128 个固定种子数据集，遍历门店、项目、员工和时间的 12 个互异主次维度组合，验证交叉矩阵四值汇总等于最细事实记录聚合值，并覆盖重复事实键去重。任务 14.6 通过 1 项属性测试、209 项工作量回归及全部 472 项 Node 回归测试；当前环境缺少 PHP 可执行文件，真实 PHP 聚合、PDO 查询和 MySQL 结果需在具备 PHP 与 MySQL 的隔离环境复核。

任务 15.1 新增 `WorkloadPermissionScopeService`，并由 `WorkloadAnalyticsQueryService` 统一调用。权限定向测试覆盖本人、授权门店、全量范围、排名可见范围和系统管理员配置能力；全量 Node 回归为 473/473。当前环境缺少 PHP 可执行文件，真实任职授权查询和权限中间层运行时需在具备 PHP 与 MySQL 的隔离环境复核。

任务 15.2 新增 `metric-detail.php` 明细下钻接口。统计、排名、交叉分析和明细查询均复用 `facts()` 的权限过滤，导出权限使用 `permission_scope.can_export` 契约；任务定向测试、工作量回归和全量 Node 回归基线分别为 1、211/211 和 474/474。

任务 15.3 新增 `workload_permission_matrix.integration.test.mjs`，覆盖员工本人、店长单店、多店授权、总部运营、系统管理员、导出契约和历史门店快照。任务通过 2 项定向测试、213 项工作量回归及全量 Node 回归。

任务 15.4 使用 128 个固定种子场景验证 `self`、`stores` 和 `all` 三种权限范围不会暴露矩阵外事实，并校验导出能力契约。属性测试通过后，全量 Node 回归基线为 477/477。

任务 17.1 新增门店周期完成和项目选取度同步 CSV 导出。CSV 使用 UTF-8 BOM，包含口径元数据与字段说明，对公式前缀进行转义；任务通过 4 项定向测试、218 项工作量回归和全部 481 项 Node 回归测试。当前环境缺少 PHP 可执行文件，真实 CSV 流输出、PDO 查询和响应头需在具备 PHP 与 MySQL 的隔离环境复核。

任务 17.2 扩展导出入口支持个人全数据和项目全维度 CSV，复用事实查询并保留审核、凭证和原始/待审核/有效/拒绝值字段。任务通过 5 项定向测试、219 项工作量回归及全部 482 项 Node 回归测试；当前环境缺少 PHP 可执行文件，真实 CSV 流输出和数据库查询需在具备 PHP 与 MySQL 的隔离环境复核。

任务 17.3 新增大结果导出任务、CLI worker、状态查询和受控下载。20,000 行以上进入任务，生成、状态查询和下载均校验发起人及权限范围；任务通过 3 项定向测试、222 项工作量回归及全部 485 项 Node 回归测试。当前环境缺少 PHP 可执行文件，任务行锁、文件生成和下载响应需在具备 PHP 与 MySQL 的隔离环境复核。

任务 17.4 至 22.4 已完成导出边界与权限属性、预警建议、员工双端、工作量后台、小程序提审、企业微信停用、权限感知缓存、流式导出和统一质量门禁。最终审计修复跨范围请求竞态、异步导出下载、店长员工范围、上传进度和更正弹窗焦点问题后，全量自动回归共 534 项，534 项通过、0 项跳过、0 项失败。任务 28.1 再次执行完整门禁，完成 144 个 PHP 文件语法检查并通过 106 个 Node 测试文件。小程序路由检查覆盖 31 个注册页面与 64 处固定路由，发布代码检查覆盖 66 个源码文件，上传检查验证页面、应用层和 API 工具三段封装。迁移状态命令已验证数据库密码缺失时立即停止。生产验收仍需在带真实基线副本的隔离 MySQL 环境执行迁移、PDO 行锁和真实流式响应测试，并在微信后台及 iOS/Android 真机完成域名、隐私和上传检查。

完成状态互斥属性测试运行 128 组固定种子、每组 256 次随机状态转换，并在每一步验证草稿、已提交和缺交状态互斥。测试覆盖义务生成、草稿保存、提交、到期锁定、管理更正、提交后防降级，以及单一 `completion_status` 字段的生产契约。

周一免交属性测试运行 128 组固定种子、每组 256 个跨年份随机周一及随机任职候选，持续验证应交义务数为零且不存在 `required` 记录。测试同时覆盖候选豁免标记、重复执行、上海业务日期、Worker 调用和应交数汇总生产契约。

营业日义务计数属性测试运行 128 组固定种子、每组 256 个跨年份周二至周日场景，逐次验证应交义务数等于按日期、门店、员工和规范化岗位去重后的在职适用任职候选数。测试覆盖任职起止边界、离职日、账号与组织启停、销售和教练岗位、角色别名、重复任职、跨店职责及生产候选计数契约。

仓库中的其他 PHP 测试脚本可能访问本机 API 或业务数据库。执行前先阅读脚本，确认目标地址、账号来源和写数据范围。

## 小程序开发

- 主要工程位于 `real_sync/mini-program/`。
- 请求统一通过 `utils/api.js`，认证状态通过 `utils/auth.js` 管理。
- API 使用 `/api` 前缀和 Bearer JWT。
- 页面新增后同步维护 `app.json` 路由。
- 登录、日报、上传和后台回显需要真机端到端验收。
- request、uploadFile、downloadFile 和 web-view 域名需在微信后台配置。

`real_sync/mobile/` 还保留一套历史移动端和小程序相关源码。修改前依据实际发布工程确认目标路径。

## 部署与回滚

生产变更遵循以下顺序：

1. 对照线上目录和数据库只读预检。
2. 备份本批次涉及的文件、表结构和数据。
3. 在独立窗口执行单个增量变更。
4. 核验结构、行数、关键接口和旧链路。
5. 记录执行时间、操作者、结果和回滚点。

企业微信数据库变更的历史检查清单位于 `.monkeycode/docs/wecom-backup-and-rollback-checklist-2026-06-24.md`，可复用其预检、备份和核验结构。

## 已知工程约束

- 数据库 DDL 仍分散于版本化 SQL 和部分 API 运行时初始化代码。
- 仓库包含历史入口和两套移动端源码，发布目标需基于实际部署清单确认。
- 部分脚本包含固定生产域名或绝对路径，跨环境运行前需核对。
- 完整 PHP/MySQL 集成测试依赖具备真实 schema 的隔离测试库。
