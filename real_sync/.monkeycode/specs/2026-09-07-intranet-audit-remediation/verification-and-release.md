# 修复验证与发布准备

日期：2026-09-07。44 个运行文件已部署到 supercalf.com，备份及文件校验完成；本文件随修复提交。本次未执行生产数据修复或数据库迁移。

## 正式发布证据

- 备份目录：`/www/wwwroot/122.51.223.46/backups/intranet-fix-20260907-163435`。其中 `manifest.json` 记录每个文件更新前后的 SHA-256，新增文件的旧哈希为 null。
- 发布清单见 `runtime-files.txt`，44 个文件全部通过服务器部署后哈希检查，PHP 文件部署前语法检查通过。PHP-FPM 8.2 已平滑 reload。
- 公网核对 8 个页面和脚本均 HTTP 200 且与本地 SHA-256 一致：课程页面/脚本、教案页面、知识首页/详情页面、考试脚本、学习页面、演练页面。
- 学习、知识、考试、教案已知 GET 接口未登录返回 401；招聘处理接口 GET 返回预期的 405。未伪造登录身份或写入业务测试数据。
- 正式数据库只读事务核对必需教案、建议、知识年龄/版本、课程进度字段齐全。候选知识查询返回 1647 条；`3-4岁 游戏` 与 `3至4岁 游戏` 均匹配 111 条；专业/教学分类匹配 1 条，未出现 collation 错误。
- 招聘线上原接口已备份并对照：权限、批次范围、幂等动作及审计保持同一契约，批量调度改为服务内处理。
- 正式七套试卷题号、数量存在，但题干和部分选项与静态页面不同。考试已改为加载后台实际题目与元数据，作答快照支持刷新恢复及未知提交原请求重放；提交前继续检查题库变化。新增后的考试专项 34 项全部通过。

题库中部分选项已有截断，本次保持题库原数据；正确答案未由评分接口返回时，结果页明确说明。真实账号端到端、Worker/OCR/AI 及并发场景仍为待验收项。

## 验证证据

最终精确暂存集合导出到 `/tmp/opencode/intranet-staged` 后独立执行完整回归：1538 项，1529 通过、8 跳过、1 项因导出目录没有 Git 元数据而失败。按项目实际父目录建立隔离 Git 仓库后，平台预检专项 2 项均通过，唯一失败已消除，无代码变动。全量日志 `/tmp/terminal_term_1788798975761_27.log`。考试最终专项 34 项全通过。该验证覆盖已拆分的迁移登记与必要脚本，未依赖工作区其余未提交文件。

完整回归：1525 项，1517 通过、0 失败、8 跳过。所有模块交接后的最终代码参与此次回归，包含演练重练、考试重考及教案 activity 字段保存补修。

```bash
NODE_OPTIONS=--max-old-space-size=256 node --test --test-concurrency=1 $(rg --files scripts -g '*.test.mjs')
git diff --check
```

工作目录 `/workspace/real_sync`。后台日志 `/tmp/terminal_term_1788795061646_25.log`，退出码 0，耗时约 62.3 秒，峰值内存约 138 MiB。

8 个跳过项：真实 MySQL 副作用并发、真实 MySQL 迁移重放各 1 项；源报告及隔离知识包各 1 项；依赖原始销售知识卡资料的生成器 4 项。无真实数据源的检查不视为验收通过。

## 功能矩阵

| 角色/模块 | 本轮已执行的证据 | 仍待验证 |
| --- | --- | --- |
| 教练：教案 | 页面函数行为、SQLite 生产服务持久化、DOCX/XLSX 解析与工作簿读取验证；上传到提交、退回、再提交用例通过 | JWT 浏览器完整操作、MySQL 并发、Office 人工打开 |
| 员工：知识卡 | 分类、年龄、响应乱序、分页、历史版本的行为和 PHP 测试通过；正式 MySQL 只读混合排序规则查询通过 | 真实账号搜索到采纳 |
| 员工：学习与签到 | 21 条分页、旧响应丢弃、签到失败/重复点击、章节保存重试测试通过；新详情页和脚本本地 HTTP 200 | 实际课程资料、章节与积分事务 |
| 员工：考试 | 六模块 SQLite 成绩保存并由后台列表 SQL 查得；34 项专项通过；已核对线上题库并改用后台实际题目 | MySQL 并发幂等和真实后台显示 |
| 员工：演练 | 恢复、阶段、结果关联、必修重练及 SQLite 服务用例通过 | 语音、评分 Worker、报告发布与真实浏览器 |
| 管理员：招聘 | 已只读确认服务器存在独有处理接口；本地队列分批、去重、失败回滚测试通过 | 对照服务器接口语义、实际 Worker/OCR/AI 消费 |
| 教练：体测 | 101 条记录六页、筛选乱序、详情返回原页行为测试通过 | 真实 records API、报告内容和导出 |
| 管理员：系统 | 非零、零、数据源不可用状态测试通过 | 锁定字段写入机制及异常日志表的实际可用性 |
| 店长/管理员：员工、工作量 | 原有管理交互与契约测试包含于全量回归 | 真实组织范围、审批和导入等完整写入流程 |
| 员工：资料、制度、个人档案；管理员：企微 | 既有导航及相关契约测试包含于全量回归；此前所查资料目标存在 | 逐份资料内容、真实账号档案、企微同步和消息发送 |

静态预览使用既有终端 `term_1788619914032_2`、8001 端口。预览连接正常；`/mobile/course.html`、`/mobile/course.js`、`/pass-map.html`、`/lesson-submission.html`、`/knowledge/` 均为 200。静态服务不运行 PHP，页面可达证据不能代替登录业务验证。

## 本次运行文件清单

下列为本轮修复涉及的运行文件，含与原有未提交内容共用的文件；最终发布前须逐文件与服务器比较。不能直接将整个工作区作为发布包。

| 批次 | 文件（相对于项目根目录） |
| --- | --- |
| 教案 | `js/lesson-submission.js`；`api/lesson-submissions/LessonDraftService.php`、`LessonExportService.php`、`LessonKnowledgeMatcher.php`、`LessonSubmissionReviewService.php`、`LessonSubmissionService.php`、`LessonSuggestionService.php`、`optimize.php` |
| 知识卡 | `knowledge/knowledge.js`、`detail.js`、`detail.html`；`api/knowledge/EmployeeKnowledgeVisibilityQuery.php`、`KnowledgeListService.php`、`KnowledgeTaxonomy.php`、`detail.php`、`list.php` |
| 学习考试 | `mobile/learning.html`、新增 `mobile/course.html`、新增 `mobile/course.js`；`api/learning/detail.php`、`list.php`；`training/exam-common.js`；`api/exam/submit.php` |
| 招聘系统 | `admin/recruitment-resumes.html`、`admin/system-dashboard.html`；新增 `api/admin/recruitment/process.php`；`api/admin/recruitment/platform/RecruitmentPlatformJobAdapter.php`；`api/admin/recruitment/services/ResumeProcessingService.php`；`api/admin/security/login-audit.php`；新增 `api/admin/services/SystemOverviewService.php` |
| 演练体测 | `mobile/drill.html`；`api/drill/v2/attempts.php`；`api/drill/v2/services/DrillEmployeeApiService.php`、`DrillConversationService.php`；`fitness-assessment-app.html` |

本轮新增测试：`scripts/lesson_submission_behavior.test.mjs`、`lesson_remediation_service.test.php`、`knowledge_remediation.test.mjs`、`knowledge_remediation.test.php`、`learning_exam_remediation.test.mjs`、`training_exam_persistence.test.php`、`intranet_batch4_behavior.test.mjs`、`intranet_batch4_services.test.php`。相关现有测试也已同步；测试文件不属于网站运行发布文件。

## 发布依赖

本轮修复没有新增数据库迁移。工作区此前已有的教案上下文字段、知识年龄和 taxonomy 迁移不因此自动获得生产执行授权；须核对实际 schema 与 migration 状态。新代码可能依赖这些已有未发布内容，缺失时暂缓对应批次发布。

`lesson-submission.html`、共享认证封装、培训页面试卷配置及既有服务为运行依赖，须确认服务器版本匹配。官网首页、样式、分类报告 JSON 及其他原有 dirty 文件不自动纳入本次发布。

招聘服务器独有 `process.php` 已确认存在，原哈希记录在 tasklist；覆盖前须备份并对照其处理语义。系统指标取真实字段/表，缺失时显示不可用；本次没有创建不存在的日志来源或补造统计数据。

## 发布与回滚步骤

1. 在配置完备的隔离 PHP/MySQL 环境完成表格中的关键待验收流程，核对六套考试实际题目映射。阻断性失败修正后再发布。
2. 逐文件比较服务器与本地，冻结获准文件清单；保存线上原文件、哈希及新增文件清单到带日期备份目录。核对必需数据结构，新增迁移另行审批。
3. 同批前后端按契约一起更新，避免客户端拿到不兼容响应。更新相关脚本缓存版本或既有缓存策略，按服务既有机制刷新 PHP OPcache。
4. 核对上线文件哈希、已知页面状态、未登录 API 的正常登录提示；使用明确授权的测试身份做关键业务验收。知识详情 GET 会写浏览记录，不列入只读探测。
5. 出现故障时恢复本批原文件及缓存引用。新增文件可以保留为不可达文件，不执行删除；数据库及上传文件不随代码回滚。涉及数据变化时单独制定恢复方案，避免旧代码误读新状态。

## 存量数据

同目录 `existing-data-audit.sql` 仅包含限定时间范围的 SELECT，输出记录 ID 和状态，不输出正文或个人身份。脚本尚未对生产执行。污染候选只代表待复核，恢复应参考原始文件和前一版本，由业务负责人确认；保持原版本及审计记录。

纯前端考试期间未提交后台的成绩无法通过服务端可靠枚举，不能根据现有成绩数量推断丢失量或补造考试记录。
