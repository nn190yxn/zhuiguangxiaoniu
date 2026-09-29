# 培训卡融合知识库："实战演练"板块 实施方案

日期：2026-09-30　状态：已获用户确认（板块名：实战演练）

## 背景与目标

培训卡（training_cards，356 张，K/S/D/C 四类组成 89 个知识点）目前只有从后台上传工具页才能进入，内网无入口。用户确认方案：把培训卡"投影"进知识库，新增一级板块"实战演练"，每个知识点合成一条四板块知识卡；training_cards 仍是唯一数据源，演练/通关功能不动，详情页提供"去演练"跳转。

## 现状机制（调研结论）

- 板块分组：`api/knowledge/KnowledgeTaxonomy.php::classificationSql()` 由 `database/knowledge_taxonomy_mapping.v1.json` 的 domain_mappings 生成 SQL CASE；domain 映射优先于关键词启发（销售词表含"话术"等，必须在 domain 层命中，不能被误判进销售板块）。
- 列表接口 `/api/knowledge/list.php` 已支持 `primary_category` 过滤，读 knowledge_versions 当前版本（kv 字段 COALESCE 回退 k 字段），新增一级板块无需改列表服务。
- 前端：`knowledge/index.html`（line-tab 专业/销售两枚）+ `knowledge/knowledge.js`（taxonomy 硬编码 topics、line 31 只认 sales/professional）+ `mobile/knowledge.html`（quick-tabs data-primary-category）+ `knowledge/detail.html`（安全 Markdown 渲染，四板块可直接显示）。
- 小程序端按 content_type 过滤（动作库/话术库/知识卡），新卡 content_type=knowledge_card 时自然出现在"知识卡"页签，不强制改小程序。
- 二期导入器契约固定（8 种 card_type、phase2_import 分类），不复用；新建专用同步 CLI，治理口径对齐：默认 dry-run、命名锁、apply 前备份、事务、manifest、精确回滚、内容指纹幂等。

## 改动清单

### 1. 分类口径（taxonomy）

- `knowledge_taxonomy_mapping.v1.json`：新增一级板块 `practice`（实战演练），子分类：
  - `sales_junior` 销售初阶、`sales_intermediate` 销售中阶、`sales_senior` 销售高阶（模块 8/9/10）
  - `store_core` 门店核心模块（模块 1-7）
- 新增 domain_code 四个：`practice_sales_junior` / `practice_sales_intermediate` / `practice_sales_senior` / `practice_store_core`，全部映射 primary_category=practice。
- `KnowledgeTaxonomy.php` 若 lines()/classify() 有硬编码两处板块假设则同步适配（domain_mappings 驱动的循环不用动）。

### 2. 数据层

- `knowledge_categories` 由同步脚本按 code 幂等建 4 个分类（type=knowledge_card）。
- 每个知识点生成一条 knowledge_items：item_code=`TRAIN-<组键>`（如 TRAIN-SALES-0001），domain_code 按模块映射，content_type=knowledge_card，publication_status=published，status=1。
- 正文四板块（安全 Markdown）：
  1. `## 这张卡是什么`（K 卡内容）
  2. `## 怎么跟家长说`（S 卡话术）
  3. `## 怎么练`（D 卡演练场景，含错误/正确示范与家长问题）
  4. `## 怎么算通关`（C 卡评分标准与通关项）
- 写 knowledge_versions 版本行并回指 current_version_id；批次记入 knowledge_import_batches（batch_type=training_practice_sync）；复用既有收藏/最近浏览，无需改表。

### 3. 同步 CLI（新文件 `scripts/sync_training_cards_to_knowledge.php`）

- 读 training_cards（status=1）→ 按 card_code 去 -k/-s/-d/-c 后缀分组 → 生成合成内容与 sha256 指纹。
- 模式：默认 dry-run（只报告 insert/update/skip/absent 四类计数与样例）；`--apply` 加命名锁、写备份（受影响行 JSON）与 manifest、事务提交；`--rollback <manifest>` 精确回滚本批。
- 幂等：同 item_code 且指纹一致 skip；指纹变化 update（新版本行+原子换指针）；training_cards 删除的组默认保留知识库旧版并在报告中标注 absent，不自动下架（防误删）。

### 4. 前端

- `knowledge/index.html`：line-tab 增加"实战演练"。
- `knowledge/knowledge.js`：primaryCategory 合法值扩为三个；taxonomy.practice topics；首标签文案"实战演练"。
- `knowledge/detail.html`：domain_code=practice_* 时，头部下方显示"去演练"按钮 → `/mobile/drill.html`（现有演练入口，数据同源 training_cards）。
- `mobile/knowledge.html`：quick-tabs 增加"实战演练"；检查其 JS 对 data-primary-category 的处理并适配。
- 小程序端不改发；新卡自动出现在"知识卡"页签与搜索结果。

### 5. 验证与交付

- 本地：node 静态契约测试（沿用 knowledge 专项测试风格，新增 taxonomy 断言）；PHP 语法在服务器 lint。
- 部署：备份至 `/www/mc-backups/<日期>-practice-fusion/`，单文件 scp + 双向 md5。
- 生产：只读 dry-run 报告（应为 89 组、insert=89）→ 用户界面验收后 `--apply` → 复核员工端可见计数（1841→1930 预期）、未登录不泄露。
- 回滚预案：manifest 精确回滚 + 文件级备份还原。

## 边界

- 不做：不改 training_cards 结构、不动演练/通关逻辑、不给知识库回写演练进度、不在小程序端加独立页签（后续可提）。
- 数据风险：低。新增 89 条 published 内容，全量记录可精确回滚。
