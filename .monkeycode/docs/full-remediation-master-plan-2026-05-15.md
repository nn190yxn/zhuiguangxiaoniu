# 追光小牛企业内网现网全面修复主方案

## 1. 文档信息

- 日期：2026-05-15
- 真实基线：`/www/wwwroot/122.51.223.46/`
- 审计总台账：`/workspace/服务器现网全面审计与API专项问题清单_2026-05-15.md`
- 当前目标：围绕总台账全部问题制定可执行、可备份、可回滚、可验收的全面修复蓝图

## 2. 修复总原则

本次全面修复必须遵守以下铁律：

1. 每一批修复必须先备份
2. 每一批修复必须有独立回滚方案
3. 每一批修复必须缩小变更面
4. 每一批修复必须先验证，再进入下一批
5. 未确认“正式入口 -> 现网真实文件 -> 工作区维护副本”映射前，不允许大规模重构
6. 失败时必须即时回滚，不允许线上试错式推进

## 3. 修复总目标

本次修复不追求一次性把所有页面改成理想架构，而分三层推进：

1. 先止血：消灭 P0/P1 安全暴露与错误入口扩散
2. 再统一：统一鉴权、导航、入口、后台和内容路由
3. 最后收口：清理历史副本、平铺专题页、活动侵入和长期结构债务

## 4. 变更治理规范

### 4.1 备份规范

线上备份目录统一格式：

`/www/mc-backups/YYYYMMDD-HHMMSS-批次名/`

每批次至少备份：

1. 被修改的现网原始文件
2. 关联入口页与公共脚本
3. 如涉及配置，备份配置文件
4. 如涉及数据修复，导出修复前 SQL 或定义可逆操作

### 4.2 回滚规范

每批次必须提前写清：

1. 回滚源文件路径
2. 回滚覆盖目标路径
3. 回滚触发条件
4. 回滚后验证步骤

### 4.3 验收规范

每批次必须至少验证：

1. 未登录访问
2. 普通员工访问
3. 管理角色访问
4. 页面入口跳转
5. 返回链路
6. 浏览器控制台错误
7. HTTPS 外部可见行为
8. 如有接口，验证返回结构与权限边界

## 5. 总台账状态规范

建议后续把总台账问题逐步标记为：

1. `pending`
2. `in_progress`
3. `fixed_waiting_verify`
4. `verified`
5. `rolled_back`
6. `deferred`

## 6. 全面修复分阶段设计

### 阶段 A：P0 安全止血

目标：先停止继续扩散的风险面。

覆盖问题类型：

1. 默认密码传播
2. 高危测试脚本、导入脚本、修复脚本暴露
3. 旧 IP / HTTP 地址残留
4. 高危后台写接口鉴权分裂

建议拆分为 4 个批次：

#### A1. 弱口令传播与旧入口扩散止血

目标：停止前端继续传播 `123456` 与旧登录入口。

已执行：

- `p0-stop-weak-defaults-and-entry-drift`

备份目录：

- `/www/mc-backups/20260515-182122-p0-stop-weak-defaults-and-entry-drift/`

#### A2. 生产目录高危测试/导入/修复脚本隔离

目标：把 Web 可达目录中的一次性脚本、导入脚本、修复脚本、测试脚本迁出或改为不可达。

涉及问题：

- 8, 46, 47, 59-76

涉及目录：

- `api/import_*`
- `api/init_*`
- `api/cleanup_*`
- `api/fix_*`
- `api/admin/test.php`
- `scripts/check_*`
- `scripts/verify_*`
- `tmp_*`

当前现网状态补充：

1. 已通过 SSH 对以下现网目标做第一轮存在性清点：
   - `api/import_faq_knowledge.php`
   - `api/import_faq_qa.php`
   - `api/import_faq_to_training_cards.php`
   - `api/import_policies.php`
   - `api/import_policies_v2.php`
   - `api/init_training_data.php`
   - `api/cleanup_duplicate_cards.php`
   - `api/fix_module1_duplicates.php`
   - `api/admin/test.php`
2. 当前 SSH 清点结果未返回任何存在文件，说明：
   - 这些文件可能已经不在现网正式目录中
   - 或当前总台账中的部分问题对象主要存在于工作区副本/历史镜像，而非当前线上目录

第二轮现网真实扫描结果补充：

1. 已通过 SSH + 远端 Python 方式再次扫描，确认以下高危脚本在线上真实存在：
   - `/www/wwwroot/122.51.223.46/api/import_policies.php`
   - `/www/wwwroot/122.51.223.46/api/import_policies_v2.php`
   - `/www/wwwroot/122.51.223.46/api/init_training_data.php`
   - `/www/wwwroot/122.51.223.46/api/import_faq_knowledge.php`
   - `/www/wwwroot/122.51.223.46/api/import_faq_qa.php`
   - `/www/wwwroot/122.51.223.46/api/import_faq_to_training_cards.php`
   - `/www/wwwroot/122.51.223.46/api/cleanup_duplicate_cards.php`
   - `/www/wwwroot/122.51.223.46/api/fix_module1_duplicates.php`
   - `/www/wwwroot/122.51.223.46/api/admin/test.php`
   - `/www/wwwroot/122.51.223.46/api/common/context-test.php`
   - `/www/wwwroot/122.51.223.46/scripts/import_staff_cli.php`
   - 多个 `scripts/test_*`、`scripts/*_test.php`、`scripts/auth_test.php`、`scripts/functional_test.php`
   - `database/import_*.php`
2. 说明第一轮未命中并非问题不存在，而是扫描方式不稳定；第二轮结果应作为 A2-1 的正式执行基线。

因此 A2 必须拆成两个子批次：

##### A2-1. 现网真实残留脚本收口

目标：

1. 只处理当前线上真实存在的高危残留脚本
2. 先建立“现网存在清单”，再做迁出或不可达化

执行策略：

1. 不直接删除文件
2. 先完整备份
3. 优先迁移到 Web 根目录外归档目录
4. 如迁移风险过高，则先做统一不可达化，再二期迁移

##### A2-2. 工作区与历史副本脚本收口

目标：

1. 清理 `real_sync/`、历史副本、测试副本中的危险脚本传播
2. 防止后续维护人员把已不在线上的危险脚本误同步回现网

备份要求：

1. 整体脚本清单导出
2. 所有目标文件逐个备份
3. 若迁移到 Web 根外，保留迁移前后映射清单

回滚方式：

1. 把文件从归档目录复制回原路径
2. 恢复原权限
3. 验证业务主链路未受影响

#### A3. 旧 IP / HTTP 与旧域名回退清理

涉及问题：

- 1, 2, 6, 136

重点对象：

- `api/config.php`
- `site_current/api/config.php`
- `mini-program/app.js`
- `mini-program/utils/api.js`
- `wp-content/themes/astra-child/page-home.php`

目标：

1. 正式环境只保留 `https://supercalf.com`
2. 禁止默认回退旧 `122.51.223.46` 和 `http://`

#### A4. 高危后台写接口先统一最小鉴权收口

涉及问题：

- 15-18, 28-37, 55-58, 92-110

目标：

1. 明确高危接口必须统一走后台正式鉴权入口
2. 先禁掉明显越权路径和假成功逻辑
3. 先补事务与真实审计，再考虑结构重构

### 阶段 B：认证与权限统一

目标：统一后台权限体系、业务权限体系、管理角色判断口径。

涉及问题：

- 3, 10, 15-18, 23-25, 55-56, 120-122, 236-246

建议拆 3 个批次：

#### B1. 后端权限统一抽象

范围：

- `api/admin/common.php`
- `api/common/context.php`
- `api/auth/me.php`

目标：

1. 一套正式权限模型
2. 去除硬编码 HQ 白名单
3. 管理员、店长、总部运营、超级管理员边界正式配置化

#### B2. 前端鉴权接入统一

范围：

- `internal-auth.js`
- `js/auth.js`
- 业务页显式接入方式

目标：

1. 页面鉴权初始化方式统一
2. 不再出现自动和半自动两套接入姿势并存

#### B3. 管理入口显示与真实权限统一

目标：

1. “管理中心是否显示”只保留一套规则
2. 去掉手机号/姓名/页面内特判式散点逻辑

### 阶段 C：首页与导航壳统一

目标：明确首页唯一职责，统一导航来源，拆分公共鉴权和公共导航。

涉及问题：

- 204-226, 229-246

建议拆 3 个批次：

#### C1. 首页职责认定

候选结论：

- `internal.html` 作为已登录工作台首页
- 未登录统一跳 `/mobile/login.html`

#### C2. 公共导航统一来源

目标：

1. 废弃 `js-auth.js` 或废弃页面自带重复导航
2. `internal-auth.js` 不再负责重写导航

#### C3. 返回链路统一

目标：

1. 工具页、中心页、专题页返回路径可预测
2. 避免 `history.back()` 和 referrer 猜测式路由

### 阶段 D：移动端双份页收敛

目标：统一移动端正式 URL，保留兼容层但停止双份维护。

涉及问题：

- 247-251

正式 URL 建议：

- `/mobile/login.html`
- `/mobile/mine.html`
- 后台正式入口统一走 `/admin/dashboard.html`

兼容策略：

- `mobile-login.html`、`mobile-mine.html`、`mobile-admin.html` 先改为兼容跳转页
- `/mobile/admin.html` 若确认与正式后台权限模型漂移，也应收敛为指向 `/admin/dashboard.html` 的兼容入口，而不是继续作为独立正式页维护
- 不立即删除，保留回滚空间

### 阶段 E：后台正式路由统一

目标：建立 `/admin/...` 与工作区平铺后台页、副本页的明确映射。

当前已确认补充：

1. `/mobile/admin.html`、`mobile-admin.html` 不再作为独立正式移动后台页维护，已收敛方向为指向 `/admin/dashboard.html` 的兼容入口
2. 工作区平铺后台副本 `admin-dashboard.html`、`admin-system-dashboard.html`、`admin-workload.html` 不再作为正式源维护，后续统一按兼容入口或历史副本处理
3. `/admin/*` 继续作为后台唯一正式路由体系
4. `/admin-upload.html` 归类为后台体系中的独立工具页，不并入 `/admin/*`，后续只做壳层、返回链路和统一权限前置的正式化修补

涉及问题：

- 252-255, 259

前置要求：

必须先产出后台映射表：

1. 正式 URL
2. 现网真实文件
3. 工作区平铺副本
4. 工作区目录副本
5. 是否为历史页

### 阶段 F：内容中心与阅读器统一

目标：统一制度中心、知识库、学习中心、阅读器、表格中心各自职责。

涉及问题：

- 204-213, 222-231

输出目标：

1. 中心页只做目录与筛选
2. 阅读器只做正文承载
3. 内容归属关系从前端硬编码脚本中抽离

### 阶段 G：培训专题副本收敛

目标：统一 `/training/*/` 正式专题页，收敛平铺页、`*.remote.html`、`index-*` 副本。

涉及问题：

- 207-213, 256-257

建议：

1. `/training/<topic>/` 为唯一正式入口
2. 平铺专题页先兼容跳转
3. 确认 `exam-common.js` 作为唯一正式考核公共脚本

现网新增事实：

1. 线上 `/training/` 目录下真实存在大量平铺副本：
   - `01-brand-index.html`
   - `02-role.index.html`
   - `03-reception-index.html`
   - `03-reception.index.html`
   - `04-assessment-index.html`
   - `04-assessment.index.html`
   - `05-trial-index.html`
   - `05-trial.index.html`
   - `06-communication-index.html`
   - `06-communication.index.html`
   - `07-renewal-index.html`
   - `07-renewal.index.html`
   - `08-goals-index.html`
   - `08-goals.index.html`
   - `09-foundation-index.html`
2. 现网根目录还存在多类平铺中心页和工具页，如：
   - `learning-center-live-index.html`
   - `fitness-assessment.html`
   - `survey-manage.html`
   - `tables.html`
   - `stats-center.html`
   - `weekly-drills.html`
   - `training-card.html`
   - `training-module.html`
3. 说明现网平铺页体系比工作区副本更大，后续修复必须继续以现网扫描结果为准。
4. 当前工作区已完成第一轮 `G1` 最小落地收口，已改为兼容跳转页的培训专题副本包括：
   - `/training/09-foundation/` 对应：`training-09-foundation-index.html`、`training-09-foundation-index.remote.html`、`index-09-foundation.html`
   - `/training/02-role/` 对应：`training-02-role.index.html`
   - `/training/03-reception/` 对应：`03-reception.index.html`
   - `/training/01-brand/` 对应：`01-brand.index.html`
   - `/training/04-assessment/` 对应：`04-assessment.index.html`
   - `/training/05-trial/` 对应：`05-trial.index.html`
   - `/training/06-communication/` 对应：`06-communication.index.html`
   - `/training/07-renewal/` 对应：`07-renewal.index.html`
   - `/training/08-goals/` 对应：`08-goals.index.html`
5. 说明 `G1` 批次模板已经稳定：当站内正式入口已明确指向 `/training/<topic>/` 目录页时，平铺专题页优先收敛为兼容入口，而不是继续双份并行维护。
6. `G1-5` 已完成现网 `/training/` 目录下 15 个平铺副本的兼容收口，备份目录为 `/www/mc-backups/20260516-g1-5-training-flat-copies/`
7. 现网验证结果：所有正式目录页返回 `200`，所有已存在的兼容跳转页返回 `200`，不存在命名模式的副本返回 `404`（符合预期）
8. `G1` 阶段至此完成从"工作区副本收口"到"现网真实副本收口"的完整闭环

### 阶段 H：工具体系与活动页解耦

目标：统一体测、问卷、表格、教案正式入口，并降低周年庆活动页对基础壳层侵入。

涉及问题：

- 214-221, 227-235, 254

原则：

1. 活动页不再长期占据首页和后台一级核心入口
2. 工具体系必须明确唯一正式入口文件、唯一鉴权方式、唯一返回链路

当前进展：

1. `H1-1` 已完成现网根目录第一批工具/中心页平铺副本收口：
   - `weekly-drills.html` -> `/training-center/weekly-drills.html`（兼容跳转）
   - `test-auth.html` -> nginx deny 封堵（403）
   - 备份目录：`/www/mc-backups/20260516-h1-1-root-flat-copies/`
   - 后续回归核对已确认 `training-card.html` 实为训练卡详情页正式入口，已从备份恢复，不再纳入目录收口对象
2. `H1-2` 已完成两类独立工具页壳层统一：
   - `survey-manage.html` 已从独立 JWT + `prompt` 登录壳切换为统一内网 token 壳，并移除 `123456` 弱口令回退逻辑
   - `stats-center.html` 已补齐 `/api/auth/me.php` 登录态校验、HQ 权限前置和 Bearer 请求头
   - 备份目录：`/www/mc-backups/20260516-h1-2-tool-shell-auth/`
3. `H2-1` 已完成 `learning-center-live-index.html` 的历史平铺入口收口，正式入口固定为 `/新员工学习/`
   - 备份目录：`/www/mc-backups/20260516-h2-1-learning-center-live-alias/`
4. `H1-3` 已完成 `training-module.html` 的统一鉴权补丁：
   - 已补齐 `checkAuth()`、`getToken()`、`authHeaders()`
   - 已将 `/api/drill/*` 请求统一纳入 Bearer token 体系
   - 备份目录：`/www/mc-backups/20260516-h1-3-training-module-auth/`
5. `H1-4` 已完成 `fitness-assessment-app.html` 的死登录壳清理：
   - 已删除无效 `loginPage`、`doLogin()`、空壳 `checkAuth()`
   - 保留正式请求层 `buildAuthHeaders()` 实现
   - 备份目录：`/www/mc-backups/20260516-h1-4-fitness-app-dead-login-cleanup/`
6. 以下页面暂不收口，后续只需按独立工具页维护，不再属于当前高风险壳层治理重点：
   - `fitness-assessment.html`（独立体测工具页，无对应目录版）
   - `training-card.html`（独立训练卡详情页，被 `training-module.html` 调用）
7. 截至当前复盘，阶段 G/H 的现网入口抽样验证结果正常：
   - 培训别名页如 `/training/01-brand-index.html`、`/training/02-role.index.html`、`/training/03-reception.index.html`、`/training/09-foundation-index.html` 均返回 `200`
   - 根目录已修入口如 `weekly-drills.html`、`learning-center-live-index.html`、`survey-manage.html`、`stats-center.html`、`training-module.html`、`fitness-assessment-app.html` 均返回 `200`
   - `test-auth.html` 返回 `403`
8. 下一批最有价值的候选对象已经浮现：根目录一组与 `/training-center/` 导航结构同型的培训资料平铺页，包含：
   - `coach-class-standards.html`
   - `consultative-sales.html`
   - `monthly-certification.html`
   - `overview.html`
   - `role-paths.html`
   - `sales-foundation.html`
   - `trainer-playbook.html`
   这组页面与 `training-center/*.html` 信息架构高度相似，适合作为下一轮 `H/F` 交界处的收口对象。
9. `H2-2` 已完成这 7 个根目录培训资料副本的现网收口：
   - `coach-class-standards.html` -> `/training-center/coach-class-standards.html`
   - `consultative-sales.html` -> `/training-center/consultative-sales.html`
   - `monthly-certification.html` -> `/training-center/monthly-certification.html`
   - `overview.html` -> `/training-center/overview.html`
   - `role-paths.html` -> `/training-center/role-paths.html`
   - `sales-foundation.html` -> `/training-center/sales-foundation.html`
   - `trainer-playbook.html` -> `/training-center/trainer-playbook.html`
   - 备份目录：`/www/mc-backups/20260516-h2-2-training-center-root-aliases/`
10. 根目录尾项复盘结果：
   - `coach-knowledge.html` 已经是指向 `/知识库/` 的兼容别名页，无需追加处理
   - `internal-beta-checklist.html` 是被 `v4-sync-center.html` 和 `smart-lessons.html` 正式引用的独立内测验收页，已接统一壳层，不属于平铺副本问题
   - 阶段 G/H 这一轮的根目录入口收口工作到此基本完成
11. `H2-3` 已继续收敛移动学习体系的根目录旧副本：
   - `learning.html` -> `/mobile/learning.html`
   - `pass-map.html` -> `/mobile/pass-map.html`
   - `profile.html` 已确认早先收敛为 `/mobile/mine.html`
   - 备份目录：`/www/mc-backups/20260516-h2-3-mobile-root-aliases/`
12. `GH-R3` 已完成 WordPress 默认公开页加固：
   - `readme.html` 已在 nginx 层封堵为 `403`
   - `license.txt` 已在 nginx 层封堵为 `403`
   - `wp-login.php` 保持可用，不误伤后台登录链路
   - 备份目录：`/www/mc-backups/20260516-gh-r3-wordpress-readme-license/`
13. `H2-4` 已补齐教案/动作库体系未入账收口项：
   - `lesson-library.html` 已确认早先收口为 `/action-library/` 兼容页
   - `training-cards-action-library.html` 已收口为 `/action-library/`
   - `/action-library/` 为当前已接统一鉴权的动作库正式入口
   - 备份目录：`/www/mc-backups/20260516-h2-4-action-library-root-alias/`
14. `GH-R4` 已完成阶段 G/H 根目录入口收口收尾确认：
   - 移动学习体系、知识库/制度入口、培训资料入口、教案/动作库入口已完成系统归类
   - 绝大多数历史平铺副本已被收口为兼容入口
   - 留存根目录页已基本都具有明确业务职责或已完成统一鉴权改造
15. 进入下一阶段业务问题簇后，已优先锁定“默认密码传播”问题：
   - `/admin/staffs.html` 原先继续提示“留空则使用 123456”
   - `/mobile/admin.html` 原先继续提示“重置密码为 123456”
   - 当前已在服务器文件层完成前端文案与交互收口
   - 后续应继续整体下线或收口 `/mobile/admin.html`，不要再维护第二套移动后台管理壳
16. `I1-2` 已完成 `/mobile/admin.html` 的正式收口：
   - 当前移动个人中心 `goAdmin()` 已直接指向 `/admin/dashboard.html`
   - `/mobile/admin.html` 已改为指向 `/admin/dashboard.html` 的兼容页
   - 后台正式入口进一步统一，旧移动后台壳停止独立演化
17. `I1-3` 已继续收口员工首页旧登录链路：
   - `internal.html` 虽已接 `internal-auth.js` 与 `requirePageAuth`，但原先仍保留手写 `/api/auth-jwt.php` 登录提交
   - 当前已改为统一跳转到 `/mobile/login.html?redirect=/internal.html`
   - 员工首页不再继续维护第二套前端登录提交实现
18. `I1-4` 已继续收口移动学习链路中的旧鉴权探测：
   - `mobile/pass-map.html` 原先仅在 `detectRole()` 中保留 `/api/auth-jwt.php?action=verify`
   - 当前已统一切换为 `/api/auth/me.php`
   - 移动学习链路进一步收拢到 `auth/me` 这一套登录态事实来源
19. `I1-5` 已继续收口正式登录页中的旧 token 直跳语义：
   - `mobile/login.html` 本身仍应保留 `/api/auth-jwt.php` 作为正式登录接口
   - 但“只要本地有 token 就直接跳转”的旧逻辑已改为先通过 `/api/auth/me.php` 验证
   - 过期或脏 token 会被清理，避免错误登录态与错误跳转
20. `I1-6` 已继续收口独立通关页的壳层鉴权缺口：
    - `training-pass.html` 原先调用 `/api/drill/*` 时未统一带 Bearer token
    - 当前已补齐 `getToken()`、`authHeaders()`、`checkAuth()`
    - 独立训练/通关链路进一步向统一登录壳模式收敛
21. `I1-7` 已继续收口训练卡详情页的壳层鉴权缺口：
    - `training-card.html` 原先读卡与提交答案请求未统一带 Bearer token
    - 当前已补齐 `getToken()`、`authHeaders()`、`checkAuth()`
    - 已将 `/api/drill/training-cards.php?action=get` 与 `action=submit` 纳入统一 token 壳
    - `site_current/training-card.html` 与主站版本已同步对齐，避免后续发布或切换回退旧实现
22. `I1-8` 已继续收口智慧教案页的前端请求鉴权缺口：
    - `smart-lessons.html` 虽已接统一页面门禁，但原先调用 `/smart-lessons-api.php` 时未显式复用 `authHeaders()`
    - 当前已将生成教案请求改为优先走 `window.authHeaders({ 'Content-Type': 'application/json' })`
    - 智慧教案页前后端链路进一步统一到正式 token 壳
23. `I1-9` 已继续收口 V4 更新中心的页面门禁旁路：
    - `v4-sync-center.html` 虽已引入 `/internal-auth.js?v=5`，但原先显式设置 `window.__SKIP_AUTO_INTERNAL_AUTH__ = true`
    - 该标记会让统一脚本跳过自动 `requirePageAuth()`，形成“已接脚本但未启用页面门禁”的旁路
    - 当前已移除跳过标记，V4 更新中心恢复统一自动登录门禁
24. `I1-10` 已继续收口一组静态正式目录页的统一门禁旁路：
    - `知识库/`、`表格中心/`、`training-center/`、`training-cards/` 下多页原先统一设置了 `window.__SKIP_AUTO_INTERNAL_AUTH__ = true`
    - 这些页本身又没有手动 `requirePageAuth(...)`，会形成“已接统一脚本但实际无页面门禁”的公开旁路
    - 当前已移除这组静态正式页的跳过标记，恢复统一自动登录门禁
25. `I1-11` 已继续收口后台首页类页面的前端角色前置缺口：
    - `/admin/dashboard.html` 与 `/admin/system-dashboard.html` 虽然后端接口已有限权，但前端原先只做“已登录即可进页面壳”
    - 普通员工会先进入后台页面，再在请求接口时收到 403 或加载失败
    - 当前已在页面层补齐角色前置拦截，非总部/非超管用户将直接回到 `/internal.html`
26. `I1-12` 已继续收口后台子页的前端角色前置缺口：
    - `/admin/staffs.html` 依赖的详情、修改、重置、解绑、解锁接口均为超管口径，前端原先却未提前拦截
    - `/admin/performance.html` 后端为“总部或店长可看”的口径，前端原先也未提前拦截
    - 当前已补齐页面层角色前置判断，使 `staffs` 仅允许超管、`performance` 仅允许总部或店长进入页面壳
27. `I1-13` 已继续收口四个超管后台子页的前端角色前置缺口：
    - `/admin/security-login-audit.html`、`/admin/security-devices.html`、`/admin/system-errors.html`、`/admin/operation-logs.html` 的后端均为超管口径
    - 前端原先仍是"已登录即可先进入页面壳"，普通员工需要等接口失败后才看见无权限结果
    - 当前已统一补齐超管前置拦截，非超管将直接回到 `/internal.html`
28. `I1-14` 已继续收口工作量后台页的前端角色前置缺口：
    - `/admin/workload.html` 的后端 `hq-summary.php` 要求 `appCanViewAll`（仅总部），`store-summary.php` 要求 `appRequireEditStore`（仅总部或店长）
    - 前端原先使用 `app-auth.js` + `api-client.js`，无 `requirePageAuth()` 也无角色前置拦截，普通员工可直接进入页面壳但接口返回 403
    - 当前已新增 `internal-auth.js` 接入与 `requirePageAuth()` 角色前置拦截，仅允许总部/管理层角色进入页面壳
29. `I1-15` 已继续收口两页 AI 总部工具的统一门禁与请求鉴权缺口：
    - `/ai-analyzer.html` 与 `/ai-drill.html` 的后端接口 `/api/admin/ai-analyze.php`、`/api/admin/ai-drill.php` 均要求 `adminCanAccessHeadquarter`
    - 前端原先未接统一页面门禁，且关键请求未统一复用 `window.authHeaders(...)`，会形成“页面可先打开、请求再失败”与“Bearer 头不一致”的双重缺口
    - 当前已补齐 `internal-auth.js` + `requirePageAuth()` 总部角色前置拦截，并统一关键 POST 请求的认证头
30. `I1-16` 已继续收口资料上传页的手写鉴权分支：
    - `/admin-upload.html` 原先依赖 `__SKIP_AUTO_INTERNAL_AUTH__ = true` 跳过统一自动门禁，再手写 `getToken()` + `/api/auth/me.php` + HQ 角色判断
    - 这虽然能工作，但会让该页继续维护一套与全站不同的页面初始化鉴权分支
    - 当前已移除跳过标记，并统一改为 `requirePageAuth()` 驱动页面进入，仅保留页面内部的 `authHeaders()` 供业务请求复用
31. `I1-17` 已继续收口 AI 工具的历史 `.remote.html` 副本：
    - 现网根目录真实存在 `/ai-analyzer.remote.html` 与 `/ai-drill.remote.html`，且可公网返回 `200`
    - 这两个文件内容与正式 AI 工具页重合，会形成新的历史别名暴露面和维护分叉风险
    - 当前已将其统一改为跳转到 `/ai-analyzer.html`、`/ai-drill.html` 的兼容页
32. `I1-18` 已继续收口数据统计中心中“写了前置校验但未实际接入”的缺口：
    - `/stats-center.html` 原先虽然定义了 `ensureStatsAccess()` 与总部角色判断 `canAccessAdmin()`，但页面最终仍是 `DOMContentLoaded -> loadStats`
    - 这会导致页面未先经过 HQ 角色前置校验就直接请求 `/api/admin/stats.php`
    - 当前已统一改为 `requirePageAuth()` 驱动页面进入，并在 `onAuthed` 中执行总部角色前置判断

### 6.1 当前阶段性总复盘

截至 `I1-18`，本轮“旧登录交互链 + 独立正式页/后台页壳层鉴权统一 + 后台页面级角色前置拦截”已经形成以下阶段性结论：

1. 已明确收口完成的对象：
   - 后台页角色前置拦截：`/admin/dashboard.html`、`/admin/system-dashboard.html`、`/admin/staffs.html`、`/admin/performance.html`、`/admin/security-login-audit.html`、`/admin/security-devices.html`、`/admin/system-errors.html`、`/admin/operation-logs.html`、`/admin/workload.html`
   - 独立总部工具页统一门禁：`/ai-analyzer.html`、`/ai-drill.html`、`/admin-upload.html`、`/stats-center.html`
   - 正式页与训练链路统一鉴权：`/training-pass.html`、`/training-card.html`、`/smart-lessons.html`、`/v4-sync-center.html`
   - 静态正式目录页统一门禁：`/知识库/`、`/表格中心/`、`/training-center/`、`/training-cards/`
   - 历史别名收口：`/ai-analyzer.remote.html`、`/ai-drill.remote.html` 以及前序各类根目录兼容入口
2. 已确认“合法保留、暂不继续改动”的对象：
   - `/admin/learning.html`：依赖的统计接口允许总部、店长、普通员工按权限分层返回，不适合再加前端硬拦
   - `/doc-viewer.html`：当前为“`__SKIP_AUTO_INTERNAL_AUTH__` + 手动 `requirePageAuth(...)`”的合法初始化模式，不应机械移除 `skip`
3. 仍需单独策略判断的对象：
   - `/internal.html`：当前仍带 `__SKIP_AUTO_INTERNAL_AUTH__`，但它属于特殊首页壳，不适合套用普通工具页的统一收口模式
4. 已补充确认 `internal.html` 的当前设计语义：
   - 该页保留登录表单与首页壳，未登录用户可直接看到首页内容与手机号登录入口
   - `__SKIP_AUTO_INTERNAL_AUTH__` 的作用是阻止 `internal-auth.js` 自动执行整页跳转，避免首页壳在未登录时被直接送往 `/mobile/login.html`
   - 页面底部仍手动调用 `requirePageAuth({ onAuthed })`，已登录用户会获得管理员入口等增强能力，因此它不是“无门禁裸奔页”，而是特殊首页模式
5. 本轮扫描未再发现新的成簇权限缺口：
   - 根目录历史别名模式专项扫描后，除已处理 `.remote.html` 外，其他候选平铺页均已处于正确兼容态或被确认为正式页
6. 后续工作重心应切换为：
   - `internal.html` 的单点策略评估
   - 收官回归抽测与台账总结

## 7. 每阶段统一执行模板

每个批次都必须产出 Runbook，至少包含：

1. 批次名
2. 修复目标
3. 涉及问题编号
4. 目标现网文件
5. 工作区对应副本
6. 备份目录
7. 上传/回滚步骤
8. 验证步骤
9. 失败触发回滚条件

## 8. 立即执行优先级

后续执行优先级固定为：

1. A2-1 现网真实残留脚本收口
2. A3 旧 IP / HTTP 清理
3. A4 高危后台写接口最小收口
4. B1 后端权限统一抽象
5. D 阶段移动端双份页收敛

## 9. 当前已完成批次记录

### 阶段性收官总结

截至 `I1-18`，本轮围绕“旧登录交互链、独立正式页/后台页壳层鉴权统一、后台页面级角色前置拦截、历史别名页收口”的主问题簇，已经可以给出以下收官判断：

1. 主问题簇已完成收口：
   - 后台首页、后台子页、工作量页、AI 工具页、上传页、数据统计中心、训练链路、静态正式目录页已经统一到“页面门禁 + 角色前置 + 请求头一致性”的正式口径
   - 根目录历史 `.remote.html` 副本与既有平铺别名页已经收敛到正式页或兼容跳转页
2. 已确认合法保留的特殊页：
   - `/admin/learning.html`：维持后端分层返回，不做前端硬拦
   - `/doc-viewer.html`：维持“手动 `requirePageAuth(...)`”模式，不机械移除 `skip`
   - `/internal.html`：维持“未登录可见首页壳 + 已登录增强”的特殊首页模式，不按普通工具页处理
3. 当前剩余风险不再属于成簇权限缺口：
   - 更多是首页壳语义、产品信息架构、旧书签兼容、回归验证与收官总结类工作
4. 若继续推进，后续优先级应切换为：
   - 全量回归抽测与人工业务验收
   - 对 `internal.html` 的产品级语义是否保留进行单独决策
   - 审计台账状态整理与最终交接文档完善

### 工作量凭证链路闭环结果

在阶段性收官后，已针对“移动端工作量缺少图片上传界面”这一真实功能缺口完成单独闭环，当前结论如下：

1. H5 与小程序已统一补齐凭证上传入口：
   - H5：`/mobile/workload.html`、`/mobile/workload-v2.html`
   - 小程序：`/mini-program/pages/workload/index`
2. 后端凭证接口已形成闭环：
   - 上传：`/api/workload/evidence-upload.php`
   - 列表：`/api/workload/evidence-list.php`
   - 删除：`/api/workload/evidence-delete.php`
   - 提交校验：`/api/workload/save-report.php`
3. 规则已统一到前后端一致口径：
   - `need_evidence` 指标在正式提交时必须满足最少凭证数
   - 单个指标凭证图片最大数统一限制在 `10` 张以内
   - 草稿态允许上传、删除和补齐凭证
   - 非 HQ 角色在日报已 `submitted` 后不能删除凭证
4. 双端体验已补齐到可用版：
   - 上传图片
   - 删除图片
   - 查看图片
   - 每项显示 `已上传 X / 最多 Y 张`
   - 页面内提示“还差几张”
5. 已补专项文档与回归脚本：
   - 文档：`.monkeycode/docs/workload-evidence-acceptance-and-test-2026-05-17.md`
   - 最小脚本：`real_sync/scripts/test_workload_evidence_requirement.php`
   - 回归脚本：`real_sync/scripts/test_workload_evidence_regression.php`
6. 已完成现网真实接口最小闭环与增强校验：
   - 教练日报草稿保存成功
   - 缺少体测凭证时正式提交被拒绝
   - 上传 1 张体测凭证后正式提交成功
   - 日报已 `submitted` 后普通员工删除凭证被拒绝
   - 在当前模板 `max_evidence_count=3` 条件下，第 4 张上传被拒绝
7. 当前该问题簇已不再属于“功能未接通”状态，而是进入“可运行 + 可验收 + 可回归 + 已完成现网闭环验证”的稳定阶段

### 已完成：A1

- 批次名：`p0-stop-weak-defaults-and-entry-drift`
- 备份目录：`/www/mc-backups/20260515-182122-p0-stop-weak-defaults-and-entry-drift/`
- 已验证结果：
  - `internal.html` 旧登录入口已收敛
  - `mobile/login.html` 弱口令传播文案已去除
  - 现网确认不存在 `mobile-login.html` 和 `mobile-admin.html` 平铺页

### 已完成：A2 第一轮现网存在性清点

- 说明：
  - 已通过 SSH 对台账列出的第一组高危导入/初始化/修复脚本做存在性核查
  - 当前现网未返回命中结果，后续 A2 将先区分“现网真实残留”和“工作区/历史副本残留”再执行

### 已完成：A2 第二轮现网真实残留扫描

- 说明：
  - 已通过 SSH + 远端 Python 列举确认，A2 相关高危脚本在线上真实存在
  - 已确认现网还存在比工作区更多的平铺页面、专题页和测试页面
  - 后续所有 A2 / G / H 阶段执行都必须继续以现网真实扫描结果为准
