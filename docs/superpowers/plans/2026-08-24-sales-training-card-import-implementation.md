# 销售培训知识卡一期实施计划

- 日期：2026-08-24
- 作者：Monkeycode
- 依据：`docs/superpowers/specs/2026-08-24-sales-training-card-import-design.md`
- 范围：75 张销售母卡拆分为 300 张培训卡；不处理 1417 张训练知识卡

## 实施原则

1. 先修复培训资源鉴权和页面 XSS 风险，再导入内容。
2. 本地生成的数据包必须确定性可复现，使用 SHA-256 固定版本。
3. 导入器默认只 dry-run；代码部署、自动测试和批处理均不得携带 `--apply`。
4. 线上真实文件是发布基线。发现线上与仓库同路径文件不一致时，先下载审阅，禁止直接覆盖。
5. 线上正式写入前必须再次向用户展示 dry-run 报告并取得明确确认。

## 任务 1：建立培训资源统一鉴权策略

**文件**

- 新增：`real_sync/api/drill/TrainingAccessPolicy.php`
- 修改：`real_sync/api/config.php`
- 新增：`real_sync/scripts/sales_training_card_import.test.mjs`

**先写测试**

- 匿名访问任何培训模块均拒绝。
- 销售岗位 `sales` 映射到模块历史角色 `consultant`。
- 销售可访问空角色及 `consultant` 模块，不可访问 `coach` 模块。
- 教练可访问空角色及 `coach` 模块，不可访问 `consultant` 模块。
- JWT `admin`、`manager` 可管理查看。
- `operation`、`ceo` 若 JWT 不是管理身份，不自动取得管理权限。
- 模块 `level` 不参与本期访问判断。

**实现**

1. 在独立策略类中实现纯角色矩阵，避免测试依赖数据库和生产密钥。
2. 在 `config.php` 提供从当前 JWT、员工档案和模块角色构造访问上下文的包装函数。
3. 认证失败时不得调用 `getEffectiveStaffRole(null)` 的销售默认值作为授权依据。
4. 统一无权访问响应，正文和进度写入必须发生在鉴权之后。

**验证**

```bat
node --test real_sync\scripts\sales_training_card_import.test.mjs
```

本机没有 PHP CLI；PHP 语法检查在任务 7 上传临时文件后于服务器执行，不直接覆盖线上文件。

## 任务 2：封闭模块与卡片 API 越权路径

**文件**

- `real_sync/api/drill/training-modules.php`
- `real_sync/api/drill/training-cards.php`
- `real_sync/scripts/sales_training_card_import.test.mjs`

**先写测试**

覆盖模块列表、模块详情、模块卡片列表、我的进度、全卡列表、单卡读取、提交和重置：

- 匿名全部拒绝。
- 销售可访问 `consultant` 模块完整链路。
- 普通教练不能通过模块 ID、卡片 ID、提交或重置访问销售卡。
- 管理身份可查看；`role` 查询参数只有管理身份可以生效。
- 停用模块或停用卡片不能读取、提交或重置。

**实现**

1. 所有 action 强制有效认证，删除 `list/get` 的匿名例外。
2. `getModules()` 从统一访问上下文构造 SQL 权限条件。
3. `getModuleDetail()`、`getModuleCards()` 在查询正文和统计前校验模块权限。
4. `getMyProgress()` 只返回当前有权访问的模块。
5. `listCards()` 在 SQL 中 JOIN 模块并过滤允许角色，不能先取全量再由 PHP 过滤。
6. `getCard()`、`submitAnswer()`、`resetCard()` 先通过卡片所属模块鉴权，再读取正文或修改 `user_progress`。
7. 保持成功响应结构兼容现有 H5；无权访问使用一致错误语义。

**验证**

```bat
node --test real_sync\scripts\sales_training_card_import.test.mjs
node --test real_sync\scripts\drill_legacy_baseline.test.mjs
```

## 任务 3：实现安全结构化排版

**文件**

- `real_sync/training-card.html`
- `real_sync/scripts/sales_training_card_import.test.mjs`

**先写测试**

- `<script>`、`img onerror`、`svg onload`、`iframe srcdoc`、事件属性和恶意引号只能显示为文字。
- `showResult()` 的 `feedback`、`standard_answer`、`tips` 与主体采用相同安全契约。
- 选项包含引号、尖括号和 `&` 时可正常选择，不产生属性注入。
- 支持章节、段落、有序/无序列表、引用、正误提示和格式完整的简单评分表。
- 输出仅包含约定标签，不允许来源控制 URL、样式、属性或 class。

**实现**

1. `formatText()` 按“先转义、后识别行结构”生成白名单标签，不引入通用 Markdown 渲染器。
2. 不把选项原值写入 `data-value`；DOM 仅保存数组索引，点击时从内存数据取原值。
3. `showResult()` 所有服务端文本经过格式化或 `textContent`。
4. 增加受控列表、引用、提示和表格样式，不改知识库 H5 或小程序。

**验证**

```bat
node --test real_sync\scripts\sales_training_card_import.test.mjs
```

## 任务 4：生成确定性 75→300 数据包

**文件**

- 新增：`real_sync/scripts/generate_sales_training_cards.py`
- 新增：`real_sync/database/import_data/sales-training-cards.v1.json`
- 新增：`real_sync/database/import_data/sales-training-cards.v1.report.json`
- 新增：`real_sync/database/import_data/sales-training-cards.v1.sha256`
- 修改：`real_sync/scripts/sales_training_card_import.test.mjs`

**先写测试**

- 75 个 `card_id` 唯一、连续覆盖 `SALES-0001` 至 `SALES-0075`。
- 每张具备非空标题以及核心要点、标准话术、演练场景、通关标准四节。
- 生成 3 个模块和 300 张卡，K/S/D/C 各 75。
- 模块卡量分别为 100、84、116。
- 300 个 `card_code` 均符合 `sales-\d{4}-[ksdc]` 且唯一。
- 初/中/高映射到 `beginner/easy`、`intermediate/medium`、`advanced/hard`。
- `sort_order=母卡编号*10+类型序号`。
- 缺节、重复、空标题、字段超长、非法选项时整批失败，不留下最终文件。
- 相同输入重复生成的 JSON 字节和 SHA-256 完全相同。

**实现**

1. 只使用 Python 标准库解析当前受限 frontmatter 和固定章节，不增加依赖。
2. K 的核心要点写 `content`；S 的标准话术写 `standard_answer`；D 的场景写 `content`；C 的评分标准写 `content`，可靠检查项写 JSON `options`。
3. `tips` 保留母卡编码、相对路径、`source_articles` 和来源说明，不写本机绝对路径。
4. 数据包保存纯文本，包含 `schema_version`、生成器版本、源编号范围和数量。
5. 使用临时文件校验完成后原子改名，固定 UTF-8、键序和换行。

**生成与验证**

```bat
python real_sync\scripts\generate_sales_training_cards.py --source "E:\知识库\追光小牛\专业知识库\销售培训知识卡库" --output real_sync\database\import_data\sales-training-cards.v1.json --report real_sync\database\import_data\sales-training-cards.v1.report.json --checksum real_sync\database\import_data\sales-training-cards.v1.sha256
node --test real_sync\scripts\sales_training_card_import.test.mjs
```

人工抽查初、中、高阶各一张母卡的 K/S/D/C，共 12 张。

## 任务 5：实现默认 dry-run 的导入与回滚 CLI

**文件**

- 新增：`real_sync/scripts/import_sales_training_cards.php`
- 修改：`real_sync/scripts/sales_training_card_import.test.mjs`

**先写测试**

- 无 `--apply` 时没有写 SQL。
- SHA-256、数据包版本、3/300 数量、字段、枚举或唯一索引不符时失败。
- 相同编码与内容为 `skip`；相同编码内容不同为 `update_pending`；相似内容仅报告人工复核。
- 默认 apply 只新增；更新还必须显式增加 `--allow-update`。
- 命名锁、备份、事务或断言失败均返回非零并释放锁。
- 应用一次后再次 dry-run 为新增 0、跳过 300。
- 回滚默认预演；遇到 `user_progress` 引用或模块内未知卡片时停止。
- 回滚只按数据包固定编码删除，不能按标题或时间范围泛删。

**实现**

1. 强制 `PHP_SAPI === 'cli'`，`require api/config.php` 并调用 `getDB()`。
2. 验证数据包 SHA-256、表结构、InnoDB、JSON 能力及两个 `uk_code` 唯一索引。
3. 完整比较模块和卡片字段，输出新增、跳过、待更新、冲突和相似项。
4. `--apply` 时依次执行：数据库命名锁、受限目录备份、备份校验、事务、二次冲突检查、写入、重算 `total_cards`、事务内断言、提交、释放锁。
5. 备份覆盖目标模块、目标卡片、相关 `user_progress`、表结构和导入前数量摘要；不在日志或进程参数输出数据库密码。
6. 事务内断言 3 个模块、300 张卡、四类型各 75、模块卡量 100/84/116、角色与难度映射、无悬空引用，以及旧 7 模块/56 卡未更新或删除。
7. 回滚使用同一数据包和 SHA-256；发现学习进度或未知卡片即停止。

**本地静态验证**

```bat
node --test real_sync\scripts\sales_training_card_import.test.mjs
```

PHP 语法、连接和隔离数据库行为在服务器临时路径验证。自动步骤禁止执行导入或回滚的 `--apply`。

## 任务 6：文档与本地验收

**文件**

- 新增：`docs/ops/sales-training-card-import-runbook.md`
- 更新：`README.md`

**内容**

1. 记录本地生成、哈希核对、服务器临时上传、语法检查、基线比对、dry-run、备份、正式 apply、验收和回滚命令。
2. 命令区分本地仓库路径与线上应用根目录 `/www/wwwroot/122.51.223.46`；线上命令使用 `api/...`、`scripts/...`、`database/...`，不能错误增加 `real_sync/`。
3. 不记录私钥路径、数据库密码、令牌或实际备份内容。
4. 执行新增测试和既有 drill 基线测试，检查 `git diff --check`。

## 任务 7：线上基线比对、临时验证与代码部署

本任务只部署代码和数据包，不写业务数据。

1. 计算本地以下文件 SHA-256：
   - `real_sync/api/config.php`
   - `real_sync/api/drill/TrainingAccessPolicy.php`
   - `real_sync/api/drill/training-modules.php`
   - `real_sync/api/drill/training-cards.php`
   - `real_sync/training-card.html`
2. 计算线上应用根目录对应旧文件 SHA-256；新文件应确认线上不存在。
3. 若旧文件哈希不同，下载到临时目录做逐项审阅，先合并线上热修，禁止覆盖。
4. 上传待发布文件到服务器临时目录并执行 `php -l`；校验通过后再按运行手册部署。
5. 只读核实 PHP/MySQL 版本、PDO、JSON、InnoDB、`GET_LOCK()`、三张表结构/索引、备份目录、磁盘空间、目标编码冲突和 `user_progress` 引用。
6. 部署后用销售、普通教练、JWT 管理测试身份验证权限矩阵，不在生产执行提交/重置测试。
7. 不运行任何带 `--apply` 的导入或回滚命令。

## 任务 8：线上 dry-run 与人工确认门

在应用根目录执行默认预演，命令不带 `--apply`：

```text
DATA_SHA256="$(cut -d ' ' -f1 database/import_data/sales-training-cards.v1.sha256)"
: "${REPORT_PATH:?先将 REPORT_PATH 设置为权限受限的报告文件路径}"
php scripts/import_sales_training_cards.php import \
  database/import_data/sales-training-cards.v1.json \
  --sha256 "$DATA_SHA256" \
  --report "$REPORT_PATH"
```

连续执行两次，报告必须一致，并显示：

- 计划新增 3 个模块、300 张卡。
- 更新 0、冲突 0。
- K/S/D/C 各 75。
- 三模块卡量 100、84、116。
- `role_code=consultant`。
- 无数据库写入、无命名锁遗留。

将代码基线哈希、数据包哈希、dry-run 报告、备份与回滚预检、鉴权和 XSS 测试结果交给用户。**只有用户明确批准后，才能进入任务 9。**

## 任务 9：独立执行 apply 与上线验收

此任务是风险操作，不与部署或 dry-run 串联执行。

1. 建立维护窗口，再核对代码和数据包 SHA-256。
2. 最后执行一次 dry-run，确认报告未变化。
3. 用户再次确认后，单独运行带 `--apply` 的导入命令。
4. 导入器内部完成锁、备份、事务、断言和结果清单。
5. apply 后再次 dry-run，应为新增 0、跳过 300、待更新 0、冲突 0。
6. 对比导入前快照，核实只增加 3 个模块和 300 张卡；`total_cards` 为 100、84、116，旧数据未变，无孤儿进度。
7. 抽查 12 张卡，并用销售、教练、管理三类身份验收权限。
8. 异常时先执行回滚预演；仅在无进度引用和未知卡片、且用户确认后执行回滚 `--apply`。

## 建议提交切分

1. `security: enforce training resource access`
2. `fix: safely render training card content`
3. `feat: generate sales training card package`
4. `feat: add dry-run sales training importer`
5. `docs: add sales training import runbook`

## 实施前自动核实项

以下项目不需要用户决策，但必须在对应任务中核实并记录：

- 线上 PHP、MySQL/MariaDB、PDO、JSON、InnoDB 和 `GET_LOCK()` 支持。
- `training_modules`、`training_cards`、`user_progress` 的完整结构、索引、引擎和引用关系。
- 线上应用路径映射和四个旧文件是否存在未入库热修。
- 服务器备份目录权限、磁盘空间、`mysqldump/gzip` 可用性；不可用时使用 PHP 参数化快照。
- 销售、教练、manager、admin 测试身份的 JWT 与员工岗位组合。
- 目标模块/卡片编码是否已存在以及相关 `user_progress` 是否异常引用。
- 所有生成字段是否满足线上实际长度。
