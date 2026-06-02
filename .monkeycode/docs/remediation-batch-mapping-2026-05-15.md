# 审计问题到修复批次映射表

## 说明

本表用于把总台账中的问题编号映射到修复阶段、修复批次、备份范围和回滚方式。

## 字段定义

- 问题编号：对应总台账编号
- 所属阶段：A-H
- 修复批次：阶段内具体批次
- 修复主题：本次处理目标
- 备份范围：需备份的现网对象
- 回滚方式：失败时恢复方法

## 映射表

| 问题编号 | 所属阶段 | 修复批次 | 修复主题 | 备份范围 | 回滚方式 |
| --- | --- | --- | --- | --- | --- |
| 1, 2, 6, 136 | A | A3 | 旧 IP / HTTP 与正式域名统一 | `api/config.php`、小程序入口、主题页、相关配置 | 覆盖回退备份文件，复测域名与请求地址 |
| 4, 5, 53, 90, 247-250 | A | A1 / D | 弱口令传播与移动端旧入口收敛 | 登录页、模板页、问卷页、个人中心入口 | 覆盖回退备份文件，复测登录流程 |
| 8, 46-47, 59-76 | A | A2-1 / A2-2 | 高危测试/导入/修复脚本隔离 | 现网真实存在脚本 + 工作区/历史副本脚本 | 现网文件从备份目录恢复，工作区副本从 git/本地备份恢复 |
| 15-18, 23-25, 55-58, 92-110 | A/B | A4 / B1 | 高危后台写接口最小收口与正式权限统一 | 高危后台接口与公共鉴权层 | 覆盖回退接口文件，复测角色访问 |
| 3, 10, 120-122, 236-246 | B | B1 / B2 / B3 | 权限模型、鉴权方式、管理入口统一 | `api/common/context.php`、`api/admin/common.php`、`api/auth/me.php`、`internal-auth.js` | 回退公共层与页面入口文件 |
| 204-226, 229-246 | C | C1 / C2 / C3 | 首页职责、公共导航、返回链路统一 | `internal.html`、`internal-auth.js`、`js-auth.js`、中心页壳文件 | 回退首页壳和公共脚本，复测入口与导航 |
| 247-251 | D | D1 | 移动端正式 URL 收敛 | `mobile/*.html`、`mobile-*.html` | 恢复兼容页原件，取消跳转 |
| 252-255, 259 | E | E1 | 后台正式路由与副本映射统一 | `/admin/*` 正式页、平铺后台副本、目录副本 | 恢复后台首页和子后台原件 |
| 204-213, 222-231 | F | F1 / F2 | 内容中心、阅读器、目录壳职责统一 | `doc-viewer.html`、制度/知识/学习/表格中心壳页 | 回退中心页和阅读器页原件 |
| 207-213, 256-257 | G | G1 | 培训专题正式入口统一与平铺副本收敛 | `/training/*/index.html`、平铺专题页、`*.remote.html` | 恢复原专题页和兼容跳转 |
| 214-221, 227-235, 254 | H | H1 | 工具体系与活动页解耦 | 体测页、问卷页、表格中心、教案入口、周年庆入口页 | 恢复工具入口页和活动入口页 |

## H1-1 已完成

- 批次名：`h1-1-collapse-root-flat-copies-and-block-test-auth`
- 备份目录：`/www/mc-backups/20260516-h1-1-root-flat-copies/`
- 当前状态：`verified`
- 已完成动作：
  - `weekly-drills.html` 从根目录平铺副本收敛为指向 `/training-center/weekly-drills.html` 的兼容跳转页
  - `test-auth.html` 在 nginx 层封堵，返回 `403`
  - nginx 配置已备份并重载

## H1-1A 已完成

- 批次名：`h1-1a-restore-training-card-detail-page`
- 备份来源：`/www/mc-backups/20260516-h1-1-root-flat-copies/training-card.html`
- 当前状态：`verified`
- 已完成动作：
  - 已确认 `training-card.html` 为训练卡详情页正式入口，而不是目录壳副本
  - 已从备份恢复 `training-card.html`
  - 已恢复 `training-module.html -> training-card.html?id=...&module=...` 的正式链路

## H1-2 已完成

- 批次名：`h1-2-unify-tool-shell-auth`
- 备份目录：`/www/mc-backups/20260516-h1-2-tool-shell-auth/`
- 当前状态：`verified`
- 已完成动作：
  - `survey-manage.html` 已接入 `/internal-auth.js?v=5`，统一改用 `jwt_token/token`，并移除 `prompt + auth-jwt.php + 123456` 弱口令回退逻辑
  - `stats-center.html` 已补 `/api/auth/me.php` 登录态校验、HQ 权限前置与 Bearer 请求头
  - 两页线上返回码均为 `200`

## H2-1 已完成

- 批次名：`h2-1-collapse-learning-center-live-alias`
- 备份目录：`/www/mc-backups/20260516-h2-1-learning-center-live-alias/`
- 当前状态：`verified`
- 已完成动作：
  - `learning-center-live-index.html` 已从历史平铺入口收敛为指向 `/新员工学习/` 的兼容跳转页
  - `/新员工学习/` 已在现网层面明确为“员工学习中心”的唯一正式目录入口

## H1-3 已完成

- 批次名：`h1-3-unify-training-module-auth`
- 备份目录：`/www/mc-backups/20260516-h1-3-training-module-auth/`
- 当前状态：`verified`
- 已完成动作：
  - `training-module.html` 已补齐 `checkAuth()`、`getToken()`、`authHeaders()`
  - `/api/drill/*` 请求已统一带 Bearer token
  - 页面线上返回码为 `200`

## H1-4 已完成

- 批次名：`h1-4-clean-fitness-app-dead-login-shell`
- 备份目录：`/www/mc-backups/20260516-h1-4-fitness-app-dead-login-cleanup/`
- 当前状态：`verified`
- 已完成动作：
  - `fitness-assessment-app.html` 已删除无效 `loginPage`、`doLogin()`、空壳 `checkAuth()`
  - 保留 `buildAuthHeaders()` 和现有 Bearer 请求头调用逻辑
  - 页面线上返回码为 `200`

## GH-R1 已完成

- 批次名：`gh-r1-stage-g-h-recap-and-tail-scan`
- 当前状态：`verified`
- 已完成动作：
  - 已抽样验证阶段 G/H 已修入口返回码
  - 已确认 `test-auth.html` 继续返回 `403`
  - 已输出下一轮尾项清单，重点锁定根目录培训资料平铺页向 `/training-center/` 体系收口

## H2-2 已完成

- 批次名：`h2-2-collapse-training-center-root-aliases`
- 备份目录：`/www/mc-backups/20260516-h2-2-training-center-root-aliases/`
- 当前状态：`verified`
- 已完成动作：
  - 7 个根目录培训资料页已收口为指向 `/training-center/*.html` 的兼容跳转页
  - 7 个正式目录页与 7 个别名页线上返回码均为 `200`

## GH-R2 已完成

- 批次名：`gh-r2-root-tail-closure`
- 当前状态：`verified`
- 已完成动作：
  - 已确认 `coach-knowledge.html` 已处于正确的 `/知识库/` 兼容别名状态
  - 已确认 `internal-beta-checklist.html` 为独立内测验收页，继续保留
  - 根目录阶段 G/H 相关尾项已完成收口判断

## H2-3 已完成

- 批次名：`h2-3-collapse-mobile-root-learning-aliases`
- 备份目录：`/www/mc-backups/20260516-h2-3-mobile-root-aliases/`
- 当前状态：`verified`
- 已完成动作：
  - `learning.html` 已收口为指向 `/mobile/learning.html` 的兼容页
  - `pass-map.html` 已收口为指向 `/mobile/pass-map.html` 的兼容页
  - `profile.html` 已确认处于 `/mobile/mine.html` 兼容状态

## GH-R3 已完成

- 批次名：`gh-r3-block-wordpress-public-readme-license`
- 备份目录：`/www/mc-backups/20260516-gh-r3-wordpress-readme-license/`
- 当前状态：`verified`
- 已完成动作：
  - `readme.html` 已在 nginx 层封堵为 `403`
  - `license.txt` 已在 nginx 层封堵为 `403`
  - `wp-login.php` 保持 `200`

## H2-4 已完成

- 批次名：`h2-4-collapse-action-library-root-alias`
- 备份目录：`/www/mc-backups/20260516-h2-4-action-library-root-alias/`
- 当前状态：`verified`
- 已完成动作：
  - `training-cards-action-library.html` 已收口为指向 `/action-library/` 的兼容页
  - `lesson-library.html` 已确认处于 `/action-library/` 兼容状态
  - `/action-library/` 为已接统一鉴权的正式动作库入口

## GH-R4 已完成

- 批次名：`gh-r4-stage-g-h-root-closure-summary`
- 当前状态：`verified`
- 已完成动作：
  - 已完成阶段 G/H 根目录入口收口结果总复盘
  - 已确认“兼容别名页”和“保留正式页”的最终分类
  - 已明确后续重心应转向新的业务问题簇

## I1-1 已完成

- 批次名：`i1-1-stop-default-reset-password-propagation`
- 备份目录：`/www/mc-backups/20260516-i1-1-stop-default-reset-password/`
- 当前状态：`verified`
- 已完成动作：
  - `/admin/staffs.html` 已移除“留空则使用 123456”交互
  - `/mobile/admin.html` 已移除“重置密码为 123456”交互
  - 已确认后续应继续整体收口 `/mobile/admin.html` 历史移动后台壳

## I1-2 已完成

- 批次名：`i1-2-collapse-mobile-admin-shell`
- 备份目录：`/www/mc-backups/20260516-i1-2-collapse-mobile-admin-shell/`
- 当前状态：`verified`
- 已完成动作：
  - `/mobile/admin.html` 已收口为指向 `/admin/dashboard.html` 的兼容页
  - 已确认 `mobile/mine.html` 的后台入口本就指向 `/admin/dashboard.html`
  - 旧移动后台壳已停止继续独立承担后台职责

## I1-3 已完成

- 批次名：`i1-3-unify-internal-login-entry`
- 备份目录：`/www/mc-backups/20260516-i1-3-unify-internal-login-entry/`
- 当前状态：`verified`
- 已完成动作：
  - `/internal.html` 已移除站内直提 `/api/auth-jwt.php` 的旧登录提交流程
  - 员工首页登录入口已统一到 `/mobile/login.html?redirect=/internal.html`

## I1-4 已完成

- 批次名：`i1-4-unify-mobile-pass-map-auth`
- 备份目录：`/www/mc-backups/20260516-i1-4-unify-mobile-pass-map-auth/`
- 当前状态：`verified`
- 已完成动作：
  - `/mobile/pass-map.html` 已移除 `/api/auth-jwt.php?action=verify` 角色探测
  - 移动通关地图页的登录态探测已统一到 `/api/auth/me.php`

## I1-5 已完成

- 批次名：`i1-5-validate-mobile-login-token`
- 备份目录：`/www/mc-backups/20260516-i1-5-validate-mobile-login-token/`
- 当前状态：`verified`
- 已完成动作：
  - `/mobile/login.html` 已新增 token 有效性校验函数
  - 本地存在 `jwt_token` 时，现已先通过 `/api/auth/me.php` 验证后再跳转

## I1-6 已完成

- 批次名：`i1-6-unify-training-pass-auth`
- 备份目录：`/www/mc-backups/20260516-i1-6-unify-training-pass-auth/`
- 当前状态：`verified`
- 已完成动作：
  - `/training-pass.html` 已补齐 `getToken()`、`authHeaders()`、`checkAuth()`
  - `/api/drill/*` 请求已统一带 Bearer 认证头

## I1-7 已完成

- 批次名：`i1-7-unify-training-card-auth`
- 备份目录：`/www/mc-backups/20260517-i1-7-unify-training-card-auth/`
- 当前状态：`verified`
- 已完成动作：
  - `/training-card.html` 已补齐 `getToken()`、`authHeaders()`、`checkAuth()`
  - `/api/drill/training-cards.php?action=get` 与 `action=submit` 已统一带 Bearer 认证头
  - `site_current/training-card.html` 已同步到与主站一致的已验证版本

## I1-8 已完成

- 批次名：`i1-8-unify-smart-lessons-auth`
- 备份目录：`/www/mc-backups/20260517-i1-8-unify-smart-lessons-auth/`
- 当前状态：`verified`
- 已完成动作：
  - `/smart-lessons.html` 调用 `/smart-lessons-api.php` 时已改为优先复用 `window.authHeaders(...)`
  - 智慧教案页已补齐请求层对统一 token 壳的显式复用

## I1-9 已完成

- 批次名：`i1-9-enable-v4-sync-auth-gate`
- 备份目录：`/www/mc-backups/20260517-i1-9-enable-v4-sync-auth-gate/`
- 当前状态：`verified`
- 已完成动作：
  - `/v4-sync-center.html` 已移除 `window.__SKIP_AUTO_INTERNAL_AUTH__ = true`
  - V4 更新中心已恢复统一脚本自动执行的页面登录门禁

## I1-10 已完成

- 批次名：`i1-10-enable-static-centers-auth-gate`
- 备份目录：`/www/mc-backups/20260517-i1-10-enable-static-centers-auth-gate/`
- 当前状态：`verified`
- 已完成动作：
  - 一组静态正式目录页已移除 `window.__SKIP_AUTO_INTERNAL_AUTH__ = true`
  - `知识库/`、`表格中心/`、`training-center/`、`training-cards/` 已恢复统一脚本自动执行的页面登录门禁

## I1-11 已完成

- 批次名：`i1-11-frontend-admin-role-gates`
- 备份目录：`/www/mc-backups/20260517-i1-11-frontend-admin-role-gates/`
- 当前状态：`verified`
- 已完成动作：
  - `/admin/dashboard.html` 已新增总部角色前置拦截
  - `/admin/system-dashboard.html` 已新增超管角色前置拦截
  - 无权限员工进入后台首页类页面时将直接回到 `/internal.html`

## I1-12 已完成

- 批次名：`i1-12-frontend-admin-role-gates-extended`
- 备份目录：`/www/mc-backups/20260517-i1-12-frontend-admin-role-gates-extended/`
- 当前状态：`verified`
- 已完成动作：
  - `/admin/staffs.html` 已新增超管角色前置拦截
  - `/admin/performance.html` 已新增“总部或店长可看”的角色前置拦截
  - `staffs` 与 `performance` 的前后端权限口径已进一步对齐

## I1-13 已完成

- 批次名：`i1-13-frontend-superadmin-role-gates`
- 备份目录：`/www/mc-backups/20260517-i1-13-frontend-superadmin-role-gates/`
- 当前状态：`verified`
- 已完成动作：
  - `/admin/security-login-audit.html`、`/admin/security-devices.html`、`/admin/system-errors.html`、`/admin/operation-logs.html` 已新增超管角色前置拦截
  - 后台审计与安全域页面的前后端权限口径已进一步对齐

## I1-14 已完成

- 批次名：`i1-14-frontend-workload-role-gate`
- 备份目录：`/www/mc-backups/20260517-i1-14-frontend-workload-role-gate/`
- 当前状态：`verified`
- 已完成动作：
  - `/admin/workload.html` 已新增 `internal-auth.js` 接入与 `requirePageAuth()` 角色前置拦截
  - 仅允许总部/管理层角色进入页面壳，普通员工将直接回 `/internal.html`
  - 前后端权限口径已进一步对齐

## I1-15 已完成

- 批次名：`i1-15-unify-ai-tools-auth`
- 备份目录：`/www/mc-backups/20260517-i1-15-unify-ai-tools-auth/`
- 当前状态：`verified`
- 已完成动作：
  - `/ai-analyzer.html` 与 `/ai-drill.html` 已新增统一页面门禁与总部角色前置拦截
  - 两页关键 POST 请求已统一复用 `window.authHeaders(...)`
  - AI 工具页与后端总部权限口径已进一步对齐

## I1-16 已完成

- 批次名：`i1-16-unify-admin-upload-auth-gate`
- 备份目录：`/www/mc-backups/20260517-i1-16-unify-admin-upload-auth-gate/`
- 当前状态：`verified`
- 已完成动作：
  - `/admin-upload.html` 已移除 `__SKIP_AUTO_INTERNAL_AUTH__` 并统一改为 `requirePageAuth()` 驱动页面进入
  - 继续保留页面内部 `authHeaders()` 供业务请求复用
  - 资料上传页与后端总部权限口径已进一步对齐

## I1-17 已完成

- 批次名：`i1-17-collapse-ai-remote-aliases`
- 备份目录：`/www/mc-backups/20260517-i1-17-collapse-ai-remote-aliases/`
- 当前状态：`verified`
- 已完成动作：
  - `/ai-analyzer.remote.html` 与 `/ai-drill.remote.html` 已收口为指向正式 AI 工具页的兼容跳转页
  - AI 工具历史别名页的暴露面与维护分叉风险已进一步降低

## I1-18 已完成

- 批次名：`i1-18-unify-stats-center-auth-gate`
- 备份目录：`/www/mc-backups/20260517-i1-18-unify-stats-center-auth-gate/`
- 当前状态：`verified`
- 已完成动作：
  - `/stats-center.html` 已改为 `requirePageAuth()` 驱动页面进入
  - 总部角色前置判断已真正接入页面初始化链路
  - 数据统计中心与后端总部权限口径已进一步对齐

## 已完成批次

### A1

- 批次名：`p0-stop-weak-defaults-and-entry-drift`
- 已修问题：
  - 弱口令前端传播的一部分
  - 旧 `internal.html` 登录入口扩散问题的一部分
- 备份目录：`/www/mc-backups/20260515-182122-p0-stop-weak-defaults-and-entry-drift/`
- 当前状态：`verified`

### A2-1

- 批次名：`a2-1-block-high-risk-web-reachable-scripts`
- 备份目录：`/www/mc-backups/20260516-023800-a2-1-block-high-risk-web-reachable-scripts/`
- 当前状态：`verified`
- 已完成动作：
  - 在 nginx 层封堵 `/api/import_*.php`、`/api/init_*.php`、`/api/cleanup_*.php`、`/api/fix_*.php`
  - 封堵 `/api/admin/test.php`
  - 封堵 `/api/common/context-test.php`
  - 封堵 `/database/import_*.php`
  - 验证高危入口返回 `403`
  - 验证主入口页面保持 `200`

## 下一批建议

优先执行：A2-1

- 批次主题：现网真实残留高危脚本收口
- 原因：
  - 风险最高
  - 但必须以“线上真实存在”为前提推进，避免修错工作区副本
  - 不必立即进入大规模结构重构

## A2 当前事实补充

1. 已通过 SSH 对第一组高危脚本做现网存在性清点
2. 当前现网未返回这些文件，说明 A2 需要改为“双层收口”：
   - A2-1：现网真实残留脚本
   - A2-2：工作区/历史副本危险脚本
3. 第二轮 SSH + 远端 Python 扫描已确认 A2 相关高危脚本在线上真实存在，A2-1 可以进入正式实施单阶段

## A2-1 当前已确认线上真实目标

1. `/api/import_policies.php`
2. `/api/import_policies_v2.php`
3. `/api/init_training_data.php`
4. `/api/import_faq_knowledge.php`
5. `/api/import_faq_qa.php`
6. `/api/import_faq_to_training_cards.php`
7. `/api/cleanup_duplicate_cards.php`
8. `/api/fix_module1_duplicates.php`
9. `/api/admin/test.php`
10. `/api/common/context-test.php`
11. `/scripts/import_staff_cli.php`
12. 多个 `scripts/test_*` 和 `*_test.php`
13. `database/import_*.php`

## G/H 阶段现网新增事实

1. 现网根目录真实存在：
   - `fitness-assessment.html`
   - `survey-manage.html`
   - `learning-center-live-index.html`
   - `tables.html`
   - `stats-center.html`
   - `weekly-drills.html`
2. 现网 `/training/` 目录下真实存在大量 `*-index.html` 与 `*.index.html` 平铺副本
3. 说明后续 G/H 阶段不能只参考工作区 `real_sync` 副本，必须继续线上实扫

## G1-5 已完成

- 批次名：`g1-5-collapse-live-training-flat-copies`
- 备份目录：`/www/mc-backups/20260516-g1-5-training-flat-copies/`
- 当前状态：`verified`
- 已完成动作：
  - 现网 `/training/` 目录下 15 个培训专题平铺副本全部从完整专题页替换为兼容跳转页
  - 兼容跳转页目标统一指向对应 `/training/<topic>/` 目录页
  - 现网验证：所有正式目录页返回 `200`，所有已存在兼容跳转页返回 `200`
- 涉及文件清单：
  - `01-brand-index.html` -> `/training/01-brand/`
  - `02-role.index.html` -> `/training/02-role/`
  - `03-reception-index.html` -> `/training/03-reception/`
  - `03-reception.index.html` -> `/training/03-reception/`
  - `04-assessment-index.html` -> `/training/04-assessment/`
  - `04-assessment.index.html` -> `/training/04-assessment/`
  - `05-trial-index.html` -> `/training/05-trial/`
  - `05-trial.index.html` -> `/training/05-trial/`
  - `06-communication-index.html` -> `/training/06-communication/`
  - `06-communication.index.html` -> `/training/06-communication/`
  - `07-renewal-index.html` -> `/training/07-renewal/`
  - `07-renewal.index.html` -> `/training/07-renewal/`
  - `08-goals-index.html` -> `/training/08-goals/`
  - `08-goals.index.html` -> `/training/08-goals/`
  - `09-foundation-index.html` -> `/training/09-foundation/`
