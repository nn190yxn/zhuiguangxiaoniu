# 追光小牛企业内网仓库

> 作者：Monkeycode

这是追光小牛企业内网项目的 GitHub 同步仓库。当前仓库用于承载线上业务源码、修复记录、项目文档和 AI 相关资料，真实业务基线以线上服务器为准。

## 真实基线

- 线上主机：`122.51.223.46`
- 线上运行目录：`/www/wwwroot/122.51.223.46/`
- 正式域名：`https://supercalf.com`
- 远端仓库：`https://github.com/nn190yxn/zhuiguangxiaoniu.git`

清理、同步和回收历史文件时，默认按“服务器 -> GitHub”的方向比对，避免用旧仓库内容覆盖线上正在运行的版本。

## 主开发目录

当前仓库里唯一的主业务目录是 `real_sync/`。后续阅读、修复、同步和提交通常都应从这里开始。

核心目录如下：

- `real_sync/api/`：PHP API，包含认证、权限、后台、工作量、学习、知识库、积分、考试、演练等接口
- `real_sync/mobile/`：员工 H5 页面
- `real_sync/mini-program/`：微信小程序主工程
- `real_sync/admin/`：总部运营后台页面
- `real_sync/scripts/`：只读核查、验收、排障、专项测试脚本
- `real_sync/database/`：数据库导入与数据调整脚本
- `real_sync/ENTRY_GUIDE.md`：主要页面入口说明

## 仓库内其他目录的定位

- `archive/`：历史归档和保守清理后的旧文件，只作追溯和比对
- `skills/`：AI skill 资产，仅保留 manifest 管理的可执行 skill
- `复盘标准/`：业务复盘类资料与模板原件
- `archive/`：包含历史归档，也包含从 `skills/` 收口出来的重复资料副本
- `.monkeycode/`：项目级记忆、交接文档和治理文档

## 当前运行形态

项目是一个混合系统，包含以下几层：

1. WordPress 站点与用户体系
2. 自定义 PHP API
3. 员工 H5 内网页面
4. 总部后台页面
5. 微信小程序

三端前端通常共用同一套 `/api/*.php` 接口。

## 仓库治理规则

为降低 GitHub 仓库继续被运行产物污染的风险，后续协作统一遵守以下口径：

1. 以 `real_sync/` 为唯一主线目录，避免在根目录散落新增业务代码。
2. 线上备份目录、上传目录、日志目录、临时截图和 `.bak` 文件保持本地或服务器存在，不进入 Git。
3. 涉及清理时优先归档，再决定是否从 Git 跟踪中移除。
4. 敏感配置、私钥、运行时 `.env` 文件始终留在本地或服务器，避免提交到仓库。
5. 修改线上相关代码前，先确认当前线上文件与仓库同路径文件是否一致。

## 重要文档

- `docs/superpowers/specs/2026-08-24-sales-training-card-import-design.md`：75 张销售培训母卡拆分导入一期设计
- `docs/superpowers/specs/2026-08-26-knowledge-card-phase2-design.md`：1417 张教练与训练知识卡二期设计规范
- `docs/superpowers/plans/2026-08-26-knowledge-card-phase2-implementation.md`：知识卡二期分阶段实施计划
- `docs/superpowers/plans/2026-08-24-sales-training-card-import-implementation.md`：销售培训卡一期实施计划
- `docs/ops/2026-08-24-sales-training-card-import-dry-run.md`：一期生产代码部署、只读预演与导入门禁记录
- `docs/repo/repo_cleanup_inventory_20260604.md`：上一轮仓库清理盘点与归档记录
- `docs/ops/server_to_github_final_sync_report.md`：服务器到 GitHub 的同步记录
- `docs/security/SECURITY_CLEANUP_NOTES_20260604.md`：敏感配置与安全清理说明
- `docs/audits/bug_audit_merged_report.md`：Bug 审计合并报告
- `docs/business/销售沟通录音点评报告_小汤圆体验课.md`：销售沟通录音点评资料
- `docs/README.md`：文档目录索引
- `archive/skills-duplicates-20260618/README.md`：`skills/` 与 `复盘标准/` 重复资料的收口说明
- `real_sync/ENTRY_GUIDE.md`：页面入口收口说明
- `.monkeycode/MEMORY.md`：项目级协作记忆

## 当前目标

当前这份仓库更适合承担三类任务：

1. 以线上服务器为基线做代码同步与治理
2. 对 `real_sync/` 主线业务代码做修复和升级
3. 沉淀项目交接、审计、验收和排障文档

## 任务记录

- 2026-08-24：完成培训卡页面安全结构化排版与独立渲染安全测试（作者：Monkeycode）。
- 2026-08-24：完成 75 张销售母卡到 300 张培训卡的确定性数据包生成与原子发布测试（作者：Monkeycode）。
- 2026-08-24：完成销售培训卡安全导入/精确回滚 CLI、29 项本地测试和独立复核（作者：Monkeycode）。
- 2026-08-24：完成一期线上代码备份与原子部署、双次只读 dry-run，并在明确授权后事务导入 3 模块/300 卡；提交后幂等与精确回滚预演通过（作者：Monkeycode）。
- 2026-08-26：完成 1417 张教练与训练知识卡二期设计规范；明确隔离摄取、版本来源、旧库人工合并、全员发布、权限与分阶段验收，尚未实施（作者：Monkeycode）。
- 2026-08-26：完成知识卡二期实施计划，用户确认执行核心开发及全部测试任务；生产变更仍按阶段单独授权（作者：Monkeycode）。
- 2026-08-26：完成二期任务1源数据契约、只读基线脚本和质量门禁测试；发现的 ORG-0001 重复及 ORG- 空编号已按授权修正为 ORG-0015/ORG-0016（作者：Monkeycode）。
- 2026-08-26：完成二期任务2确定性 Markdown 解析与隔离数据包；1417张卡严格校验通过，默认 publication_status=isolated，未进入员工可见发布面（作者：Monkeycode）。
- 2026-08-26：完成二期任务3知识库 schema 迁移草案、迁移清单和静态门禁；新增批次、版本、来源、关系、收藏、最近浏览、审计表及核心表扩展，未执行生产迁移（作者：Monkeycode）。
- 2026-08-26：完成二期任务4安全导入与精确回滚 CLI；v2隔离包携带1417张完整正文与原始Markdown，具备双哈希、dry-run、幂等、备份、事务、manifest、候选关系及回滚阻断，32项相关回归中30项通过、2项因未提供源目录跳过，未连接或写入生产数据库（作者：Monkeycode）。
- 2026-08-26：完成二期任务5知识访问权限边界修复；列表、分类、详情、搜索、演练、通关地图和阶段入口要求登录，知识及关联资源仅允许 `status=1 AND publication_status='published'`，客户端 `role/stage` 不再覆盖服务端身份，新增三项元数据筛选和权限/IDOR静态回归测试（作者：Monkeycode）。
- 2026-08-26：完成二期任务6个人学习功能边界；保留进度历史只读，停用知识完成/进度写入及积分奖励，新增发布知识收藏/取消收藏、收藏列表和最近浏览幂等记录/分页接口；下架知识的最近浏览历史保留但不返回内容元数据（作者：Monkeycode）。

## License

MIT
