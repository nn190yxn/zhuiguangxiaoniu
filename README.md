# 追光小牛企业内网仓库

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

## License

MIT
