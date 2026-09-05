# 接口文档

## Web 入口

| 入口 | 路径 | 用户 |
|------|------|------|
| 公开官网 | `/` | 家长与公开访客 |
| 公开资讯 | `/news/` | 搜索、AI 检索与家长 |
| 员工登录 | `/mobile/login.html` | 员工 |
| 员工工作台 | `/internal.html` | 已登录员工 |
| 知识中心 | `/knowledge/` | 已登录员工 |
| 知识详情 | `/knowledge/detail.html?id={id}` | 已登录员工 |
| 学习中心 | `/learning/` | 已登录员工 |
| 个人中心 | `/mobile/mine.html` | 已登录员工 |
| 教案上传与结构化编辑 | `/lesson-submission.html` | 具有教案创建权限的教练 |
| 教案审核工作台 | `/lesson-review.html` | 店长与教学主管 |
| 正式教案库 | `/lesson-library.html` | 已登录员工 |
| 管理后台 | `/admin/dashboard.html` | 管理角色 |

员工工作台 `/internal.html` 保留 `staffPhone`、`staffPassword`、`loginPanelTitle`、`loginForm`、`accountCard`、`accountName`、`accountMeta`、`adminLink` 和 `globalSearchInput` DOM ID。全局搜索将非空关键词编码后导航到 `/search.html?q={query}`；业务工具导航使用 `/internal.html#tools`。

正式教案库 `/lesson-library.html` 默认调用 `/api/lesson-library/list.php`，支持关键词、课程线、班级或级别和分页查询。列表卡片使用接口返回的 `canonical_route`；访问 `/lesson-library.html?id={submission_id}` 时，页面调用 `/api/lesson-library/detail.php` 展示固定批准版本。学习中心的“正式教案库”卡片直接进入该入口。

### 员工运营中枢页面壳

加载 `/internal-auth.js` 的员工页面共享以下客户端合同：

- 脚本创建唯一 `#mcOpsShell`，导航使用 `.mc-persistent-staff-nav`。
- 页面壳从 `/assets/internal-ops.css` 加载石墨、暖白和信号橙设计令牌。
- 一级导航固定包含内网首页、制度中心、知识中心、演练中心、学习中心、业务工具和我的；管理角色额外显示管理中心。
- 当前入口使用 `.current` 和 `aria-current="page"`，`/internal.html#tools` 与内网首页保持唯一当前态。
- 员工身份展示读取 `staff_name`、`display_name`、`nickname` 或 `username`，并组合门店与角色信息。
- 页面按路径获得 `mc-ops-center-page`、`mc-ops-center--{center}` 和 `data-mc-ops-center` 标识，共享视觉层使用这些标识限定各中心的卡片、筛选、列表与状态样式。
- 复杂页面使用 `internal-knowledge-detail`、`internal-submission`、`internal-review`、`internal-dashboard` 和 `internal-staffs` body 类限定详情、表格、表单、审核决策与抽屉样式。
- 页面壳以插入节点方式工作，原页面内容、表单 ID、移动端底部导航和业务脚本接口保持不变。

## 公开内容契约

### 文章页面

公开文章位于 `/news/{slug}.html`，主要页面契约包括：

- 唯一 `title`、`description`、canonical 与 H1。
- `og:title`、`og:description`、`og:type`、`og:url` 和文章发布日期。
- `Article`、`FAQPage`、`BreadcrumbList` JSON-LD。
- 页面可见 FAQ 与 FAQPage 内容一致。
- 返回 `/news/` 的入口和至少三篇相关文章。

### 发现文件

| 文件 | 契约 |
|------|------|
| `news/index.html` | CollectionPage、专题 ItemList 和全部文章入口 |
| `sitemap.xml` | 规范 URL、lastmod、changefreq、priority |
| `robots.txt` | 爬虫允许规则和 sitemap 声明 |
| `manifest.webmanifest` | PWA 公开元数据 |

## PHP API 基础契约

API 基础路径为 `/api`。大多数业务端点使用 JSON，请求通过 `Authorization: Bearer <token>` 认证。写请求可使用：

| Header | 用途 |
|--------|------|
| `Authorization` | JWT 身份认证 |
| `X-Request-ID` | 请求追踪 |
| `Idempotency-Key` | 写操作幂等 |
| `X-State-Version` | 客户端状态版本与冲突控制 |

未认证接口主要集中在登录和运行能力发现；具体授权以端点实现和 `business-domain-matrix.json` 为准。

## 小程序业务域

以下接口来自 `mini-program/business-domain-matrix.json`，该文件也是云函数代理的路由登记来源。

| 业务域 | 代表接口 | 认证 |
|--------|----------|------|
| 认证与会话 | `POST /auth-jwt.php`、微信与企微登录绑定 | 部分公开 |
| 运行能力 | `GET /platform/capabilities.php` | 公开 |
| 制度与通知 | `/policy/search.php`、`/policy/detail.php`、`/policy/notify.php` | JWT |
| 学习课程 | `/learning/category.php`、`/learning/list.php`、`/learning/detail.php` | JWT |
| 知识库 | `/knowledge/list.php`、`/knowledge/detail.php`、`/knowledge/progress.php` | JWT |
| 考试 | `/exam/index.php`、`/exam/save.php`、`/exam/submit.php` | JWT |
| Drill v2 | `/drill/v2/home.php`、`attempts.php`、`turns.php`、`results.php` | JWT |
| 工作量 | `/workload/template.php`、`my-report.php`、`save-report.php` | JWT |
| 积分与档案 | `/points/`、`/staff/profile.php`、`/pass/` | JWT |
| 教案上传与编辑 | `/lesson-submissions/create.php`、`/lesson-submissions/upload.php`、`/lesson-submissions/parse.php`、`/lesson-submissions/detail.php`、`/lesson-submissions/draft.php`、`/lesson-submissions/validate.php`、`/lesson-submissions/optimize.php`、`/lesson-submissions/suggestion-decision.php` | JWT 与教案权限 |
| 智能教案生成 | `POST /smart-lessons-api.php` | JWT 或员工会话 |

## 小程序 transport

`mini-program/utils/api.js` 支持四种策略值：

| 模式 | 行为 |
|------|------|
| `direct` | 小程序直接请求 PHP API |
| `cloud` | 通过腾讯云函数代理请求 PHP API |
| `shadow` | 用于迁移期影子请求与结果比较 |
| `versioned` | 按客户端版本和读写类型选择 direct、cloud 或 shadow |

默认请求超时 15 秒，上传超时 60 秒。客户端会规范化未授权、无权限、冲突、校验和服务端错误。

设备会话刷新统一使用 `POST /auth/mini-program-session.php?action=refresh`，请求体携带 `refresh_token` 和 `device_id`。PHP 端点、小程序统一客户端、源业务矩阵和云代理部署矩阵使用相同 method-path 契约；退出会话使用同端点的 `action=logout` POST 请求。

### 知识中心分类

`GET /knowledge/list.php` 支持 `primary_category=professional` 或 `primary_category=sales`，并在每条结果中返回 `primary_category`、`primary_category_label`、`subcategory_code` 和 `subcategory_label`。`GET /knowledge/categories.php` 返回双主线及其稳定子分类清单；动作、游戏、体测与教案参考归入专业知识，话术及接待、需求分析、体验课、异议处理、成交和续费内容归入销售知识。桌面入口 `/knowledge/` 将查询参数同步到列表接口，数据库结果跳转 `/knowledge/detail.html?id={id}`；收藏与最近浏览分别使用 `favorite=1` 和 `recent=1`。

`database/knowledge_taxonomy_mapping.v1.json` 是分类映射的版本化数据源。当前激活版本为 `taxonomy-2026-09-04-v1`，完整定义员工端双主线及其子分类，并覆盖 `ace_teaching`、`child_development`、`sensory_integration`、`physical_qualities`、`course_skills`、`assessment`、`teaching_practice` 和 `safety_first_aid` 八个导入领域。每项领域映射包含目标主线、目标子分类和状态，`content_type_review_baselines` 额外定义动作、游戏、训练计划、教学组织、教学知识、测评和安全内容的复核目标。列表响应、分类清单及每条分类结果返回 `taxonomy_mapping_version`；列表主线筛选按激活版本的领域集合执行，并兼容旧销售领域和话术内容。

`scripts/build_knowledge_card_classification_report.py` 读取隔离包和激活 taxonomy，生成 `database/import_data/knowledge-cards-phase2.taxonomy-review-report.json`。报告 schema 为 `knowledge-card-classification-review-report.v1`，包含输入摘要、仓库与数据库评估边界、分类汇总、映射缺口、人工确认原因和逐卡待审核记录；`report_sha256` 对移除自身后的稳定 JSON 计算。

`scripts/knowledge_card_release_gate.php` 接受隔离包、预期数量、分类审核报告和目标环境 evidence，输出 `knowledge-card-release-gate.v2`。`checks` 独立包含 `record_count`、`transitional_classification`、`mapping_integrity`、`current_versions`、`review_records` 和 `target_visibility`，每项提供 `passed`、计数明细和稳定失败原因。目标环境输入可使用包含 `knowledge_database` 的完整 release evidence，也可直接使用该对象；字段包括 `record_count`、`visible_count`、`transitional_count`、`unmapped_count`、`current_version_count`、`review_record_count` 和 `taxonomy_mapping_version`。

员工发现面数据库查询使用 `EmployeeKnowledgeVisibilityQuery::fromCurrentVersion()` 生成知识主记录与当前版本数据源。知识列表计数和结果、详情主记录和相关内容、全局搜索、教案知识建议及兼容智能教案入口均调用该组件；返回候选必须同时满足主记录启用、发布状态为 `published`、当前版本属于同一知识卡且版本状态为 `active`。知识详情的 `related` 条目返回当前 `version_id`，并以当前版本的标题、摘要、内容类型和领域字段为优先值；全局搜索同样返回 current version 的 `version_id`。查询别名只接受最长 64 字节的 ASCII SQL identifier。管理审核和历史建议快照按其独立状态与固定版本合同读取。

### 静态来源索引

`content-index.json` 是迁移期静态内容清单。每条记录包含 `stable_key`、`source_type`、`source_path`、`canonical_url`、`center_code`、`primary_category`、`content_type`、`publication_status` 和 `version_id`；数据库内容迁移完成后，可按 `stable_key` 回填 `unified_content_index`。静态来源搜索结果与数据库结果使用相同的中心、内容类型、规范路径和版本字段。

### 全局搜索治理

`GET /api/search/global.php?q=关键词&type=all` 聚合制度、知识、课程、员工、话术、演练、培训和考试结果。每条结果统一返回 `center`、`category`、`content_type`、`canonical_url`、`matched_fields`、`version_id`、`source_type` 和 `source_path`；迁移期静态来源继续保持 `publication_status=published` 才可进入员工搜索。搜索词会按业务同义词扩展并在分类内去重；零结果查询以最佳努力方式写入 `search_query_logs`。

管理员可通过 `GET /api/admin/search/no-results.php?limit=20` 查询高频无结果词，该接口仅允许管理、CEO、运营和店长角色访问。

### 知识内容治理

`GET/POST /api/admin/knowledge/index.php` 提供统一治理入口。GET action 包括 `list_batches`、`items`、`quality`、`item`、`relations`、`versions` 和 `audit`；`items` 支持 `publication_status`、`content_type`、`domain_code` 和 `limit` 筛选。POST action 包括 `create_relation`、`review_relation`、`create_version`、`publish`、`unpublish` 和 `rollback`，发布、回滚、关系变更和版本创建均写入 `knowledge_audit_logs`。

## 云函数事件契约

`api-proxy` 与 `auth-proxy` 接受协议版本 1 的 request 事件：

```json
{
  "protocol_version": 1,
  "type": "request",
  "method": "GET",
  "route": "/knowledge/list.php",
  "header": {
    "Authorization": "Bearer <token>",
    "X-Request-ID": "<request-id>"
  },
  "data": {}
}
```

代理只允许登记路由，限制请求与响应体大小，并返回 `upstream_status`、`body`、`request_id`、`route_id` 和业务域信息。配置网关密钥后，代理向上游附加 HMAC SHA-256 签名头。

## 数据接口

`api/config.php` 通过 PDO 提供 MySQL 连接。数据库变更位于 `database/migrations/`，`database/migration_manifest.php` 描述 migration 对表、字段和索引的预期影响。

主要数据域包括：

- 员工、门店、岗位、组织分配与生命周期。
- 工作量模板、日报、凭证、审核、预警与导出。
- Drill 场景、过程、尝试、轮次、评估、学习与治理。
- 学习、知识、考试、积分、制度通知与提醒。
- 招聘需求、候选人、简历、队列和平台任务。
- 教案提交、Office 原始文件、结构化版本、优化建议、审核任务、导出和审计表已登记在数据库 migration 中；上传、解析、编辑、检查、建议、导出和审核接口已经接通。
- 教案创建接口 `POST /lesson-submissions/create.php` 校验门店、作者、课程线、班级或级别、日期和标题，并创建初始草稿版本。提交页的作者字段只读展示共享身份适配器解析的姓名；接口从认证上下文取得 `staff_id`，将其写入 `author_staff_id` 和 `created_by` 作为 ownership 依据。
- 教案上传接口 `POST /lesson-submissions/upload.php` 接收 `submission_id` 和 `file`，仅允许 `.xlsx`、`.xls`、`.docx`、`.doc`，校验实际 MIME、大小和作者归属后保存私有文件及 SHA-256 摘要；存储键位于 `lesson-submissions/submission-{submission_id}/` 命名空间。
- 教案解析接口 `POST /lesson-submissions/parse.php` 接收 `submission_id` 和 `source_file_id`，读取私有原文件并生成递增的 `parsed` 结构化版本；解析失败时保留原文件、失败记录和可手工编辑的空模板。
- 教案 XLSX 解析服务读取多 Sheet、单元格、合并区域和常见字段表头，输出统一教案结构及 `sheet`、单元格引用；`.xls` 旧格式返回明确解析失败信息，由手工录入回退处理。
- 教案 DOCX 解析服务读取标题、段落、列表和表格，输出统一教案结构及段落/表格位置引用；`.doc` 旧格式返回明确解析失败信息，由手工录入回退处理。
- 教案手工录入恢复接口 `POST /lesson-submissions/manual-entry.php` 仅允许作者处理 `parse_failed` 教案，将已创建的空结构化版本切换为 `editable`；解析失败记录保存在 `lesson_parse_runs`，原始文件继续保留。
- 教案详情接口 `GET /lesson-submissions/detail.php?id=...` 返回主记录、当前版本、版本历史、原始文件摘要和解析记录；草稿接口 `POST /lesson-submissions/draft.php` 使用 `status_version` 创建递增版本并返回修改字段摘要。
- 建议决定接口 `POST /lesson-submissions/suggestion-decision.php` 接收 `submission_id`、`suggestion_id`、`decision` 和 `status_version`；`accepted` 携带当前结构化内容并创建新草稿版本，`ignored` 记录忽略决定；两种决定均保存处理人、时间和审计日志，并拒绝旧版本或已处理建议。
- ACE 规则接口 `POST /lesson-submissions/validate.php` 接收 `submission_id`，默认检查作者可访问的当前结构化版本；传入 `content` 时检查编辑中的未保存内容。结果返回版本编号、内容来源、字段路径、严重级别、优先级、修复动作、规则依据和课程环节总时长。
- 知识卡优化接口 `POST /lesson-submissions/optimize.php` 按年龄、课程线、训练项目、课堂阶段、器材和风险匹配已发布动作、游戏与安全知识卡。建议绑定当前教案版本及生成时的 active 知识卡版本，返回字段路径、优先级、理由、匹配维度、知识卡 ID、知识卡版本 ID、编号和标题；教案详情按固定版本返回各版本建议。
- 教案导出接口 `POST /lesson-submissions/export.php` 接收 `submission_id`、`format`（`xlsx` 或 `docx`）和可选 `version_id`，从指定结构化版本生成标准 Office 文件，写入 `lesson_exports` 并返回受保护的下载地址；`GET /lesson-submissions/export.php?id=...` 校验导出人后下载私有文件。Excel 包含基本信息、课程流程、安全与器材、ACE反思四个 Sheet，Word 输出同一结构化内容。
- 教案提交接口 `POST /lesson-submissions/submit.php` 接收 `submission_id` 和 `status_version`，复用 ACE 完整性检查并要求当前版本的优化建议已处理；接口按门店查找启用店长，冻结当前结构化版本，创建 `store_review` 店长初审任务，将主记录切换为 `store_review` 并写入审计日志。
- 审核查询接口 `GET /lesson-reviews/list.php` 按当前审核人隔离任务，支持 `status`、`stage` 筛选；传入 `id` 时返回任务详情、提交版本、原始文件摘要、版本历史、优化建议和对应导出记录。店长使用 `lesson_submission.view_store`，教学主管使用 `lesson_submission.view_review_scope`。
- 审核决策接口 `POST /lesson-reviews/decision.php` 接收 `review_task_id`、`decision`（`approved` 或 `returned`）和审核意见；退回必须填写原因。店长通过后创建教学主管任务并进入 `supervisor_review`，教学主管通过后写入 `approved_version_id` 并进入 `approved`，退回进入 `returned`。任务归属、角色阶段权限、版本一致性、状态版本和重复处理均受到校验，审核意见与状态迁移写入审计日志。
- 正式教案列表接口 `GET /lesson-library/list.php` 面向已登录员工，支持 `page`、`page_size`、`q`、`course_line` 和 `class_level`。返回 `list`、`total`、`page`、`page_size` 和实际筛选条件；每条记录来自已批准且已发布的主记录及其已提交、不可变批准版本，并包含稳定 `canonical_route`。
- 正式教案详情接口 `GET /lesson-library/detail.php?id={submission_id}` 面向已登录员工，只返回 `lesson` 和 `approved_version`。查询严格使用同教案的 `approved_version_id`，隐藏、未批准、版本可变或版本归属异常的记录统一返回 `lesson_library_item_not_found`。
- 正式教案归档接口 `POST /lesson-library/archive.php` 要求 `lesson_review.supervisor_decide` 权限，接收 `submission_id`、`status_version` 和可选 `reason`。成功后返回保留的 `approved_version_id`、`archived` 主状态与正式库状态及递增后的状态版本；归档事务保留批准版本、发布历史、审核任务和导出关系，并写入 `lesson_archived` 审计。
- `lesson_review` 业务域把 `lesson-library.html` 和 `js/lesson-library.js` 登记为正式库消费者。正式库列表、详情和归档响应通过统一平台元数据声明 `approved_version_publication`、`formal_library_read` 和 `canonical_lesson_route` 能力。
- 智能教案生成接口 `POST /smart-lessons-api.php` 保持 `monthlySummary`、`aceFocus`、`weeks`、`materials`、`segments`、`coachTips` 和 `parentTips` 响应字段。资料顺序为已发布知识卡、静态周教案和内置 ACE 模板，`libraryStatus` 与 `sourceReferences` 描述实际来源及降级状态。
- 文件资产、AI 调用、同步、作业队列、outbox 和审计。

## 错误与响应

PHP API 普遍返回 JSON；具体字段由业务端点定义。客户端按照 HTTP 状态和 `code` 将错误归类为 `unauthorized`、`forbidden`、`conflict`、`validation`、`server` 或 `http`。冲突响应可携带当前版本、权威状态和恢复动作。
