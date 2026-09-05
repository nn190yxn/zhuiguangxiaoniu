# 开发者指南

## 项目目的

本项目同时维护公开品牌官网和追光小牛内部数字化运营平台。开发时应先确认改动属于公开内容、员工端、管理端、PHP API、小程序、云适配或数据库 migration 中的哪一层。

## 前置条件

- Python 3：静态官网本地预览。
- PHP CLI 与 PDO MySQL：PHP API 和数据库脚本。
- Node.js：`.test.mjs` 自动化测试与云函数本地验证。
- 微信开发者工具：小程序真机和开发者工具验证。
- MySQL：需要执行 API 集成或 migration 时使用。

## 静态官网预览

在仓库根目录运行：

```bash
# 启动静态官网预览
python3 -m http.server 8001 --directory /workspace/real_sync
```

至少检查：首页 `/`、资讯索引 `/news/`、本次文章页和引用的 CSS、图片资源均返回 HTTP 200。

## 公开文章开发

1. 在 `news/` 创建稳定 slug 的静态 HTML。
2. 配置唯一 title、description、canonical、Open Graph 和发布日期。
3. 配置 Article、FAQPage、BreadcrumbList JSON-LD。
4. 确保 FAQPage 与可见 FAQ 完全一致。
5. 在 `news/index.html` 增加专题或归档入口。
6. 在需要时更新 `index.html` 代表入口。
7. 在 `sitemap.xml` 增加规范 URL 与 lastmod。
8. 验证内部链接、JSON-LD、XML、事实口径和 HTTP 200。

公共文章样式位于 `assets/public-insights.css`。新文章应优先复用现有组件类，保持移动端单列布局。

## 员工运营中枢视觉开发

员工内部页面通过 `internal-auth.js` 自动加载 `assets/internal-ops.css` 并创建共享页面壳。新增内部页面应复用该脚本，保留页面自己的业务 DOM 与事件处理；共享层负责六中心导航、身份区、当前态、桌面侧栏和移动横向导航。

页面壳改动使用以下命令验证：

```bash
# 检查页面壳、入口、认证恢复和移动端应用壳
node --test scripts/internal_ops_shell_contract.test.mjs scripts/internal_entry_contract.test.mjs scripts/internal_auth_pwa_session.test.mjs scripts/mobile_pwa_shell.test.mjs

# 检查认证脚本语法
node --check internal-auth.js
```

页面壳使用 `#mcOpsShell` 保证唯一实例，当前导航同时设置 `.current` 与 `aria-current="page"`。修改导航匹配时应分别验证 `/internal.html` 与 `/internal.html#tools`，确保只有一个入口处于当前状态。

制度、知识、演练、学习和个人中心的通用表面由页面路径对应的 `mc-ops-center--{center}` 类限定。修改中心映射或共享卡片、筛选、列表、状态和移动底栏样式时，运行 `internal_ops_shell_contract.test.mjs`，并补跑 `knowledge_desktop_page.test.mjs`、`internal_knowledge_p1_contract.test.mjs`、`drill_mobile_pwa.test.mjs` 和 `mobile_pwa_accessibility.test.mjs`。

员工工作台结构或样式改动还应运行 `node --test scripts/internal_ops_dashboard_contract.test.mjs`。该测试验证四项内容摘要、六中心规范路径、登录与搜索 ID、管理员显示逻辑以及桌面、平板和手机布局边界。

知识详情、教案编辑、教案审核、管理仪表盘或员工管理页面的共享视觉改动应运行 `node --test scripts/internal_ops_complex_pages_contract.test.mjs scripts/lesson_submission_editor_contract.test.mjs scripts/lesson_review_page_contract.test.mjs scripts/staff_admin_accessibility.test.mjs scripts/staff_admin_interactions.test.mjs`。复杂页面样式通过稳定的 `internal-*` body 类限定，修改时应保留原有表单 ID、对话框语义、权限入口和事件处理。

## API 配置

`api/config.php` 从环境变量读取数据库、JWT 和 API 配置；PHP-FPM 环境也支持本地配置文件。仓库只提供 `api/.env.local.php.example` 示例。

| 变量 | 必需 | 用途 |
|------|------|------|
| `DB_HOST` | 是 | MySQL 主机 |
| `DB_NAME` | 是 | 数据库名 |
| `DB_USER` | 是 | 数据库用户 |
| `DB_PASSWORD` | 是 | 数据库密码 |
| `DB_CHARSET` | 否 | 默认 `utf8mb4` |
| `JWT_SECRET` | 是 | JWT 签名 |
| `ALLOWED_ORIGINS` | 否 | CORS 允许来源 |
| `API_BASE_URL` | 否 | API 基础 URL |

真实密钥应由运行环境提供，禁止提交到仓库。

## 自动化测试

仓库测试脚本位于 `scripts/`，主要使用 Node.js 原生测试风格和 PHP CLI。按改动域选择最小相关测试集：

知识中心双主线、桌面列表、入口和静态来源改动使用 `node --test scripts/knowledge_desktop_page.test.mjs scripts/knowledge_taxonomy_contract.test.mjs scripts/content_source_index_contract.test.mjs scripts/internal_entry_contract.test.mjs scripts/internal_knowledge_visibility_contract.test.mjs`，并运行相关 JavaScript 语法和 PHP 文件的 `php -l` 检查。员工知识 SQL 可见性组件或消费者改动还需运行 `node --test scripts/knowledge_version_consistency.property.test.mjs scripts/knowledge_visibility_query.test.mjs scripts/internal_knowledge_visibility_contract.test.mjs scripts/lesson_knowledge_matcher.test.mjs scripts/global_search_contract.test.mjs scripts/content_source_index_contract.test.mjs scripts/smart_lesson_source_contract.test.mjs scripts/knowledge_access_boundary.test.mjs`，验证启用、发布、current version 归属、active 状态、安全别名、五类发现面接入、详情相关内容版本字段和搜索版本输出合同。版本一致性属性测试使用 256 个固定种子生成 4096 条知识主记录及其多版本组合，并通过 SQLite 实际执行共享 SQL。

知识分类映射源及其消费者改动使用 `node --test scripts/knowledge_taxonomy_mapping.property.test.mjs scripts/knowledge_card_classification_report.test.mjs scripts/knowledge_taxonomy_mapping_contract.test.mjs scripts/knowledge_taxonomy_contract.test.mjs scripts/knowledge_card_contract.test.mjs scripts/knowledge_card_package.test.mjs scripts/knowledge_card_release_gate.test.mjs scripts/unified_release_gate.test.mjs`。映射合同验证唯一激活版本、员工端双主线、稳定子分类、导入包八个 domain code 的精确覆盖、七类内容复核基线、每个映射目标的有效性，以及 PHP 分类与筛选、Python 导入和审核报告、只读发布门禁共同使用 `taxonomy-2026-09-04-v1`。属性测试使用 256 组固定种子验证 domain 大小写和空白归一化，使用 24 组顺序扰动验证审核报告语义稳定，并逐条检查正式 1417 张卡的映射覆盖。正式源测试需要设置 `KNOWLEDGE_SOURCE_ROOT`。

1417 张知识卡上线前运行 `php scripts/knowledge_card_release_gate.php database/import_data/knowledge-cards-phase2.isolated-package.json 1417 database/import_data/knowledge-cards-phase2.taxonomy-review-report.json <release-evidence.json>`。该命令只读输出 `knowledge-card-release-gate.v2`，分别报告记录数、过渡分类、映射完整性、current version、审核记录和员工可见数量；任一检查失败时返回非零状态。目标环境参数也支持直接提供 `knowledge_database` JSON。当前自动分类已覆盖全部卡片，缺失适龄字段统一标注为“全年龄段，需教练现场评估”；角色、阶段和难度已写入导入包，无法验证的关联内容保留为空数组。真实 MySQL dry-run 通过业务验收后再执行 apply 流程。

分类人工审核前运行 `python3 scripts/build_knowledge_card_classification_report.py --output database/import_data/knowledge-cards-phase2.taxonomy-review-report.json`。输出固定排序且绑定输入文件摘要；当前基线应为 1417 条过渡分类、1417 条待审核、1404 条内容类型与领域映射差异、0 条映射缺口。该报告只评估仓库隔离包，生产数据库状态保持 `not_evaluated`。

统一上线前运行 `node scripts/unified_release_gate.mjs`。该门禁汇总页面契约、搜索和管理 API、1417 张知识卡仓库包、治理元数据、领域映射、相关契约测试和知识卡只读发布门禁，并将仓库知识包状态与集成数据库知识状态分开报告。机器可读 evidence 遵循 `scripts/release-evidence.schema.json`，通过 `--evidence <path>` 或 `RELEASE_EVIDENCE_PATH` 传入；`knowledge_database` 需包含员工可见数量、current version 数量、审核记录数量和 taxonomy mapping 版本，员工可见数量必须与目标知识总量相等。门禁会重新计算 evidence 中 `verified_files` 的 SHA-256 摘要，并比较完整 migration 文件集合和证据有效时间。

完成 MySQL、页面预览和四类角色浏览器验收后，使用 `node scripts/unified_release_gate.mjs --evidence <path> --integration-verified` 执行最终检查。调用标志、当前代码摘要、数据库验证和浏览器验证均通过时，`integration_verified` 才为 `true`；全部必需检查和附加检查通过时，`ready_for_release` 才为 `true`。门禁输出中的 `release_artifact` 是机器可读汇总，包含测试总数、migration 结果、知识数量、角色覆盖、静态资源发布号和逐项检查明细。

全局搜索改动使用 `node --test scripts/global_search_contract.test.mjs scripts/content_source_index_contract.test.mjs scripts/migration_readiness.test.mjs`，并运行 `php -l api/search/search-service.php`、`php -l api/search/global.php` 和 `php -l api/admin/search/no-results.php`。新增搜索日志 migration 后需同步更新 `database/migration_manifest.php` 与 `database/migration_catalog.php` 的校验和。

知识管理改动使用 `node --test scripts/knowledge_admin_contract.test.mjs scripts/migration_readiness.test.mjs`，并检查 `api/admin/knowledge/index.php` 与 `api/admin/services/KnowledgeOperationService.php` 的 PHP 语法。批次发布和关系审核必须提供原因或说明，以便保留审计记录。

```bash
# 运行单个 Node.js 测试
node --test scripts/miniprogram_api_client.test.mjs

# 验证设备会话刷新方法与业务矩阵一致
node --test scripts/platform_miniprogram_device_session.test.mjs scripts/miniprogram_api_client.test.mjs scripts/miniprogram_business_domain_matrix.test.mjs scripts/miniprogram_api_proxy.test.mjs

# 运行小程序静态契约检查
node --test scripts/miniprogram_static_contract.test.mjs

# 运行 PHP 自由演练文本回放
php scripts/free_practice_text_replay.test.php

# 检查 migration readiness
node --test scripts/migration_readiness.test.mjs
```

生产发布门禁和大范围回归具有单独脚本，应先阅读对应 `.mjs` 与配置，再在具备所需环境时执行。教案 Office 解析回归使用 `node --test scripts/lesson_office_parser_property.test.mjs scripts/lesson_parse_fallback_contract.test.mjs scripts/lesson_submission_upload_contract.test.mjs scripts/lesson_workbook_parser_contract.test.mjs scripts/lesson_word_parser_contract.test.mjs`，同时检查相关 PHP 文件语法。
教案数据库约束和状态转换属性测试使用 `node --test scripts/lesson_database_state.property.test.mjs`，覆盖冻结审核版本、两级状态转换、批准版本一致性、退回留痕、导出版本绑定、跨教案版本约束、知识卡版本归属和解析失败回退。
教案版本服务契约使用 `node --test scripts/lesson_draft_version_contract.test.mjs`。
教案 ACE 规则检查使用 `node --test scripts/lesson_ace_rule_checker.test.mjs`，覆盖缺项定位、完整教案、时间超限、高风险保护措施、异常数组输入和接口契约。
教案知识卡优化使用 `node --test scripts/lesson_knowledge_matcher.test.mjs`，覆盖发布边界、active 当前版本读取、多维匹配、教案及知识卡双版本绑定、重复推荐控制、接口和代理矩阵契约。
教案建议与版本属性测试使用 `node --test scripts/lesson_suggestion_version.property.test.mjs`，覆盖建议版本隔离、采纳或忽略决定留痕、重复优化决定保护，以及提交和审核状态锁定。
教案上传与结构化编辑页使用 `node --test scripts/lesson_submission_editor_contract.test.mjs scripts/lesson_submission_identity_contract.test.mjs`，覆盖生产解析接线、完整 ACE 字段、响应式页面、接口串联、只读边界、共享身份作者显示、服务端 staff ownership 和三个员工入口。
任务 4.2 建议交互也使用 `lesson_submission_editor_contract.test.mjs` 验证采纳、忽略、当前版本约束、草稿版本创建和版本差异展示。
教案标准 Office 导出使用 `node --test scripts/lesson_export_contract.test.mjs`，同时检查导出服务和接口的 PHP 语法。
教案提交审核使用 `node --test scripts/lesson_submission_submit_contract.test.mjs`，同时检查 `api/lesson-submissions/LessonSubmissionReviewService.php` 和 `api/lesson-submissions/submit.php` 的 PHP 语法。
审核任务列表和详情使用 `node --test scripts/lesson_review_query_contract.test.mjs`，同时检查 `api/lesson-reviews/LessonReviewQueryService.php` 和 `api/lesson-reviews/list.php` 的 PHP 语法。
审核通过和退回状态机使用 `node --test scripts/lesson_review_decision_contract.test.mjs`，同时检查 `api/lesson-reviews/LessonReviewDecisionService.php` 和 `api/lesson-reviews/decision.php` 的 PHP 语法。
两级审核工作台使用 `node --test scripts/lesson_review_page_contract.test.mjs`，覆盖任务筛选、冻结版本、原始文件、优化建议、版本与审核历史、意见填写和审核决策接口串联。
审核流程属性与权限回归使用 `node --test scripts/lesson_review_flow.property.test.mjs`，覆盖两级通过、两级退回、并发与重复处理、角色阶段权限、批准版本一致性和审核留痕。
正式教案库查询、页面、归档或业务域能力声明改动使用 `node --test scripts/platform_business_domain_migration.test.mjs scripts/lesson_library_api_contract.test.mjs scripts/lesson_library_page_contract.test.mjs scripts/lesson_archive_contract.test.mjs`、`php scripts/lesson_library_query_service.test.php`、`php scripts/lesson_archive_service.test.php` 和 `node --check js/lesson-library.js`。业务域契约验证正式库消费者文件及批准版本发布、正式库读取和规范路由能力；API 契约验证端点认证、migration readiness、批准版本 SQL、规范路由及主管归档权限；页面契约验证列表、详情、筛选、分页、页面状态及学习中心入口；SQLite 测试执行列表隔离、批准版本读取、归档状态迁移、状态版本冲突及历史引用保留。
教案生产服务全生命周期使用 `node --test scripts/lesson_lifecycle_e2e.test.mjs`。测试自动创建临时 SQLite 数据库、私有文件目录和最小 DOCX，通过受控 PHP HTTP 服务执行真实 multipart 上传，再依次执行解析、编辑、提交、店长审核、教学主管批准、正式库读取和归档；运行环境需要 PHP CLI、`pdo_sqlite`、`zip`、`simplexml`、`fileinfo` 与 `mbstring`。
教学主管批准版本与正式库版本一致性使用 `node --test scripts/lesson_approved_version_consistency.property.test.mjs`。Node 测试调用 PHP SQLite harness 生成 256 个确定性样本，检查终审任务、终审响应、主记录、正式库列表和详情的批准版本 ID，并通过批准后切换当前草稿验证正式库读取边界。
教案全链路检查点使用 `node --test scripts/*lesson*.test.mjs scripts/platform_idempotency_contract.test.mjs scripts/platform_idempotency.property.test.mjs`，统一验证教案权限、版本、创建与导出幂等、两级审核、正式库、归档和 Office 解析导出。检查点还需对 `api/lesson-*/*.php` 执行 `php -l`，运行 `php scripts/lesson_library_query_service.test.php`、`php scripts/lesson_archive_service.test.php` 和 `node --check js/lesson-library.js`。
旧智能教案资料源使用 `node --test scripts/smart_lesson_source_contract.test.mjs`，覆盖静态教案接管、完整默认模板降级、2 至 8 周输出、异常输入、当前知识版本查询和页面响应保护。

五类关键副作用的真实 MySQL 验证使用 `scripts/critical_side_effects_mysql.integration.test.mjs`。测试要求 `TEST_DB_HOST`、`TEST_DB_PORT`、`TEST_DB_NAME`、`TEST_DB_USER` 和 `TEST_DB_PASSWORD`，其中数据库名称必须包含 `test`、`staging`、`stage` 或 `qa`。测试会在专用数据库中创建最小表结构，以两个独立连接并发执行积分兑换、每日签到、考试提交、教案创建和教案导出，再执行一次完成后重试；每类操作必须只保留一个业务结果。

```bash
# 在专用测试数据库运行五类副作用集成测试
TEST_DB_HOST=<host> TEST_DB_PORT=3306 TEST_DB_NAME=<dedicated_test_database> TEST_DB_USER=<user> TEST_DB_PASSWORD=<password> node --test scripts/critical_side_effects_mysql.integration.test.mjs
```

## 数据库 migration

- migration 文件位于 `database/migrations/`，名称采用时间戳前缀。
- `database/migration_manifest.php` 描述预期表、字段和索引。
- `database/MigrationRunner.php`、`MigrationReadiness.php` 和测试脚本负责执行与校验。
- `php scripts/migrate.php compatibility` 的 `risks` 字段列出 `modify_column`、`data_update`、`data_insert`、`state_backfill` 和 `table_rewrite`，并提供 migration 版本、目标和 SQL 语句序号。
- 存在上述风险的 catalog 项必须在 `compatibility.risk_declaration` 声明 `compatibility_window`、`write_adapter`、`estimated_affected_rows`、`lock_risk`、`execution_strategy`，并至少提供 `rollback_plan` 或 `forward_fix`；缺项会阻断 compatibility 检查。
- `202609040002` 使用 `SELECT COUNT(*) FROM lesson_suggestions WHERE source_type = 'knowledge_card' AND knowledge_version_id IS NULL` 作为精确回填量预检。执行前验证 catalog 中两项教案与知识版本归属检查，在批准窗口完成可能重建 `lesson_suggestions` 的字段修改和限定行回填；失败数据通过后续 additive forward-fix migration 修复后再重试外键约束。
- `php scripts/migrate.php apply --dry-run` 只读取历史表、结构和行数，返回 `history_table_state`、前后快照及差异；历史表缺失时不会创建 `schema_migrations`。
- `node --test scripts/migration_compatibility.test.mjs scripts/migration_runner.test.mjs` 验证 SQL 风险分类和 dry-run 零变化。分类测试固定运行 24 组语句顺序、大小写、注释和标识符引用变体；Property 5 固定运行 18 组表、索引、行值与历史表状态组合，并比较完整 SQLite schema、逐行数据和 runner 快照。
- `scripts/migration_mysql.integration.test.mjs` 固定临时数据库回放合同。真实回放要求 `TEST_DB_NAME` 匹配 `mc_migration_test_[a-z0-9_]+`、`TEST_DB_CONFIRM=ALLOW_MIGRATION_HARNESS`，且目标库初始为空；harness 从包含历史课程依赖的 `scripts/fixtures/migration-mysql/baseline.sql` 开始，依次执行 dry-run 指纹比较、apply、verify/readiness、关键数据与外键断言和二次 apply。历史版本 `202607240001`、`202607240009`、`202608020002` 和 `202608100001` 的 MariaDB 兼容问题由 runner 在执行时按版本适配，原 SQL checksum 保持稳定。
- 已进入共享环境的 migration 保持不可变；新增变更使用新的 migration。
- 执行真实数据库 migration 前确认目标环境、备份和变更窗口。

```bash
# 在专用空数据库运行完整 migration 回放
TEST_DB_HOST=<host> TEST_DB_PORT=3306 TEST_DB_NAME=mc_migration_test_<run_id> TEST_DB_USER=<user> TEST_DB_PASSWORD=<password> TEST_DB_CONFIRM=ALLOW_MIGRATION_HARNESS node --test scripts/migration_mysql.integration.test.mjs
```

## 小程序开发

- 页面注册：`mini-program/app.json`。
- API 客户端：`mini-program/utils/api.js`。
- transport：`mini-program/utils/transports/`。
- 业务域与路由契约：`mini-program/business-domain-matrix.json`。
- 云配置：`mini-program/config/cloud.js`。

新增小程序接口时，应同步业务域矩阵、页面调用、云函数白名单和契约测试。写请求应携带幂等键；涉及状态并发时携带状态版本。
业务域矩阵的每个 endpoint 只允许一条规范化 method-path 登记；修改路由时运行 `node --test scripts/miniprogram_api_proxy.test.mjs scripts/miniprogram_business_domain_matrix.test.mjs` 检查源矩阵、部署矩阵和代理注册的一致性及唯一性。
endpoint 规范化工具使用 `node --test scripts/miniprogram_endpoint_contract.test.mjs` 验证 method、path、action query 排序、左右差集和重复键；后续矩阵同步检查应复用 `scripts/miniprogram_endpoint_contract.mjs`。
小程序完整契约检查会扫描客户端静态 PHP 调用，并比较源矩阵、部署矩阵和认证代理白名单的 endpoint 差集；缺失登记会输出调用文件和行号；运行 `node scripts/check_miniprogram_contracts.mjs` 或 `node --test scripts/miniprogram_static_contract.test.mjs`。

受保护页面清单位于 `.monkeycode/page-inventory.json`，覆盖培训卡片工作区、静态教案和工作量页面，记录页面状态、所属中心、规范入口及统一资源发布号。清单完整性检查使用 `node --test scripts/page_inventory_contract.test.mjs`。
页面与小程序覆盖检查使用 `node --test scripts/miniprogram_endpoint_contract.test.mjs scripts/miniprogram_static_contract.test.mjs scripts/miniprogram_api_proxy.test.mjs scripts/miniprogram_business_domain_matrix.test.mjs scripts/page_inventory_contract.test.mjs scripts/internal_auth_navigation_contract.test.mjs scripts/internal_auth_pwa_session.test.mjs scripts/internal_entry_contract.test.mjs`，并补充 `node --check`、`php -l` 和 `git diff --check`。

浏览器角色场景定义位于 `scripts/browser-role-flows.json`，契约测试使用 `node --test scripts/browser_role_flows_contract.test.mjs`。真实 Playwright 执行要求 Chromium/Playwright 运行时和本地服务，当前环境状态为 `pending-runtime`。
桌面与移动视口的静态恢复契约使用 `node --test scripts/viewport_resilience_contract.test.mjs`，覆盖媒体查询、加载/空/错误态、超时、网络恢复、ETag 更新、冲突重试和本地草稿恢复。真实视口渲染结果仍需 Playwright/Chromium 运行时验证。

## 认证与权限

- Web 与小程序使用 JWT Bearer 认证。
- `api/config.php` 也兼容服务端 session 身份。
- 管理页面入口按 `admin`、`manager` 或 `is_manager` 控制显示，服务端接口仍需独立授权。
- 新管理动作应接入操作审计，并保持最小权限。

## 常见任务

### 添加公开文章

修改 `news/`、`news/index.html`、必要的首页入口、共享样式和 `sitemap.xml`，随后执行结构化数据、链接和预览检查。

### 添加 API 端点

在对应 `api/{domain}/` 创建端点，复用 API kernel、认证和响应约定。若小程序使用该端点，同步 `business-domain-matrix.json` 和代理契约测试。

### 添加 migration

创建新的 SQL migration，更新 manifest，运行 migration compatibility、idempotency、readiness 和 replay 相关测试。

### 修改小程序页面

同步检查 `.js`、`.json`、`.wxml`、`.wxss`，运行页面涉及的契约和语法测试，再使用微信开发者工具完成端到端验证。

## 事实与安全要求

- 公开品牌内容优先引用仓库正式标准和可回溯公开事实。
- 儿童运动观察与体测应明确专业边界，避免医疗化诊断和效果保证。
- 禁止在文档、代码或提交中写入密码、Token、密钥和生产凭据。
- 修改旧页面时保留用户未授权范围内的现有业务行为。
