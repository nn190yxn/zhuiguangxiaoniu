# API 与数据平台

该模块为 Web 和小程序提供业务接口、认证授权、数据访问、异步任务、审计与 migration 能力。

## 结构

- `api/config.php`：环境配置、PDO、JWT 与基础请求工具。
- `api/kernel/`：API 核心基础设施。
- `api/platform/`：作业、同步、文件、AI、outbox 与健康能力。
- `api/{domain}/`：按工作量、学习、知识、考试、演练等业务域拆分的端点。
- `api/lesson-submissions/`：教案上传、Office 解析、结构化版本、ACE 规则检查和已发布知识卡优化。
- `api/lesson-library/`：正式教案列表、批准版本详情、稳定规范路由查询和归档事务。
- `api/knowledge/EmployeeKnowledgeVisibilityQuery.php`：员工知识发现面的统一当前版本 SQL 数据源。
- `database/knowledge_taxonomy_mapping.v1.json`：员工知识双主线、稳定子分类和导入领域映射的版本化数据源。
- `smart-lessons-api.php`：兼容现有智能教案页面的聚合生成接口，按已发布知识卡、静态周教案、ACE 默认模板的顺序生成月计划、周计划和单节课样稿。
- `database/migrations/`：版本化 SQL migration。
- `database/migration_manifest.php`：migration 预期结构清单。

## 依赖

- MySQL 数据库。
- PHP PDO MySQL 扩展。
- 环境变量提供数据库密码和 JWT secret。
- WordPress 兼容用户表用于部分身份查询。

## 约定

- 受保护接口验证 JWT 和服务端权限。
- 写请求按业务需要执行幂等和状态版本检查。
- 教案提交页使用共享身份适配器生成只读作者显示，创建端点以认证上下文中的 `staff_id` 绑定主记录作者和创建人；客户端显示字段只承担版本元数据展示。
- 员工知识发现面使用共享 SQL 数据源，知识列表、详情、相关内容、全局搜索、教案知识建议和兼容智能教案入口统一要求主记录启用且已发布、当前版本归属同卡并处于 active 状态；相关内容返回当前版本 ID，并以版本表中的标题、摘要、内容类型和领域字段为优先值；管理审核与历史快照使用独立查询边界。
- 分类映射源通过 `active_mapping_version` 指向唯一激活版本。`KnowledgeTaxonomy` 校验该版本及所有领域目标，向列表筛选和分类清单提供主线、子分类、domain mapping 与版本号。`taxonomy-2026-09-04-v1` 覆盖专业知识与销售知识两条主线，将二期知识包八个 domain code 映射到有效的专业知识子分类，并通过 `content_type_review_baselines` 定义七类导入内容的复核目标。分类审核生成器逐卡比较领域映射目标与内容类型基线，输出仓库过渡分类、映射缺口和人工确认队列；生产数据库状态在该报告中独立标记为未评估。
- 知识卡只读发布门禁组合隔离包、分类审核报告和目标环境 evidence，分别验证 1417 条记录、零过渡分类、完整映射、1417 个 active current version、1417 条审核记录和 1417 条员工可见记录。目标 evidence 同时绑定 `taxonomy_mapping_version`，用于识别数据库分类口径与当前激活版本的偏差。
- 积分兑换和每日签到通过独立服务类执行事务 SQL，统一幂等执行器负责事务、并发唯一键和首次响应快照；考试提交、教案创建与教案导出复用相同模式。
- 教学主管终审在批准事务中固定批准版本和正式库发布状态。正式库列表与详情共同要求主记录已批准且已发布，并只关联同教案的已提交、不可变 `approved_version_id`；详情保持批准版本读取边界。`lesson-library.html` 通过对应列表与详情接口呈现正式库，并由学习中心提供规范入口。归档事务使用主管权限和状态版本锁，将主记录及正式库状态切换为 `archived`，保留批准版本、发布历史及审核和导出关系，并新增归档审计记录。`PlatformBusinessDomainRegistry` 的 `lesson_review` 域登记正式库页面和脚本消费者，并让同域端点共享批准版本发布、正式库读取和规范路由能力声明。
- 管理操作记录审计信息。
- 数据结构变更通过新增 migration 推进。
- `ExpandMigrateContractValidator` 将字段修改、数据更新、数据插入、状态回填和潜在表重写输出为结构化风险，供 catalog 兼容声明和发布审批使用。
- 风险 migration 的 `compatibility.risk_declaration` 包含兼容窗口、写适配器、预计影响行数、锁风险、执行策略和恢复方案；声明缺失时 compatibility 门禁按风险逐项阻断。
- `202609040002` 对 `lesson_suggestions` 声明专用兼容计划：N/N-1 读取端可忽略新增知识版本字段，N-1 写入端可暂留空值，预检按未固定版本的知识卡建议精确计数；字段修改可能持有 metadata lock，数据回填只锁定匹配行，异常归属通过新增 forward-fix migration 修复。
- migration dry-run 使用 `information_schema` 只读判断历史表状态，并比较执行前后的结构和行数快照；首次 dry-run 不创建历史表或写入迁移状态。
- `scripts/migration_mysql.integration.php` 将 68 个 migration 放入专用空 MySQL 数据库完整回放。受版本控制的 baseline 包含组织、工作量、知识与教案版本、积分和学习进度旧数据；执行结果包含 dry-run 指纹、首次 apply、verify/readiness、关键回填、复合外键及 replay 计数，可进入 release evidence。
