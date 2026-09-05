# 内网知识体系升级前置审计

审计日期：2026-09-04  
审计范围：员工 Web/PWA、全局搜索、微信小程序与云代理、PHP API、MySQL migration、知识卡导入和版本发布链路。

## 审计结论

现有功能已经形成知识库、学习、演练、体测、动作库、教案和搜索的多个入口，但入口、内容来源、版本和发布边界没有完全统一。统一上线前必须完成基础链路修复，再进行 1417 张知识卡的分类和发布。

## P0：统一上线前必须修复

### 1. 制度搜索和详情没有统一发布边界

- `api/search/search-service.php:123-154` 的制度搜索没有限制发布状态。
- `api/policy/search.php:46-108` 同样查询全部制度记录。
- `api/policy/detail.php:25-31` 详情按 ID 读取，缺少员工可见性判断。

影响：普通员工可能通过搜索或 ID 获取草稿、下线或角色限定制度。

处理：建立制度可见性服务，统一用于制度列表、搜索、详情和通知；增加普通员工读取未发布制度的回归测试。

### 2. 知识卡版本发布链路不一致

- `api/admin/services/KnowledgeOperationService.php:53` 创建版本后没有同步 `knowledge_items.current_version_id`。
- `api/knowledge/KnowledgeListService.php:65-76`、`api/knowledge/detail.php:40-47` 和 `api/search/search-service.php:98-103` 读取主表旧字段。
- `smart-lessons-api.php:334-345` 单独读取版本表。

影响：员工知识库、搜索、智能教案可能看到不同版本；后台新版本无法成为员工端实际版本。

处理：版本创建、当前版本切换、旧版本失效放入同一事务；所有读取服务统一 JOIN 当前版本并校验版本归属。

### 3. `current_version_id` 缺少同卡归属约束

- `database/migrations/202608260001_knowledge_card_phase2_schema.sql:119-164` 仅建立单列外键。

影响：知识卡 A 可以指向知识卡 B 的版本，造成跨内容读取。

处理：增加复合唯一键和复合外键；迁移前清理异常数据并阻断不一致发布。

### 4. 教案关联缺少知识卡版本和跨表约束

- `database/migrations/202609030001_smart_lesson_review.sql:3-147` 多个教案子表没有外键。
- `api/lesson-submissions/LessonKnowledgeMatcher.php:33-55` 建议只记录知识卡 ID，没有知识卡版本 ID。

影响：教案建议、审核任务和导出记录可能引用跨教案版本；知识卡更新后无法追溯建议生成时的具体版本。

处理：补充复合外键、知识卡版本 ID、来源版本快照和一致性校验。

### 5. 小程序云代理缺少实际调用路由

- 演练问答页面调用 `/drill/v2/qa/...`，矩阵登记为 `/qa/...`。
- 考试页面调用 `/exam/list.php` 和 `/exam/history.php`，矩阵没有登记。
- 知识详情调用 `/knowledge/favorite.php`，矩阵没有登记。
- 会话刷新和退出调用 `/auth/mini-program-session.php?action=refresh/logout`，矩阵没有登记。

影响：云传输模式下页面请求被 `route_not_allowed` 或 404 拒绝。

处理：从实际 PHP 路径生成或校验两份业务矩阵；补齐方法、action、认证、幂等和副作用声明。

### 6. 小程序核心页面没有注册到 `app.json`

- `mini-program/app.json:2-24` 缺少学习、制度、搜索、通关、积分、提醒等已存在页面。

影响：页面代码和业务矩阵存在，微信小程序导航无法打开页面。

处理：补齐页面注册并加入页面文件、矩阵和入口调用的三方一致性测试。

## P1：上线前应修复

### 7. 全局搜索没有统一搜索索引

- `api/search/search-service.php:91-120` 对多个主表字段执行 `%keyword%` 查询。
- 数据库没有统一内容索引、全文索引或增量索引机制。
- 静态动作、游戏、培训资料、专题卡和静态教案不在搜索范围。

处理：建立统一搜索索引和来源注册表，发布版本通过 outbox 或事务事件更新索引；迁移期补充静态来源索引。

### 8. 文档搜索结果和阅读器 ID 不一致

- 搜索结果通过 `doc_key` 进入 `doc-viewer.html`。
- `doc-content.php` 有 72 个文档 ID，`doc-viewer.html` 仅登记 29 个。

处理：使用单一文档注册表生成内容 API、阅读器映射和搜索链接，并加入 ID 集合一致性测试。

### 9. 内网核心入口存在缺失路径

- `internal.html:138-141` 指向 `/表格中心/`、`/知识库/`、`/新员工学习/`。
- 仓库当前存在 `/mobile/knowledge.html`、`/mobile/learning.html`，缺少中文目录入口。

处理：建立规范 `/knowledge/` 和 `/learning/`，补齐兼容跳转；确认表格中心的实际发布入口并加入页面可达性检查。

### 10. 学习章节 GET 请求包含写入副作用

- `api/learning/LearningLessonService.php:39-95` 的读取流程会写学习进度并可能发放积分。
- 小程序章节页以 GET 调用，矩阵没有声明副作用。

处理：拆分纯读取和完成接口；完成接口使用 POST、幂等键和状态版本。

### 11. 学习详情未使用统一认证上下文

- `api/learning/detail.php:11-16` 仅读取用户 ID，没有统一认证校验。

处理：统一使用 `platformApiAuthContext()`，复用标准响应、日志和异常处理。

### 12. 隔离知识卡尚未进入员工端

- `database/import_data/knowledge-cards-phase2.source-report.json` 显示 1417 条导入记录。
- `database/import_data/knowledge-cards-phase2.isolated-package.json` 默认状态为 `isolated`。
- 员工列表、全局搜索和智能教案只读取 `published`。

处理：完成分类、字段补全、关联、审核和全量门禁后一次性发布。

## P2：体验和运维改进

- 搜索页应等待会话刷新完成后再请求，避免有效 refresh cookie 被误判为未登录。
- 认证 API、制度正文和权限相关 GET 接口统一使用 `Cache-Control: private, no-store`。
- PWA 明确离线能力边界，统一展示网络错误、离线和重试状态。
- JWT 存储方式需要统一，减少 localStorage、sessionStorage 和可读 Cookie 的多份凭证副本。
- `lessons/manifest.json` 与实际 55 个 HTML 文件存在 3 个未登记文件，应补齐或明确归档。
- 小程序学习、考试、制度和体测页面统一接入 `view-state.js`。

## 审计验证缺口

当前已有契约测试 24/24 通过，教案相关测试 64/64 通过。现有测试主要验证源码契约，尚未覆盖：

- 临时 MySQL/MariaDB 的真实迁移回放。
- 当前版本归属和唯一 active 版本。
- 制度发布状态与员工权限。
- 小程序 `app.json`、页面调用、矩阵和 PHP 文件的三方一致性。
- 搜索结果路径实际可达性。
- PWA 会话刷新竞态和真实浏览器交互。
