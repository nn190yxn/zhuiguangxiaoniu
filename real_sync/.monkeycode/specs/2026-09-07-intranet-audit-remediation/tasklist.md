# 内网审计修复实施计划

日期：2026-09-07。状态：R01–R20 的本地代码修复已落地，复核新增的三项边界问题已补修；全量回归 1517 通过、0 失败、8 跳过。真实环境集成验收和正式发布尚未完成。

范围：本轮审计的 19 个功能问题及上传后自动生成建议这一需求缺口，共 20 项。目标是恢复完整业务流程，并补齐行为验证。

路径均相对于项目目录 `/workspace/real_sync`。现有未提交修改是实施基线，逐文件叠加修复，保留其他工作。不得以恢复 HEAD 的方式清除现有改动。

## 修复顺序与约束

按第一批至第五批执行；每批达到验收条件后记录实际结果。教案建议展示、采纳、版本流转完成后再接通自动生成，避免自动放大现有错误。知识卡详情与教案共用文件由同一实施单元负责。

所有任务的验收均为必做。现有静态契约测试通过只作为基础证据；业务完成需验证响应内容、页面操作结果及数据库持久化。集成测试使用隔离数据库和测试身份，生产验证优先使用只读请求。生产写操作、数据恢复和新增迁移须列明影响后单独确认。

不新增无关重构，不修改官网视觉，不自动提交或推送代码。优先复用现有认证、版本、匹配器、考试和 Worker 机制。

## 第一批：教案上传到审核

- [ ] R01 修复上传登录身份丢失：在 `js/lesson-submission.js` 的上传请求中复用既有认证头，保留进度事件；网络失败保留 submission ID 并支持重试，避免重复创建。验收：仅 JWT 登录时上传成功；过期身份清晰报错；失败重试复用原教案。

- [ ] R02 统一建议响应契约：调整 `LessonKnowledgeMatcher.php` 与前端渲染，刷新和详情返回一致的建议 ID、教案版本 ID、字段路径与建议正文；尽量复用数据库读取结果。验收：刷新后可直接采纳或忽略；空建议正常；旧版本响应不会覆盖新版本界面。

- [ ] R03 修复采纳污染正文：在 `js/lesson-submission.js` 通过结构化建议数据获取应用内容，明确追加或替换语义；复用字段验证处理文本和数组。验收：只有目标字段发生预期变化，问题说明和理由不混入正文，双击不产生重复版本。

- [ ] R04 修复建议版本与提交阻塞：联合调整 `LessonSuggestionService.php`、`LessonDraftService.php` 和 `LessonSubmissionReviewService.php`。历史建议保留供追溯，仅当前版本有效待处理建议阻止提交；采纳或保存后，为新版本重新评估建议并去重，刷新当前版本和状态版本。验收：多条建议连续采纳/忽略、保存后重新处理、历史 pending 存在时均可正确提交；当前有效问题仍阻止提交，冲突不覆盖他人修改。

- [ ] R05 接通上传后自动建议：在 R02–R04 完成后接通“解析成功→为解析版本生成建议→加载详情”。生成失败保留已解析教案，呈现失败状态及重试入口；使用既有去重机制防止重复建议。验收：DOCX/XLSX 上传后无需额外点击即可看到建议或明确无建议；失败可重试且不重复创建教案或建议。

- [ ] R06 修复 Word 导出 Excel：在 `LessonExportService.php` 按来源文件类型和工作簿结构选择导出路径；DOCX 使用结构化导出，合法 XLSX 才保留原工作表。验收：DOCX、XLSX、无原文件和损坏原件有明确结果；生成文件可被工作簿读取器打开且内容完整。

- [ ] G01 完成第一批验证：扩展已有 `lesson_submission_*`、`lesson_knowledge_matcher`、`lesson_export_contract` 和 `lesson_lifecycle_e2e.php` 对应测试，覆盖上传、建议生成、连续采纳、保存、提交、审核退回、再次提交和导出。记录通过/失败/跳过及实际数据库验证证据。

第一批实施证据（2026-09-07）：R01–R06 已有本地代码修复；以下为本地验证证据，R01–R06/G01 的集成验收复选框保持未勾选。保留当前 dirty 改动，未提交、推送、部署或写入生产。

| 项目 | 代码与本地验证证据 |
| --- | --- |
| R01 | `js/lesson-submission.js` 上传复用认证头，保留进度、处理超时及 401；失败复用教案 ID、已上传文件和创建幂等键。`lesson_submission_behavior.test.mjs` 验证 JWT 头、上传失败重试只有一次创建及过期提示。 |
| R02 | `LessonKnowledgeMatcher.php` 刷新复用 `LessonDraftService.php` 详情 DTO，`optimize.php` 校验绑定版本；页面丢弃旧版本响应。SQLite 比较刷新/详情建议 ID、版本、正文及处理状态；页面行为测试验证乱序保护。知识引用链接携带绑定知识版本的 `knowledge_version_id` 和 `version_id`。 |
| R03 | 前端通过 DTO 正文采纳，文本追加/替换、数组去重；后端限制目标字段及版本冲突。行为测试验证问题/理由未混入正文、双击只有一次请求、旧建议拒绝应用；SQLite 验证采纳版本及重复采纳冲突。 |
| R04 | 保存/采纳事务内重新评估，未变字段继承忽略决定，仅当前版本 pending 阻止提交；SQLite 验证历史 pending 保留、当前 pending 阻塞、保存、退回及再次提交。P2 复核修复：编辑器在当前渲染阶段快照上回写，保留已有 `activity`/`content`/`description` 字段与其他阶段属性。新增前后端串联用例，将真实 `readContent()` 输出传入生产草稿/匹配服务：仅修改反思时 `phases.0.activity` 建议保持唯一且为 ignored、处理人/时间保留；真实修改 activity 后重新 pending。匹配器身份规则保持不变。 |
| R05 | `LessonSubmissionService.php` 在 DOCX/XLSX 解析后自动优化；建议失败保留 editable 版本，解析重试复用版本。SQLite 测试实际执行两种格式解析，并模拟知识来源不可用后恢复，验证失败状态、恢复重试和版本不变。 |
| R06 | `LessonExportService.php` 按来源类型和工作簿结构分流，追溯原始 XLSX，防止追加工作表名称/编号冲突。导出测试覆盖 DOCX、XLSX、无原件、损坏原件；生成 Excel 由 `LessonWorkbookParser` 实际打开并核对正文及原工作表保留。 |

G01 测试记录：初轮教案全套出现上传测试服务 CLI 判断错误，修正测试入口后全套 `node --test --test-concurrency=1 scripts/lesson*.test.mjs` 为 93/93 通过，0 失败、0 跳过，日志 `/tmp/terminal_term_1788794295384_24.log`。`php scripts/lesson_remediation_service.test.php` 三组 PASS，包含 SQLite 实际持久化与自动建议恢复。9 份修改 PHP 的 `php -l`、编辑器 `node --check` 和限定本轮文件的 `git diff --check` 通过。

P2 修复后专项命令：

```bash
node --test scripts/lesson_submission_behavior.test.mjs scripts/lesson_submission_editor_contract.test.mjs scripts/lesson_knowledge_matcher.test.mjs scripts/lesson_suggestion_version.property.test.mjs
php -l scripts/lesson_remediation_service.test.php
node --check js/lesson-submission.js
git diff --check -- js/lesson-submission.js scripts/lesson_submission_behavior.test.mjs scripts/lesson_remediation_service.test.php .monkeycode/specs/2026-09-07-intranet-audit-remediation/tasklist.md
```

P2 专项结果：29/29 通过，0 失败、0 跳过；PHP/JavaScript 语法及差异格式检查通过。本次仅修改编辑器阶段字段保存、两份行为测试和本任务证据，未重跑整个教案测试集，以上 93/93 为 P2 修复前的全套记录。

G01 遗留边界：尚未执行真实 JWT 身份浏览器 HTTP 联调、MySQL 并发及生产数据验收；前端串联测试执行生产页面函数并模拟 DOM，持久化使用隔离 SQLite。上传重试状态仍在页面内存，整页刷新续传未实现；Office 尚待桌面 Excel/Word 人工验收；历史污染正文未自动修复。G01 保持未完成。

## 第二批：知识卡搜索与引用

- [ ] R07 修复分类筛选：`knowledge/knowledge.js` 发送 taxonomy 中的规范代码，后端保持统一映射；无效代码返回明确结果，避免静默扩大结果集。验收：逐个分类结果均符合分类条件，主分类切换后失效子分类被清理。

- [ ] R08 修复搜索响应乱序：在 `knowledge/knowledge.js` 使用请求序号或取消机制；请求绑定条件快照，刷新重置分页，旧响应和旧 finally 不改变新请求状态。验收：快速连续搜索、切换年龄、切换分类及加载更多时结果和 URL 一致，无跨条件混页或重复卡片。

- [ ] R09 修复年龄同义搜索：`KnowledgeListService.php` 统一规范年龄条件，移除冲突的原始年龄文本等值限制；保留搜索中的其他关键词与已确认年龄条件，兼顾现有数据库排序规则。验收：“3-4岁游戏”与“3至4岁游戏”在相同数据上结果一致，组合筛选准确，跨表比较不报 collation 错误。

- [ ] R10 修复历史知识引用：建议详情链接携带绑定的 knowledge_version_id；详情接口及页面读取指定版本，复用既有可见性规则。无版本参数时仍显示当前版本；历史版本不可用时明确提示。验收：引用 V1 后卡片升级 V2，引用仍展示 V1，普通搜索详情显示 V2。

- [ ] G02 完成第二批验证：扩展知识卡行为测试，覆盖真实筛选结果、响应乱序、分页、年龄同义词、历史版本及“搜索→详情→引用→应用”的连续流程；与第一批联合回归，不能只断言源码中存在关键词。

第二批实施证据（2026-09-07）：R07–R10 已修复规范分类 code、请求序号与分页去重、年龄同义词及历史 version_id 详情读取；历史字段直接取绑定版本快照，不回落到当前正文。`knowledge_remediation.test.mjs` 及对应 PHP 用例已加入，知识专项 49/49、教案关联专项 14/14 通过。教案引用链接已同步携带 version_id，实际混合 collation MySQL 数据与真实身份浏览器连续流程仍待验证。

## 第三批：学习入口与考试持久化

- [ ] R11 补齐课程详情入口：核对 `mobile/learning.html` 与现有学习详情 API，复用可用页面；无合适页面时实现 `mobile/course.html`，包含课程信息、内容、进度和返回入口。验收：有效课程可学习并保存进度；无效 ID、空内容、未登录均有可理解状态，页面和资源无 404。

- [ ] R12 修复通关地图路径：将学习中心入口统一指向实际 `/pass-map.html`，验证返回导航与登录跳转。验收：所有地图入口可达，身份与回跳正确。

- [ ] R13 修复签到请求配置：将认证头放入 Fetch 的 `headers` 字段，复用现有请求封装并处理失败响应。验收：仅 JWT 登录可以签到，重复点击/当天重复签到不重复发积分，失败时不显示成功。

- [ ] R14 补齐课程分页：消费 `total/page/page_size` 并提供加载更多或分页；切换分类重置页码并丢弃旧响应。验收：至少 21 条课程时全部可访问，末页不会重复加载，空分类正常。

- [ ] R15 接通培训考试提交：`training/exam-common.js` 复用已有 `/api/exam/submit.php` 流程；核对第 03–08 模块试卷与题目 ID 映射、后台评分及记录查询。后台确认成功后显示已提交；失败保留答案并允许重试，使用既有幂等约定。验收：每个模块提交后刷新可查记录，重复请求只有一份成绩，失败不伪装成功。

- [ ] G03 完成第三批验证：覆盖学习入口、21 条课程分页、签到与考试持久化，执行认证导航、考试幂等、移动端页面相关回归；验证后台能查询测试成绩。

第三批实施证据（2026-09-07）：R11–R15 已有本地修复，保持待集成验收。新增 `mobile/course.html`、`mobile/course.js`，接入课程/章节读取与幂等完成；详情 SQL 修正章节进度关联，列表限制分页参数并固定同序课程排序。学习中心通关入口统一 `/pass-map.html`，签到通过认证请求封装携带 headers，课程支持加载更多及旧响应丢弃。

考试共用脚本接入 `exam/submit.php`，提交前对比后台题目 ID、正文、题型、分值和选项；03–08 页面配置分别为试卷 2–7。提交快照按用户与试卷存于 sessionStorage，网络失败/刷新重试复用相同请求和幂等键；明确回滚响应允许新请求，未知结果超过既有 24 小时幂等期限要求人工核对。后台确认成功后保存成绩编号并展示服务端评分。共用脚本也用于 01-brand，随本次同步生效。

验证命令：`node --test scripts/learning_exam_remediation.test.mjs scripts/exam_submission_idempotency_contract.test.mjs scripts/internal_auth_navigation_contract.test.mjs scripts/mobile_pwa_accessibility.test.mjs scripts/mobile_pwa_shell.test.mjs scripts/platform_idempotency.property.test.mjs scripts/platform_idempotency_contract.test.mjs`：44/44 通过，0 跳过。新增行为测试包含 21 条分页、分类乱序、签到双击与失败、六模块断网后刷新重试、试卷不匹配阻止提交、回滚后重试、章节保存重试。`training_exam_persistence.test.php` 在隔离 SQLite 执行生产 `ExamSubmissionService`，六模块写入六份成绩，执行管理后台原列表 SQL 查得六份记录。修改的三份 PHP 语法、两份 JavaScript 语法及 `git diff --check` 通过。

限制：SQLite 试卷夹具取自仓库页面，仅证明服务评分与后台查询连接关系；当前缺少 `TEST_DB_HOST/NAME/USER/PASSWORD`，尚未核对实际后台数据库试卷 2–7 和题目数据，未执行 MySQL 并发幂等、章节/签到真实积分事务以及真实身份浏览器联调。G03 保持未完成。本批未修改教案、知识卡、演练和管理文件，未执行提交、推送、部署、生产写入或网络扫描。

第三批 P2 复核补修（2026-09-07）：`training/exam-common.js` 在已确认成绩页增加“重新考试”操作，确认后原子替换本地草稿，清空答案、已完成结果与旧 pending，重新计时；新提交生成新幂等键。计时起点写入 sessionStorage，刷新后继续本次计时。提交中或结果未知时禁止重考，继续重放原请求；取消重考或本地存储写入失败保留旧状态。新增两项行为测试验证不及格刷新后重考、新键、新计时、旧答案清除、存储失败及未知结果保护。上述同一组回归现为 **46/46 通过，0 跳过**；JavaScript 语法与 `git diff --check` 通过。实际 MySQL 与真实身份浏览器联调限制保持不变，G03 继续待验收。

## 第四批：招聘、演练、体测与系统概览

- [ ] R16 恢复招聘批次处理：先只读核对服务器是否存在独有 `process.php` 及对应路由，再复用当前批次/Worker 服务补齐接口或更正前端调用。验收：待处理批次能进入处理队列，空批次明确提示，重复触发不重复处理，失败可重试；禁止复制第二套简历处理引擎。

- [ ] R17 修复演练刷新恢复：`mobile/drill.html` 使用已有 resume 能力恢复 turns、practice_context、stage_progress；状态轮询仅更新其负责字段。验收：已有多轮对话刷新后内容、阶段和 attempt ID 保持一致，不重复创建演练或发送消息。

- [ ] R18 补齐演练结果数据：对齐 `DrillEmployeeApiService.php` 结果响应与前端需要的 recommendations、review、growth、media，核对真实关联数据源。验收：存在复核/推荐时展示真实记录，未生成与请求失败分别提示，不用默认值掩盖失败。

- [ ] R19 补齐体测历史分页：`fitness-assessment-app.html` 发送页码并消费列表总数，新增分页交互，筛选变化重置结果。验收：101 条以上记录可逐页访问，详情对应正确，空结果和末页正常。

- [ ] R20 接通系统概览指标：核对锁定账号的有效统计定义及现有数据源，接入真实锁定数量与系统异常数量；无法获取时显示暂不可用。验收：有数据时数量正确、无数据时为零、查询失败不补零。

- [ ] G04 完成第四批验证：补充招聘接口存在性及队列行为、演练恢复与关联报告、101 条体测分页、系统指标成功/失败测试；执行相关现有回归并记录结果。

第四批实施证据（2026-09-07）：R16–R20 已完成本地修复与专项验证，保持待集成验收。此次保留其他批次已有修改，未执行提交、推送、部署或生产写入。

| 项目 | 本地修复与证据 | 待验收边界 |
| --- | --- | --- |
| R16 | 只读确认服务器存在独有 `api/admin/recruitment/process.php`，文件 SHA-256 为 `de2a4d5b063db47f9d7bd6734d34e87c71a3c0dd8fef9a875d6d90cbeed67884`。本地补齐接口，保留权限、批次范围、幂等及审计；复用统一队列。SQLite 执行生产 adapter，验证 100+1 条分批投递、批次隔离、重复触发、空批次、失败回滚与 dead_letter 原任务重试。 | HTTP 权限和幂等入口目前为静态接线检查；未执行 MySQL 并发投递、真实 Worker/OCR/AI 消费或完整人工重试链路。 |
| R17 | 恢复调用完整 resume，保留多轮消息、上下文、阶段和版本；轮询限制合并字段，文本发送防重复。统一必修创建接口及页面的 `attempt_id`。生产 resume 服务的 SQLite 测试验证重复恢复不写入实例或消息；页面行为测试验证已有实例恢复、新建实例使用同一 ID、恢复失败保留内容。 | 未执行真实身份浏览器刷新、录音上传、暂停恢复和断网后的端到端联调。 |
| R18 | 按最新评估关联对应已发布报告、真实推荐证据、复核、当前关联成长及录音；修正成长查询使用 `scope_type/scope_key`。SQLite 验证实际关联结果、文本录音排除、失败与空记录区分；页面验证请求失败重试及数据不完整提示。已核对相关迁移字段和成长服务写入口径。 | 成长数据为仍引用本次演练的当前聚合；历史快照未补造。实际 MySQL 数据及评分生成到报告发布链路待验证。 |
| R19 | 页面消费既有 `X-Records-Total`，每页 20 条，筛选重置并丢弃旧响应。页面函数行为测试访问 101 条数据共六页，验证末页限制、详情返回原页、筛选乱序、空结果与分页契约缺失提示。 | 列表响应使用测试替身；真实 records API 权限、数据和浏览器交互待验证。 |
| R20 | 统计未到期 `staffs.account_locked_until` 及当天 `system_error_logs` 的 error/critical/fatal；查询失败返回 null/unavailable。SQLite 和页面行为测试验证非零、真实零、缺表不可用。 | 仓库仅发现锁定字段的解锁入口，未发现锁定写入机制；异常日志接口预留了表不存在状态，未发现该表建表和写入实现。未新增迁移，实际数据源有效性待环境核验。 |

G04 专项命令：

```bash
node --test scripts/intranet_batch4_behavior.test.mjs scripts/drill_mobile_pwa.test.mjs scripts/drill_employee_api_contract.test.mjs scripts/drill_conversation_services.test.mjs scripts/drill_review_growth_services.test.mjs scripts/drill_media_services.test.mjs scripts/fitness_report_records.test.mjs scripts/recruitment_resume_pipeline.test.mjs scripts/recruitment_resume_workbench.test.mjs scripts/recruitment_resume_upload.test.mjs scripts/recruitment_platform_adapter.test.mjs
php scripts/intranet_batch4_services.test.php
git diff --check
```

结果：Node 专项 100/100 通过，0 失败、0 跳过；其中 PHP runner 执行 21 项 SQLite 内存数据库断言（包含在上述专项中，不另计为 21 个 Node 测试）。8 份相关 PHP（包含新增测试）及四个修改页面的 7 段内联 JavaScript 语法检查通过；`git diff --check` 通过。缺表测试产生两条预期 `no such table` 日志，用于证明指标查询失败返回不可用。

G04 保持未完成：上述证据覆盖本地服务执行、数据库夹具持久化与页面函数行为；真实 MySQL、认证 HTTP、浏览器和外部 Worker 联调尚未执行。下一验收步骤是在配置完备的隔离 PHP/MySQL 环境，用测试身份完成招聘投递/重试、演练恢复/结果、体测分页和系统指标端到端验证。

第四批 P1 复核补修（2026-09-07）：修复 `startAssignment` 仅凭 `current_attempt_id` 恢复旧报告的问题。任务接口追加当前实例的状态、`plan_item_id` 和状态版本，关联限制为同一任务及同一员工。页面仅在任务为 `in_progress/ai_evaluating`、计划项相同且实例为 `active/paused/turn_finalizing/evaluating` 时恢复；暂停实例先带版本执行 `resume_paused`。`retry_available` 明确创建新实例，按钮显示“重新练习”；创建事务将任务更新为 `in_progress` 并绑定新 ID，后续点击可恢复新实例。待复核、已通过和取消任务显示状态提示。

P1 验证：新增四项页面行为测试并强化进行中恢复用例，覆盖失败后重练的 `create → resume(新ID) → 再次resume(新ID)`、终态实例、不同计划项、暂停/提交中/评分中恢复、任务状态限制及按钮文案。SQLite 执行生产任务查询，新增四项断言验证可重练任务保留旧 ID 时返回真实终态、计划项/版本、活跃状态以及跨任务/员工关联隔离。上述 G04 同一组命令现为 **104/104 通过，0 失败、0 跳过**；PHP runner 共 25 项 SQLite 断言。修改的三份 PHP（包含测试）及演练页面脚本语法、`git diff --check` 均通过。页面请求使用替身；完整创建事务的 MySQL 持久化与真实身份浏览器重练仍待集成验收，G04 状态保持不变。

## 第五批：全面回归与发布准备

- [ ] G05 补齐全站功能矩阵：从导航和路由列出员工、教练、店长、管理员的现有功能入口，标记页面可达、接口响应、关键操作、持久化及异常状态的验证证据。补查企微、员工管理、工作量、制度、资料、个人档案的代表性完整流程；新发现的问题加入本清单，不能用未测试代替通过。

- [ ] G06 检查存量数据影响：提供只读核查，识别历史 pending 建议、可能被错误采纳污染的版本及缺失考试记录的范围。输出可恢复来源与修复候选；保留原文和审计历史，不自动改写正式教案，不凭前端提示补造历史成绩。实际数据修复需单独审批。

- [ ] G07 执行完整验证：在有正确配置的 PHP/MySQL 环境验证上述业务流程；从项目目录执行现有完整 Node 测试集、修改 PHP 文件语法检查和 `git diff --check`。注明外部服务不可用、身份缺失及被跳过的用例；测试套件原有缺陷与本次回归分别记录。

- [ ] G08 准备发布检查产物：生成本次改动文件清单、必要迁移顺序、备份对象、回滚步骤和只读上线验证脚本；引用既有部署约定。每批发布须保证前后端契约同步，新增迁移单独审核；发布前后核对文件哈希及真实入口响应。

## 完成标准

第五批本地结果（2026-09-07）：完整 Node 回归命令为 `NODE_OPTIONS=--max-old-space-size=256 node --test --test-concurrency=1 $(rg --files scripts -g '*.test.mjs')`，在项目目录执行。共 1525 项，1517 通过、0 失败、8 跳过，约 62.3 秒；日志 `/tmp/terminal_term_1788795061646_25.log`。本地静态预览的课程详情、详情脚本、通关地图、教案页和知识首页均返回 200。

`verification-and-release.md` 已整理功能验证矩阵、发布文件、既有依赖和回滚边界；`existing-data-audit.sql` 提供限定时间范围的只读教案历史检查，尚未对生产执行。G05–G07 保持待完整验收；G08 的发布准备文档已完成，实际发布尚未执行。复选框代表完整验收，不等同于本地代码实施状态。

20 项均有修复代码、对应验证和结果记录。教案上传自动建议、采纳、提交完整闭环；知识卡筛选准确且引用可追溯；考试记录可持久化；缺失入口恢复；其余问题逐项关闭。未完成外部服务联调或真实身份验证的项目保持待验收状态。

估算：第一批 8–12 工程小时，第二批 4–6 小时，第三批 6–10 小时，第四批 6–10 小时，第五批 4–8 小时；合计约 28–46 工程小时。课程详情、招聘接口的实际缺失范围和现有集成环境可用性是主要变量，此估算不包含等待外部服务或人工审批。
