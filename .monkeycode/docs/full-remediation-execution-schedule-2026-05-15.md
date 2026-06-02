# 追光小牛企业内网全面修复执行表

## 总体策略

执行顺序固定为：

1. P0 安全止血
2. 认证与权限统一
3. 首页与导航壳统一
4. 移动端双份页收敛
5. 后台正式路由统一
6. 内容中心与阅读器统一
7. 培训专题副本收敛
8. 工具体系与活动页解耦

## 执行表

| 阶段 | 主题 | 优先级 | 目标 | 变更原则 | 通过标准 |
| --- | --- | --- | --- | --- | --- |
| 1 | P0 安全止血 | 最高 | 去除弱口令传播、危险脚本暴露、旧 IP/HTTP 暴露 | 只做止血，不做结构重构 | 风险停止继续扩散 |
| 2 | 认证与权限统一 | 高 | 统一后台和业务鉴权口径 | 单批小范围、角色验证后放行 | 权限口径单一 |
| 3 | 首页与导航壳统一 | 高 | 明确唯一首页职责与唯一导航来源 | 先抽公共层，再清壳页 | 首页、导航、返回一致 |
| 4 | 移动端双份页收敛 | 高 | 统一正式移动端 URL | 保留兼容入口，先不硬删 | 同功能单正式页 |
| 5 | 后台正式路由统一 | 高 | 建立后台正式路由与副本映射 | 先认正式源，再合并 | 后台首页与子后台一致 |
| 6 | 内容中心与阅读器统一 | 中高 | 统一制度/知识/学习/阅读器的正式关系 | 先认定中心职责，再调路由 | 内容可直达，返回清晰 |
| 7 | 培训专题副本收敛 | 中高 | 统一培训专题正式源 | 先保兼容，再收副本 | 培训专题只剩唯一正式入口 |
| 8 | 工具体系与活动页解耦 | 中 | 工具与活动从基础壳层解耦 | 先降级活动页侵入，再统一工具入口 | 首页和后台恢复基础结构 |

## 第一批执行单

### 批次名

`p0-stop-weak-defaults-and-entry-drift`

### 范围

1. `real_sync/mobile-login.html`
2. `real_sync/mobile/login.html`
3. `/workspace/internal.html`
4. `real_sync/mobile-admin.html`
5. `real_sync/mobile/admin.html`

### 本批目标

1. 停止前端继续传播 `123456` 默认密码
2. 消除旧 `mobile-login.html` 入口在工作区副本中的继续扩散
3. 收敛明显的移动端重复页高暴露差异点，为后续统一 URL 铺路

### 当前实施结果

1. 已把 `/workspace/internal.html` 中的登录入口从 `/mobile-login.html` 收敛为 `/mobile/login.html`
2. 已移除 `real_sync/mobile-login.html` 与 `real_sync/mobile/login.html` 中对默认密码 `123456` 的传播文案
3. 已把 `real_sync/mobile-admin.html` 的返回行为改为与 `real_sync/mobile/admin.html` 一致，统一回到 `/mobile/mine.html`
4. 已完成线上备份：`/www/mc-backups/20260515-182122-p0-stop-weak-defaults-and-entry-drift/`
5. 已上传并验证 `internal.html` 和 `mobile/login.html` 到现网
6. 线上验证确认：旧 `mobile-login.html` 入口已消除、`123456` 默认密码提示已消除、安全替代文案已生效
7. 现网事实更新：`mobile-login.html` 和 `mobile-admin.html` 在现网并不存在，只有 `mobile/login.html` 和 `mobile/admin.html` 在线上

### 必须备份

1. 线上对应原文件
2. 本地工作区对应修改文件

### 验证项

1. 登录页可正常打开
2. 不再出现“初始密码默认是 123456”文案
3. `internal.html` 登录入口不再继续落到旧扁平登录页
4. 管理员中心返回路径行为一致

### 回滚条件

1. 登录页打不开
2. 登录跳转错误
3. 管理员页返回异常

### 回滚方式

1. 用批次备份覆盖还原
2. 复测登录入口和管理员页返回

## 第二批执行单

### 批次名

`a2-1-block-high-risk-web-reachable-scripts`

### 范围

1. `/api/admin/test.php`
2. `/api/common/context-test.php`
3. `/api/import_*.php`
4. `/api/init_*.php`
5. `/api/cleanup_*.php`
6. `/api/fix_*.php`
7. `/database/import_*.php`
8. nginx 站点配置：`/www/server/panel/vhost/nginx/122.51.223.46.conf`

### 本批目标

1. 先在 nginx 层阻断现网真实存在的高危 Web 可达脚本
2. 不直接删除、不移动脚本，优先做最小风险止血
3. 保留 CLI 使用可能性，降低回滚成本

### 现网已确认事实

1. `/scripts/` 已被 nginx 统一 `403`
2. 以下脚本在线上真实存在且仍需收口：
   - `/api/admin/test.php`
   - `/api/common/context-test.php`
   - `/api/import_faq_knowledge.php`
   - `/api/import_faq_qa.php`
   - `/api/import_faq_to_training_cards.php`
   - `/api/import_policies.php`
   - `/api/import_policies_v2.php`
   - `/api/init_training_data.php`
   - `/api/cleanup_duplicate_cards.php`
   - `/api/fix_module1_duplicates.php`
   - `/database/import_feedback_dimension.php`
   - `/database/import_sales_seven_steps.php`
   - `/database/import_sales_ten_questions.php`

### 必须备份

1. nginx 配置原件
2. 本批目标脚本文件清单

### 实施策略

1. 先备份 nginx 配置
2. 在 HTTPS 站点块新增高危路径 deny 规则
3. `nginx -t` 通过后 reload
4. 对每个高危入口执行 HTTP/HTTPS 访问验证，应返回 `403` 或等效拦截结果

### 验证项

1. `/scripts/` 仍保持 `403`
2. `/api/import_*.php` 访问被拦截
3. `/api/init_*.php` 访问被拦截
4. `/api/cleanup_*.php` 访问被拦截
5. `/api/fix_*.php` 访问被拦截
6. `/api/admin/test.php` 访问被拦截
7. `/api/common/context-test.php` 访问被拦截
8. 站点主页面和现有业务入口正常访问

### 回滚条件

1. nginx 配置校验失败
2. reload 后站点主入口异常
3. 正常业务 PHP 访问被误伤

### 回滚方式

1. 恢复备份的 `122.51.223.46.conf`
2. 再次执行 `nginx -t`
3. reload nginx
4. 复测首页和登录页

### 当前实施结果

1. 已完成 nginx 配置备份：`/www/mc-backups/20260516-023800-a2-1-block-high-risk-web-reachable-scripts/122.51.223.46.conf`
2. 已在站点 HTTPS 配置中新增高危脚本 deny 规则，覆盖：
   - `/api/import_*.php`
   - `/api/init_*.php`
   - `/api/cleanup_*.php`
   - `/api/fix_*.php`
   - `/api/admin/test.php`
   - `/api/common/context-test.php`
   - `/database/import_*.php`
3. 已执行 `nginx -t`，语法校验通过
4. 已完成 `nginx -s reload`
5. 已验证以下高危脚本入口返回 `403`：
   - `/api/import_policies.php`
   - `/api/init_training_data.php`
   - `/api/cleanup_duplicate_cards.php`
   - `/api/fix_module1_duplicates.php`
   - `/api/admin/test.php`
   - `/api/common/context-test.php`
   - `/database/import_feedback_dimension.php`
6. 已验证以下主入口页面保持 `200` 正常：
   - `/internal.html`
   - `/mobile/login.html`
   - `/index.html`

## 第三批执行单

### 批次名

`a3-1-replace-legacy-ip-http-baseline`

### 范围

第一轮只处理正式业务链路中确认命中的旧 IP / HTTP 残留，不处理第三方 vendor 与测试脚本。

目标文件：

1. `/api/config.php`
2. `/mini-program/app.js`
3. `/mini-program/utils/api.js`
4. `/wp-content/themes/astra-child/page-home.php`

暂缓对象：

1. `site_current/*` 历史副本
2. `scripts/*` 检查脚本
3. `wp-content/plugins/*` vendor 依赖
4. `wp-includes/*` 与 WordPress 核心文件

### 本批目标

1. 正式业务默认地址不再回退到 `122.51.223.46`
2. 小程序正式 API 基地址改为 `https://supercalf.com/api`
3. 主题首页旧 IP 直链改为正式域名路径
4. 保持现网入口兼容，不在本批引入结构重构

### 现网已确认命中

1. `/api/config.php`
   - `ALLOWED_ORIGINS` 默认包含 `http://122.51.223.46`
   - `API_BASE_URL` 默认值为 `http://122.51.223.46/api`
2. `/mini-program/app.js`
   - `apiBase: 'http://122.51.223.46/api'`
3. `/mini-program/utils/api.js`
   - 默认回退到 `http://122.51.223.46/api`
4. `/wp-content/themes/astra-child/page-home.php`
   - 多个内容卡片链接仍为 `http://122.51.223.46/...`

### 必须备份

1. `/api/config.php`
2. `/mini-program/app.js`
3. `/mini-program/utils/api.js`
4. `/wp-content/themes/astra-child/page-home.php`

### 实施策略

1. 先备份这 4 个正式业务文件
2. 只替换正式业务默认值，不顺手处理历史副本
3. 优先把默认值统一到 `https://supercalf.com/api`
4. 主题页旧 IP 直链优先改为正式域名链接或站内相对路径

### 验证项

1. `api/config.php` 不再默认回退到旧 IP / HTTP
2. 小程序配置文件默认 API 基地址变为 `https://supercalf.com/api`
3. 主题首页旧 IP 直链消失
4. `https://supercalf.com/index.html`、`https://supercalf.com/internal.html`、`https://supercalf.com/mobile/login.html` 访问仍正常

### 回滚条件

1. 首页渲染异常
2. 小程序正式配置语义被破坏
3. PHP 配置文件语法异常

### 回滚方式

1. 覆盖恢复这 4 个文件的备份版本
2. 重新验证首页与登录页访问

### 当前实施结果

1. 已完成现网备份目录创建：`/www/mc-backups/20260516-a3-1-replace-legacy-ip-http-baseline`
2. 已完成以下正式业务文件备份：
   - `/www/mc-backups/20260516-a3-1-replace-legacy-ip-http-baseline/config.php`
   - `/www/mc-backups/20260516-a3-1-replace-legacy-ip-http-baseline/app.js`
   - `/www/mc-backups/20260516-a3-1-replace-legacy-ip-http-baseline/api.js`
   - `/www/mc-backups/20260516-a3-1-replace-legacy-ip-http-baseline/page-home.php`
3. 已完成以下现网正式文件最小替换：
   - `/www/wwwroot/122.51.223.46/api/config.php`
   - `/www/wwwroot/122.51.223.46/mini-program/app.js`
   - `/www/wwwroot/122.51.223.46/mini-program/utils/api.js`
   - `/www/wwwroot/122.51.223.46/wp-content/themes/astra-child/page-home.php`
4. 已将 `api/config.php` 中以下默认值替换为正式 HTTPS 域名：
   - `ALLOWED_ORIGINS`: `https://supercalf.com`
   - `API_BASE_URL`: `https://supercalf.com/api`
5. 已将小程序正式默认 API 基地址统一替换为：`https://supercalf.com/api`
6. 已将主题首页“热门制度”区 3 个旧 IP 绝对链接统一替换为 `https://supercalf.com/...`
7. 已完成 PHP 语法验证：
   - `php -l /www/wwwroot/122.51.223.46/api/config.php`
   - `php -l /www/wwwroot/122.51.223.46/wp-content/themes/astra-child/page-home.php`
   - 结果均为 `No syntax errors detected`
8. 已抽样确认旧公开入口默认值不再出现在本批 4 个目标文件中；残留的 `122.51.223.46` 仅存在于数据库名/用户名标识，不属于本批 URL 清理范围
9. 已验证以下正式入口保持 `200` 正常：
   - `https://supercalf.com/index.html`
   - `https://supercalf.com/internal.html`
   - `https://supercalf.com/mobile/login.html`

### 第二轮复核结果

1. 已对以下公开业务范围做第二轮现网扫描：
   - `/www/wwwroot/122.51.223.46/mobile`
   - `/www/wwwroot/122.51.223.46/training`
   - `/www/wwwroot/122.51.223.46/wp-content/themes/astra-child`
   - `/www/wwwroot/122.51.223.46/*.html`
2. 已确认公开页面和前端脚本中未再发现以下旧公开入口残留：
   - `http://122.51.223.46`
   - `http://supercalf.com`
3. 第二轮剩余命中主要为以下非本批处理对象，暂不修改：
   - 维护脚本中的服务器绝对路径常量，如 `/www/wwwroot/122.51.223.46/...`
   - 本地渲染/修复脚本中的调试输出，如 `fix_layout.php`、`homepage_design.php`、`subpages.php`
   - `data:image/svg+xml` 内合法的 `http://www.w3.org/2000/svg` 命名空间
4. 结论：`A3` 在“正式业务公开入口 URL 清理”这一层面已完成第一阶段目标，后续如继续推进，应转入维护脚本与历史副本专项清理，而非继续修改正式业务入口文件

## 第四批执行单

### 批次名

`a4-1-tighten-admin-upload-auth`

### 范围

只处理仍未接入正式后台统一鉴权入口、且具备上传即写库/落盘能力的高危后台上传接口：

1. `/api/admin/upload.php`
2. `/api/upload.php`

### 本批目标

1. 去掉散装 `checkAdminAuth()` 管理员判断
2. 统一改为 `adminRequireAuth('adminCanAccessHeadquarter')`
3. 让高危上传入口和正式后台权限入口保持一致

### 当前实施结果

1. 已完成现网备份目录创建：`/www/mc-backups/20260516-a4-1-tighten-admin-upload-auth`
2. 已完成以下现网文件备份：
   - `/www/mc-backups/20260516-a4-1-tighten-admin-upload-auth/admin-upload.php`
   - `/www/mc-backups/20260516-a4-1-tighten-admin-upload-auth/root-upload.php`
3. 已完成以下现网文件最小替换：
   - `/www/wwwroot/122.51.223.46/api/admin/upload.php`
   - `/www/wwwroot/122.51.223.46/api/upload.php`
4. 两个上传接口已从旧的 `getJwtCurrentUser() + isJwtManager()` 散装判断切换为：
   - `handleCORS()`
   - `adminRequireAuth('adminCanAccessHeadquarter')`
5. 已完成 PHP 语法验证：
   - `php -l /www/wwwroot/122.51.223.46/api/admin/upload.php`
   - `php -l /www/wwwroot/122.51.223.46/api/upload.php`
   - 结果均为 `No syntax errors detected`
6. 已完成未登录访问验证，以下接口均返回 `401`，不再以宽松旧逻辑放行：
   - `POST https://supercalf.com/api/admin/upload.php`
   - `POST https://supercalf.com/api/upload.php`
7. 已复核主入口保持正常：
   - `https://supercalf.com/index.html` 返回 `200`

### 本批结论

1. `A4` 第一轮已完成“高危上传写接口最小收口”
2. 现网其余主要 `api/admin/*` 写接口大多已接入 `adminRequireAuth()`，下一步更适合转入：
   - 后台公共权限口径统一 `B1`
   - 或继续补事务与真实审计日志的后台写接口专项

## 第五批执行单

### 批次名

`b1-1-unify-backend-permission-context`

### 范围

第一轮只处理后端权限口径分裂最明显的 3 个核心文件：

1. `/api/common/context.php`
2. `/api/admin/common.php`
3. `/api/auth/me.php`

### 本批目标

1. 形成单一后端权限来源
2. 让后台公共鉴权层复用统一 staff context
3. 让 `auth/me` 返回的角色与权限信息不再单独算一套

### 当前实施结果

1. 已完成现网备份目录创建：`/www/mc-backups/20260516-b1-1-unify-backend-permission-context`
2. 已完成以下现网文件备份：
   - `/www/mc-backups/20260516-b1-1-unify-backend-permission-context/context.php`
   - `/www/mc-backups/20260516-b1-1-unify-backend-permission-context/admin-common.php`
   - `/www/mc-backups/20260516-b1-1-unify-backend-permission-context/auth-me.php`
3. 已在 `/api/common/context.php` 中新增统一权限辅助函数：
   - `appRoleTokensFromUser()`
   - `appIsSuperAdmin()`
   - `appIsHeadquarter()`
   - `appCanAccessHeadquarter()`
   - `appCanAccessPerformance()`
   - `appCanAccessWorkload()`
4. 已将 `appGetCurrentStaffContext()` 内的 HQ / 管理能力判断切换为复用上述统一函数，不再在函数内部单独维护一套判断分支
5. 已将 `/api/admin/common.php` 切换为复用 `/api/common/context.php`：
   - `adminRoleTokens()` 复用 `appRoleTokensFromUser()`
   - `adminCanAccessHeadquarter()` 复用 `appCanAccessHeadquarter()`
   - `adminCanAccessPerformance()` 复用 `appCanAccessPerformance()`
   - `adminCanAccessWorkload()` 复用 `appCanAccessWorkload()`
   - `isSuperAdminUser()` 复用 `appIsSuperAdmin()`
6. 已将 `/api/auth/me.php` 切换为复用 `appGetCurrentStaffContext()` 输出权限结果，并补充返回：
   - `is_admin`
   - `is_hq`
   - `permissions`
7. 已完成 PHP 语法验证：
   - `php -l /www/wwwroot/122.51.223.46/api/common/context.php`
   - `php -l /www/wwwroot/122.51.223.46/api/admin/common.php`
   - `php -l /www/wwwroot/122.51.223.46/api/auth/me.php`
   - 结果均为 `No syntax errors detected`
8. 已完成未登录语义验证：
   - `GET https://supercalf.com/api/auth/me.php` 返回 `401`
   - `GET https://supercalf.com/api/admin/stats.php` 返回 `401`
9. 已复核主入口保持正常：
   - `https://supercalf.com/internal.html` 返回 `200`

### 本批结论

1. `B1` 第一轮已经把“后台公共鉴权层、staff context、auth/me 返回口径”收拢到一套后端权限来源
2. 仍未彻底完成的部分是“正式配置化替代历史 HQ 白名单”，这一项需要在后续批次结合真实组织/角色配置继续推进

## 第六批执行单

### 批次名

`b1-2-remove-hq-whitelist`

### 范围

第二轮只处理 HQ 白名单去硬编码，并补齐现网历史总部角色兼容映射：

1. `/api/common/context.php`
2. `/api/admin/common.php`

### 本批前置核对

1. 已通过现网应用配置读取 `staffs.role` 分布，确认当前关键后台角色包含：
   - `operation`
   - `finance`
   - `ceo`
   - `manager`
   - 历史值 `headquarters`
2. 已核对白名单 4 人现网角色：
   - `姚修宁` → `headquarters`
   - `陈琪琪` → `ceo`
   - `何梓辛` → `operation`
   - `周颖` → `finance`
3. 结论：不能直接硬删 HQ 白名单；必须先让 `headquarters` 进入正式角色映射，再移除硬编码白名单依赖

### 当前实施结果

1. 已完成现网备份目录创建：`/www/mc-backups/20260516-b1-2-remove-hq-whitelist`
2. 已完成以下现网文件备份：
   - `/www/mc-backups/20260516-b1-2-remove-hq-whitelist/context.php`
   - `/www/mc-backups/20260516-b1-2-remove-hq-whitelist/admin-common.php`
3. 已在 `/api/common/context.php` 中补齐总部历史角色映射：
   - `headquarters` → `operation`
   - `hq` → `operation`
   - `总部` → `operation`
4. 已在 `appIsHeadquarter()` 中把 HQ 判断改为只基于正式角色 token，不再依赖姓名/手机号白名单
5. 已将 `/api/admin/common.php` 中 `adminIsWhitelistedHeadquarter()` 改为固定返回 `false`，彻底移除后台代码层面的 HQ 白名单放行逻辑
6. 已完成 PHP 语法验证：
   - `php -l /www/wwwroot/122.51.223.46/api/common/context.php`
   - `php -l /www/wwwroot/122.51.223.46/api/admin/common.php`
   - 结果均为 `No syntax errors detected`
7. 已完成未登录语义复核：
   - `GET https://supercalf.com/api/admin/stats.php` 返回 `401`
   - `GET https://supercalf.com/api/auth/me.php` 返回 `401`
8. 已复核主入口保持正常：
   - `https://supercalf.com/internal.html` 返回 `200`

### 本批结论

1. `B1` 第二轮已完成“后台代码去 HQ 姓名/手机号白名单硬编码”
2. 当前 HQ 权限已改为依赖正式角色及其历史兼容映射，而非人员白名单
3. 后续如继续推进，应考虑数据层面把 `headquarters` 存量角色批量收敛到正式角色值 `operation`

## 第七批执行单

### 批次名

`b1-3-normalize-headquarters-role`

### 范围

只处理现网已确认存在的最后 1 条历史角色存量数据：

1. `staffs.id = 45`
2. `role: headquarters -> operation`

### 本批目标

1. 完成 HQ 历史角色的数据层收尾
2. 让 HQ 权限完全基于正式角色值生效
3. 为后续移除 `headquarters` 兼容分支创造条件

### 当前实施结果

1. 已完成现网备份目录创建：`/www/mc-backups/20260516-b1-3-normalize-headquarters-role`
2. 已将目标记录更新前快照备份到：
   - `/www/mc-backups/20260516-b1-3-normalize-headquarters-role/staff-45-before.json`
3. 已核对现网唯一 `headquarters` 存量记录：
   - `id=45`
   - `name=姚修宁`
   - `phone=13668501068`
   - `role=headquarters`
4. 已完成最小数据更新：
   - `UPDATE staffs SET role = 'operation' WHERE id = 45 AND role = 'headquarters'`
5. 已复核更新后记录：
   - `id=45`
   - `role=operation`
6. 已复核角色分布变化：
   - `headquarters` 从 `1` 变为 `0`
   - `operation` 从 `2` 变为 `3`
7. 已复核未登录接口语义保持稳定：
   - `GET https://supercalf.com/api/auth/me.php` 返回 `401`
   - `GET https://supercalf.com/api/admin/stats.php` 返回 `401`
8. 已复核主入口保持正常：
   - `https://supercalf.com/internal.html` 返回 `200`

### 本批结论

1. `B1` 的 HQ 历史角色已在数据层完成收尾，现网 `staffs.role` 不再存在 `headquarters` 存量
2. 当前 HQ 权限已同时完成：
   - 代码层去白名单
   - 数据层收敛到正式角色值
3. 后续如继续推进，可考虑在下一轮删除 `headquarters/hq/总部` 的兼容映射分支，彻底完成角色模型清理

## 第八批执行单

### 批次名

`b1-4-drop-headquarters-compat`

### 范围

在确认现网数据已无 `headquarters` 存量后，删除后端权限模型中的 HQ 历史兼容映射分支：

1. `/api/common/context.php`

### 本批前置核对

1. 已再次复核现网 `staffs.role` 分布：
   - `coach`
   - `sales`
   - `manager`
   - `operation`
   - `finance`
   - `ceo`
2. 已确认现网已无 `headquarters` 角色存量记录

### 当前实施结果

1. 已完成现网备份目录创建：`/www/mc-backups/20260516-b1-4-drop-headquarters-compat`
2. 已完成以下现网文件备份：
   - `/www/mc-backups/20260516-b1-4-drop-headquarters-compat/context.php`
3. 已从 `/api/common/context.php` 中删除以下历史兼容映射：
   - `headquarters` → `operation`
   - `hq` → `operation`
   - `总部` → `operation`
4. 已从 `appIsHeadquarter()` 中删除以下历史兼容 token：
   - `headquarters`
   - `hq`
5. 已完成 PHP 语法验证：
   - `php -l /www/wwwroot/122.51.223.46/api/common/context.php`
   - 结果为 `No syntax errors detected`
6. 已通过现网检索确认 `context.php` 中不再存在 `headquarters` / `hq` 旧角色兼容代码
7. 已复核未登录接口语义保持稳定：
   - `GET https://supercalf.com/api/auth/me.php` 返回 `401`
   - `GET https://supercalf.com/api/admin/stats.php` 返回 `401`
8. 已复核主入口保持正常：
   - `https://supercalf.com/internal.html` 返回 `200`
9. 已最终复核角色分布仍为：
   - `coach=29`
   - `sales=9`
   - `manager=7`
   - `operation=3`
   - `finance=1`
   - `ceo=1`

### 本批结论

1. `B1` 已完整闭环：
   - 权限来源统一
   - HQ 白名单移除
   - 历史 HQ 角色数据收敛
   - HQ 历史兼容映射代码删除
2. 当前后端权限模型已只依赖正式角色值，不再依赖历史 HQ 特判链路

## 第九批执行单

### 批次名

`b3-1-unify-admin-entry-display`

### 范围

第一轮只统一“总部后台 `/admin/dashboard.html` 入口是否显示”的前端判断口径，不扩散到其他业务入口：

1. `/internal-auth.js`
2. `/internal.html`
3. `/mobile/mine.html`
4. `/mobile-mine.html`
5. `/新员工学习/index.html`

### 本批目标

1. 去掉前端手机号/姓名白名单显示逻辑
2. 不再使用 `is_manager` 粗粒度决定总部后台入口显示
3. 统一改为与后端 HQ 权限口径对齐：
   - `user.is_hq`
   - `user.is_admin`
   - `role in ['admin', 'ceo', 'operation', 'finance']`

### 当前实施结果

1. 已完成现网备份目录创建：`/www/mc-backups/20260516-b3-1-unify-admin-entry-display`
2. 已完成以下现网文件备份：
   - `/www/mc-backups/20260516-b3-1-unify-admin-entry-display/internal-auth.js`
   - `/www/mc-backups/20260516-b3-1-unify-admin-entry-display/internal.html`
   - `/www/mc-backups/20260516-b3-1-unify-admin-entry-display/mobile-mine-dir.html`
   - `/www/mc-backups/20260516-b3-1-unify-admin-entry-display/mobile-mine-flat.html`
   - `/www/mc-backups/20260516-b3-1-unify-admin-entry-display/learning-index.html`
3. 已在 `/internal-auth.js` 中新增统一前端判断函数：
   - `canShowAdminDashboardEntry(user)`
4. 已将顶部导航中的“管理中心”入口改为按统一函数决定是否渲染，不再固定显示后交给后台页拦截
5. 已将 `/internal.html` 中的散点判断替换为统一口径：
   - 删除手机号/姓名白名单
   - 删除 `role === 'manager'` 作为总部后台入口显示条件
6. 已将 `/mobile/mine.html` 与 `/mobile-mine.html` 中 `adminMenuItem` 的显示规则统一为：
   - `user.is_hq`
   - `user.is_admin`
   - `role in ['admin', 'ceo', 'operation', 'finance']`
7. 已将 `/新员工学习/index.html` 中 `adminLink` 与 `adminHomeCard` 的显示规则统一为同一口径
8. 已完成页面访问复核：
   - `https://supercalf.com/internal.html` 返回 `200`
   - `https://supercalf.com/mobile/mine.html` 返回 `200`
   - `https://supercalf.com/新员工学习/` 返回 `200`
9. 已通过现网检索确认本批 5 个实际生效文件中不再残留以下前端白名单内容：
   - `18285031172`
   - `18685147960`
   - `13885135551`
   - `13668501068`
   - `何梓辛`
   - `周颖`
   - `陈琪琪`
   - `姚修宁`

### 本批结论

1. `B3` 第一轮已完成“总部后台入口显示规则”与后端真实权限口径的统一
2. 现网真实生效页中的前端姓名/手机号白名单显示逻辑已清除
3. 后续如继续推进，应单独梳理 `campaignMenuItem` 等其他业务入口，不要继续复用“总部后台入口显示”规则混合处理

### B3-2

`b3-2-split-campaign-entry-display`

#### 目标

1. 将周年庆入口显示规则从“总部后台入口显示规则”中拆出，避免继续复用 `admin/dashboard.html` 的显示口径
2. 清理周年庆看板前端基于手机号/姓名的总部白名单兜底，改为仅信任正式角色和后端 `campaign auth`
3. 收紧周年庆页前端门店编辑兜底，避免 `managedStores` 未命中时被过宽放行

#### 已执行步骤

1. 已完成现网备份目录创建：`/www/mc-backups/20260516-b3-2-split-campaign-entry-display`
2. 已备份：
   - `/www/wwwroot/122.51.223.46/mobile/mine.html`
   - `/www/wwwroot/122.51.223.46/mobile-mine.html`
   - `/www/wwwroot/122.51.223.46/周年庆数据看板-V5.html`
3. 已将 `/mobile/mine.html` 与 `/mobile-mine.html` 中周年庆入口显示规则调整为独立口径：
   - `canShowAdmin`
   - 或 `role in ['manager', 'sales', 'coach', 'consultant']`
4. 已保留 `/mobile/mine.html` 与 `/mobile-mine.html` 中 `adminMenuItem` 继续使用 `B3-1` 的总部后台显示口径
5. 已补齐两份移动端个人中心的角色文案映射：
   - `ceo`
   - `operation`
   - `finance`
   - `consultant`
6. 已从 `/周年庆数据看板-V5.html` 删除前端手机号/姓名 HQ 白名单：
   - `HQ_WHITELIST_PHONES`
   - `HQ_WHITELIST_NAMES`
7. 已将周年庆页前端 HQ 兜底改为仅认正式总部角色：
   - `admin`
   - `ceo`
   - `operation`
   - `finance`
8. 已修正周年庆页 `canEditStore()` 前端门店权限兜底：
   - `canEditAllData=true` 时放行全部门店
   - `managedStores` 有值时仅允许命中门店
   - 否则仅在 `canEditStoreAllData=true` 时放行
9. 已将周年庆页可选门店列表改为按 `canEditStore()` 过滤，不再直接展示全部门店
10. 已完成页面访问复核：
   - `https://supercalf.com/mobile/mine.html` 返回 `200`
   - `https://supercalf.com/mobile-mine.html` 返回 `200`
   - `https://supercalf.com/周年庆数据看板-V5.html?v=20260509b` 返回 `200`
11. 已通过现网源码检索确认：
   - 两份 `mine` 页已出现独立的 `canAccessCampaign`
   - 周年庆页已不存在 `HQ_WHITELIST_*`
   - 周年庆页已改为 `HQ_ROLE_CODES=['admin','ceo','operation','finance']`

#### 本批结论

1. `B3` 第二轮已完成“周年庆入口显示规则”与“总部后台入口显示规则”的拆分
2. 周年庆前端不再使用姓名/手机号白名单直接授予总部权限
3. 周年庆页前端门店权限兜底已从过宽放行收紧为基于 `campaignAuth` 的显式判断
4. 后续可继续推进 `internal.html`、`admin/dashboard.html` 中周年庆一级入口的长期降级或隔离

### C1-1 / H1-1

`c1-1-downgrade-campaign-primary-entry`

#### 目标

1. 将 `internal.html` 中阶段性专题“周年庆”从长期一级工作台入口降级
2. 将 `admin/dashboard.html` 中“周年庆后台”从总部后台快捷入口中移除
3. 保留长期系统入口，减少活动页继续侵入工作台首页和后台首页

#### 已执行步骤

1. 已完成现网备份目录创建：`/www/mc-backups/20260516-c1-1-downgrade-campaign-primary-entry`
2. 已备份：
   - `/www/wwwroot/122.51.223.46/internal.html`
   - `/www/wwwroot/122.51.223.46/admin/dashboard.html`
3. 已从 `/internal.html` 的数据概览中移除周年庆统计卡：
   - `🔥 7周年庆`
   - `campaignDay`
4. 已从 `/internal.html` 的核心工作入口中移除：
   - `7周年庆作战看板`
5. 已同步移除 `/internal.html` 中仅为该入口服务的周年庆倒计时脚本
6. 已从 `/admin/dashboard.html` 的快捷入口卡片中移除：
   - `周年庆后台 -> 进入`
7. 已完成页面访问复核：
   - `https://supercalf.com/internal.html` 返回 `200`
   - `https://supercalf.com/admin/dashboard.html` 返回 `200`
8. 已通过现网源码检索确认：
   - `internal.html` 中不再残留 `周年庆`、`campaignDay`、`作战看板` 一级入口代码
   - `admin/dashboard.html` 中不再残留 `周年庆后台 -> 进入` 快捷入口代码
9. 当前 `admin/dashboard.html` 仅剩一条与数据口径有关的说明文案：
   - `按周年庆录入表统计`
   - 该项属于指标来源说明，不属于入口暴露

#### 本批结论

1. `internal.html` 已从“长期工作台首页 + 活动页一级入口”收敛为更偏长期系统入口的首页
2. `admin/dashboard.html` 已不再把周年庆页作为后台一级快捷入口暴露
3. 周年庆专题已从“入口层长期暴露”进一步降级为“按需访问的活动页面”

### C2-1

`c2-1-respect-skip-auto-nav`

#### 目标

1. 让 `__SKIP_AUTO_INTERNAL_AUTH__` 同时跳过自动鉴权和自动导航改写
2. 降低 `internal-auth.js` 对已手写导航页面的二次接管，缓解“静态壳 + 动态壳”双实现并存问题
3. 以现网真实状态为准，先处理正式生效的 `internal-auth.js`，不把工作区/历史副本中的 `js-auth.js` 误当成当前线上主阻塞项

#### 已执行步骤

1. 已确认现网正式根目录存在：
   - `/www/wwwroot/122.51.223.46/internal-auth.js`
2. 已确认现网正式根目录不存在：
   - `/www/wwwroot/122.51.223.46/js-auth.js`
3. 已确认当前线上大量页面显式使用 `/internal-auth.js?v=5`
4. 已确认以下页面设置了 `window.__SKIP_AUTO_INTERNAL_AUTH__ = true`，但此前仍会被 `internal-auth.js` 自动改写顶部导航：
   - `/internal.html`
   - `/doc-viewer.html`
   - `/admin/dashboard.html`
   - `/admin/learning.html`
   - 多个后台子页
5. 已完成现网备份目录创建：`/www/mc-backups/20260516-c2-1-respect-skip-auto-nav`
6. 已备份：
   - `/www/wwwroot/122.51.223.46/internal-auth.js`
7. 已在 `/internal-auth.js` 中新增统一变量：
   - `const shouldSkipAutoInternalAuth = !!window.__SKIP_AUTO_INTERNAL_AUTH__`
8. 已调整公共脚本行为：
   - `shouldSkipAutoInternalAuth=true` 时不再执行初始 `unifyTopNav()`
   - `requirePageAuth()` 成功后也不再自动执行 `unifyTopNav(result.user)`
   - 未设置 `skip` 的页面继续保留统一导航能力
9. 已完成线上关键片段复核：
   - `shouldSkipAutoInternalAuth`
   - `if (!shouldSkipAutoInternalAuth)`
10. 已完成页面访问复核：
   - `https://supercalf.com/internal.html` 返回 `200`
   - `https://supercalf.com/admin/dashboard.html` 返回 `200`
   - `https://supercalf.com/知识库/` 返回 `200`

#### 本批结论

1. `C2` 已先完成一轮最小止血：公共脚本不再强行接管明确声明 `skip` 的页面导航
2. 现网“公共导航统一来源”问题仍未彻底收敛，但已从“脚本无条件二次改写”降到“只接管未声明跳过的页面”
3. `js-auth.js` 当前更接近工作区/历史副本遗留问题，不是现网正式根目录的当前主阻塞项

### C2-2

`c2-2-classify-nav-ownership`

#### 目标

1. 以现网真实页面为准，梳理“公共导航托管页”和“页面自带导航页”
2. 缩小 `C2` 后续治理范围，避免继续把工作区/历史副本问题误判为正式页主阻塞项
3. 为后续是否继续扩展 `skip`、或反向收口页面自带导航，先建立现网分组事实

#### 已执行步骤

1. 已通过现网目录扫描三类页面：
   - 含 `<nav class="nav">` 的页面
   - 引入 `/internal-auth.js` 的页面
   - 声明 `__SKIP_AUTO_INTERNAL_AUTH__` 的页面
2. 已确认正式现网页中，存在大量“手写导航 + 引入 `/internal-auth.js`”并存页面
3. 已确认真正声明 `skip` 的正式页范围当前集中在 11 个页面：
   - `/doc-viewer.html`
   - `/internal.html`
   - `/admin/dashboard.html`
   - `/admin/learning.html`
   - `/admin/performance.html`
   - `/admin/system-dashboard.html`
   - `/admin/staffs.html`
   - `/admin/security-login-audit.html`
   - `/admin/security-devices.html`
   - `/admin/system-errors.html`
   - `/admin/operation-logs.html`
4. 已确认这些 `skip` 页同时都具备页面自带导航，适合作为“页面自带导航负责”的正式口径
5. 已确认其他大量内容页、培训页、知识页、工具页虽然也有手写导航，但当前仍引入 `/internal-auth.js`，属于后续可继续细分的“待收敛混合页”
6. 已确认本轮统计结果还包含：
   - `tmp_upload/`
   - `tmp_upload2/`
   - `site_current/`
   - 其他训练/历史副本目录
   - 这些路径需要与正式现网页分开判断，不能直接代表当前对外正式入口

#### 本批结论

1. `C2` 当前最明确的一组“页面自带导航页”就是上述 11 个 `skip` 正式页
2. 现网当前可按两层口径理解：
   - 第一层：`skip` 正式页由页面自带导航负责
   - 第二层：其他引入 `/internal-auth.js` 的页暂继续由公共脚本托管或处于混合过渡状态
3. 后续 `C2` 若继续推进，建议优先只处理正式现网页，不再把 `tmp_upload`、`tmp_upload2`、`site_current` 等历史/同步副本直接纳入同批治理范围

### C2-3

`c2-3-training-center-nav-ownership`

#### 目标

1. 选择一组结构高度一致的正式页，验证“页面自带导航负责”口径可以按目录整组落地
2. 优先收口 `training-center/*.html` 这组“有手写导航 + 引入 internal-auth.js + 未声明 skip”的正式页
3. 在不改导航内容的前提下，只补充归属声明，降低公共脚本对该组页面的二次接管

#### 已执行步骤

1. 已确认 `training-center/` 正式目录下以下页面都具备：
   - 页面自带 `<nav class="nav">`
   - 引入 `/internal-auth.js?v=5`
   - 原先未声明 `__SKIP_AUTO_INTERNAL_AUTH__`
2. 已完成现网备份目录创建：`/www/mc-backups/20260516-c2-3-training-center-nav-ownership`
3. 已备份以下正式页：
   - `/training-center/index.html`
   - `/training-center/overview.html`
   - `/training-center/role-paths.html`
   - `/training-center/coach-class-standards.html`
   - `/training-center/consultative-sales.html`
   - `/training-center/monthly-certification.html`
   - `/training-center/sales-foundation.html`
   - `/training-center/trainer-playbook.html`
   - `/training-center/weekly-drills.html`
4. 已在上述 9 个页面中统一补充：
   - `window.__SKIP_AUTO_INTERNAL_AUTH__ = true`
5. 本批未改动任何页面导航结构、导航文案和链接顺序，只调整导航归属声明
6. 已完成页面访问复核：
   - `https://supercalf.com/training-center/` 返回 `200`
   - `https://supercalf.com/training-center/overview.html` 返回 `200`
   - `https://supercalf.com/training-center/coach-class-standards.html` 返回 `200`
7. 已通过现网源码抽样确认：
   - `/training-center/index.html`
   - `/training-center/overview.html`
   - `/training-center/coach-class-standards.html`
   均已存在 `window.__SKIP_AUTO_INTERNAL_AUTH__ = true`

#### 本批结论

1. `training-center/*.html` 已正式收敛到“页面自带导航负责”的口径
2. `C2` 已验证可以按“结构相近目录整组收口”的方式推进，而不必一次性处理全站所有混合页
3. 后续若继续推进 `C2`，可优先按目录选择下一组相似页面复制同样模式

### C2-4

`c2-4-training-cards-index-nav-ownership`

#### 目标

1. 继续按最小范围推进 `C2`，验证 `training-cards/` 目录是否适合复制 `training-center/` 的整组收口模式
2. 避免把不具备顶部导航结构的卡片详情页误纳入“页面自带导航负责”同批治理

#### 已执行步骤

1. 已核对 `training-cards/` 目录现网结构：
   - `/training-cards/index.html` 有顶部 `<nav class="nav">`
   - `/training-cards/beginner.html`、`/training-cards/intermediate.html`、`/training-cards/advanced.html` 为侧栏卡片页，不具备同一套顶部导航结构
2. 已确认 `training-cards/index.html` 同时满足：
   - 页面自带顶部导航
   - 引入 `/internal-auth.js?v=5`
   - 原先未声明 `__SKIP_AUTO_INTERNAL_AUTH__`
3. 已完成现网备份目录创建：`/www/mc-backups/20260516-c2-4-training-cards-index-nav-ownership`
4. 已备份：
   - `/www/wwwroot/122.51.223.46/training-cards/index.html`
5. 已仅对 `/training-cards/index.html` 补充：
   - `window.__SKIP_AUTO_INTERNAL_AUTH__ = true`
6. 本批未改动：
   - `/training-cards/beginner.html`
   - `/training-cards/intermediate.html`
   - `/training-cards/advanced.html`
7. 已完成页面访问复核：
   - `https://supercalf.com/training-cards/` 返回 `200`
8. 已通过现网源码确认：
   - `/training-cards/index.html` 已存在 `window.__SKIP_AUTO_INTERNAL_AUTH__ = true`

#### 本批结论

1. `training-cards/` 目录不适合按整组统一套用 `training-center` 的处理方式
2. 当前仅 `/training-cards/index.html` 应归入“页面自带导航负责”集合
3. `beginner/intermediate/advanced` 这类侧栏卡片详情页需要按其自身结构单独判断，不能简单复用顶部导航治理策略

### C2-5

`c2-5-knowledge-nav-ownership`

#### 目标

1. 继续按“小目录、结构一致、正式现网页”原则推进 `C2`
2. 收口 `/知识库/` 目录下两页“有顶部导航 + 引入公共脚本 + 未声明 skip”的正式页

#### 已执行步骤

1. 已核对 `/知识库/index.html` 与 `/知识库/viewer.html` 均具备：
   - 页面自带顶部 `<nav class="nav">`
   - 引入 `/internal-auth.js?v=5`
   - 原先未声明 `__SKIP_AUTO_INTERNAL_AUTH__`
2. 已完成现网备份目录创建：`/www/mc-backups/20260516-c2-5-knowledge-nav-ownership`
3. 已备份：
   - `/www/wwwroot/122.51.223.46/知识库/index.html`
   - `/www/wwwroot/122.51.223.46/知识库/viewer.html`
4. 已在上述两页统一补充：
   - `window.__SKIP_AUTO_INTERNAL_AUTH__ = true`
5. 本批未改动导航结构、导航链接与正文逻辑，仅调整导航归属声明
6. 已完成页面访问复核：
   - `https://supercalf.com/知识库/` 返回 `200`
   - `https://supercalf.com/知识库/viewer.html` 返回 `200`
7. 已通过现网源码确认：
   - `/知识库/index.html`
   - `/知识库/viewer.html`
   均已存在 `window.__SKIP_AUTO_INTERNAL_AUTH__ = true`

#### 本批结论

1. `/知识库/` 目录下这两页已正式归入“页面自带导航负责”集合
2. `C2` 继续证明可以按正式目录逐组收口，而无需一次性重写所有公共导航实现
3. 后续可继续按相同原则评估 `/表格中心/`、`doc-viewer.html` 之外的内容目录，或切换到移动端 URL 收敛阶段

### C2-6 / D1-1

`c2-6-d1-1-nav-and-mobile-alias`

#### 目标

1. 继续按小范围推进 `C2`，收口仍具备顶部导航结构的正式页
2. 同步启动 `D1`，先把已确认的移动端平铺兼容页 `mobile-mine.html` 收敛为正式跳转页

#### 已执行步骤

1. 已确认 `/表格中心/index.html` 与 `/v4-sync-center.html` 均具备：
   - 页面自带顶部 `<nav class="nav">`
   - 引入 `/internal-auth.js?v=5`
   - 原先未声明 `__SKIP_AUTO_INTERNAL_AUTH__`
2. 已确认现网当前可识别的平铺移动兼容页只有：
   - `/mobile-mine.html`
3. 已确认 `/mobile-mine.html` 与 `/mobile/mine.html` 在收口前内容高度一致，适合转为兼容跳转页
4. 已完成现网备份目录创建：`/www/mc-backups/20260516-c2-6-d1-1-nav-and-mobile-alias`
5. 已备份：
   - `/www/wwwroot/122.51.223.46/表格中心/index.html`
   - `/www/wwwroot/122.51.223.46/v4-sync-center.html`
   - `/www/wwwroot/122.51.223.46/mobile-mine.html`
6. 已在以下两页补充：
   - `/表格中心/index.html`
   - `/v4-sync-center.html`
   统一新增 `window.__SKIP_AUTO_INTERNAL_AUTH__ = true`
7. 已将 `/mobile-mine.html` 收敛为兼容跳转页：
   - `meta refresh` 指向 `/mobile/mine.html`
   - 保留可见手动跳转链接
   - 通过 `window.location.replace(target)` 跳转
   - 自动透传原查询参数到 `/mobile/mine.html`
8. 已完成页面访问复核：
   - `https://supercalf.com/表格中心/` 返回 `200`
   - `https://supercalf.com/v4-sync-center.html` 返回 `200`
   - `https://supercalf.com/mobile-mine.html` 返回 `200`
9. 已通过现网源码确认：
   - `/表格中心/index.html` 已存在 `window.__SKIP_AUTO_INTERNAL_AUTH__ = true`
   - `/v4-sync-center.html` 已存在 `window.__SKIP_AUTO_INTERNAL_AUTH__ = true`
   - `/mobile-mine.html` 已存在 `meta http-equiv="refresh"` 与 `window.location.replace(target)`

#### 本批结论

1. `/表格中心/index.html` 与 `/v4-sync-center.html` 已归入“页面自带导航负责”集合
2. `mobile-mine.html` 已从双份维护页收敛为指向 `/mobile/mine.html` 的兼容跳转页
3. `D1` 已正式开始落地，当前先完成了一项最明确、风险最低的移动端 URL 收口
4. 现网根目录当前已未发现其他 `mobile-*.html` 平铺兼容页，`D1` 第一轮范围暂可视为基本收清

### E1-0

`e1-0-admin-route-mapping-baseline`

#### 目标

1. 在进入后台正式路由收敛前，先建立现网 `/admin/...` 正式页与根目录/其他目录后台页的真实映射事实
2. 避免把独立工具页、移动端页或历史副本误当成 `/admin/...` 的正式镜像副本而误收敛

#### 已执行步骤

1. 已盘点现网 `/admin/` 目录正式后台页：
   - `/admin/dashboard.html`
   - `/admin/learning.html`
   - `/admin/performance.html`
   - `/admin/workload.html`
   - `/admin/system-dashboard.html`
   - `/admin/staffs.html`
   - `/admin/security-login-audit.html`
   - `/admin/security-devices.html`
   - `/admin/system-errors.html`
   - `/admin/operation-logs.html`
2. 已盘点现网根目录 `admin*.html` 文件：
   - `/admin-upload.html`
3. 已逐项检查 `/admin/*.html` 是否存在同名根目录平铺副本，结果：
   - 当前未发现 `/admin/dashboard.html`、`/admin/learning.html`、`/admin/performance.html`、`/admin/workload.html` 等正式后台页的同名根目录镜像副本
4. 已确认当前对外正式入口和页面内后台跳转已广泛统一指向：
   - `/admin/dashboard.html`
   - `/admin/learning.html`
   - `/admin/performance.html`
   - `/admin/workload.html`
   - `/admin/system-dashboard.html`
   - 以及其下游 `/admin/*` 子页
5. 已确认以下文件虽与后台能力相关，但不应与 `/admin/*.html` 正式路由映射问题混为一类：
   - `/admin-upload.html`：独立上传工具页
   - `/mobile/admin.html`：移动端后台页
   - `site_current/*`：同步副本/历史副本
6. 已确认 `E1` 当前更像“先确认正式后台路由已成型，再判断独立工具页是否需要后续单独归类”，而不是立即执行大规模副本收敛

#### 本批结论

1. 当前现网 `/admin/*.html` 正式后台路由本身已经比早期审计判断更干净，未发现同名根目录镜像副本并存问题
2. `E1` 后续重点应从“收平铺后台副本”调整为“识别独立后台工具页、移动端后台页、历史副本各自职责”，而不是直接做文件级合并
3. 下一步如果继续推进 `E1`，应优先单独梳理 `admin-upload.html`、`mobile/admin.html` 与 `/admin/*` 的职责边界

### E1-1

`e1-1-classify-admin-upload-as-tool-page`

#### 目标

1. 明确 `/admin-upload.html` 是否属于 `/admin/*` 正式后台路由体系，避免误并到后台正式页收敛批次
2. 核对其页面壳层、入口来源、返回链路与接口鉴权边界，判断后续应做“路由合并”还是“独立工具页最小正式化”

#### 已执行步骤

1. 已基于现网审计记录确认：
   - `/admin-upload.html` 在线上可访问，返回 `200`
   - 被 `ai-analyzer.html`、`ai-drill.html` 反向链接使用
2. 已核对工作区正式后台接口：
   - `/api/admin/upload.php` 已在前序批次接入 `adminRequireAuth('adminCanAccessHeadquarter')`
   - `/api/admin/template.php` 当前也已接入 `adminRequireAuth('adminCanAccessHeadquarter')`
3. 已检查当前工作区事实：
   - 工作区不存在正式 `admin-upload.html`
   - 工作区也不存在正式 `ai-analyzer.html`、`ai-drill.html`
   - 说明这组页面目前只能继续以“现网真实文件”作为唯一判断依据，不能依赖工作区副本推导
4. 已从页面实现临时分析副本中确认：
   - `admin-upload.html` 当前更像独立上传工具页
   - 原始页面缺少明确的正式后台返回入口
   - 原始页面未体现统一登录态前置校验，存在“接口虽已收口，但页面壳层仍偏裸页”的问题
5. 已形成最小化处理方向：
   - 不把 `/admin-upload.html` 直接并入 `/admin/*`
   - 优先补正式后台返回链路、统一登录态校验和页面壳层边界
   - 不改上传和模板下载业务接口本体

#### 本批结论

1. `/admin-upload.html` 当前应归类为“独立 AI 配套上传工具页”，而不是 `/admin/*` 正式后台子页镜像
2. `E1` 对该页的正确处理方式是“保留独立页，补壳层和边界”，而不是直接做后台路由合并
3. `E1` 下一优先对象可切换到 `/mobile/admin.html` 的职责边界，或回到剩余正式页导航归属收口

### E1-2

`e1-2-classify-mobile-admin-as-legacy-shell`

#### 目标

1. 明确 `/mobile/admin.html` 是否仍是独立有效的移动后台正式页，还是历史遗留移动壳
2. 核对其前端访问口径与实际后台接口权限是否一致，避免继续保留一个“看似可访问、实则权限漂移”的入口

#### 已执行步骤

1. 已核对工作区中的正式移动后台页与平铺兼容页：
   - `/real_sync/mobile/admin.html`
   - `/real_sync/mobile-admin.html`
2. 已确认两者当前内容实质相同：
   - 都显示“管理员中心”
   - 都调用 `/api/admin/dashboard.php`
   - 都调用 `/api/admin/staff-list.php`
   - 都调用 `/api/admin/staff-toggle-status.php`
   - 都调用 `/api/admin/reset-password.php`
3. 已核对其前端访问控制实现：
   - 无 token 时跳到 `/mobile/login.html`
   - 有 token 时调用 `/api/auth/me.php`
   - 前端仅以 `role === 'admin' || role === 'manager'` 作为放行条件
4. 已核对页面依赖接口的真实后端权限：
   - `/api/admin/dashboard.php` 要求 `adminCanAccessHeadquarter`
   - `/api/admin/staff-list.php` 要求 `adminCanAccessHeadquarter`
   - `/api/admin/staff-toggle-status.php` 要求 `isSuperAdminUser`
   - `/api/admin/reset-password.php` 要求 `isSuperAdminUser`
5. 已与当前正式桌面后台事实对照：
   - 站内其他正式后台入口已广泛统一到 `/admin/dashboard.html`
   - `internal.html`、`mobile/mine.html`、`mobile-mine.html` 等处的后台入口也已统一指向 `/admin/dashboard.html`

#### 本批结论

1. `/mobile/admin.html` 当前不是一个权限模型自洽的独立正式后台页，而更像历史遗留的移动后台壳
2. 它的前端放行口径仍停留在旧的 `admin/manager` 视角，但实际调用的后台接口已经切换到总部后台和超管口径，前后端明显漂移
3. 从站内正式入口收敛情况看，当前唯一正式后台入口应继续视为 `/admin/dashboard.html`
4. `E1` 后续对 `/mobile/admin.html` 的正确方向应是“收敛为兼容入口或跳转入口”，而不是继续扩展其独立职责

### E1-3

`e1-3-collapse-mobile-admin-entry`

#### 目标

1. 把已确认权限漂移的移动后台旧页收敛为兼容入口
2. 继续固定 `/admin/dashboard.html` 为后台唯一正式入口，避免保留第二套移动后台壳

#### 已执行步骤

1. 已参考 `mobile-mine.html` 的兼容收敛方式，对以下两个页面执行最小化收口：
   - `/real_sync/mobile/admin.html`
   - `/real_sync/mobile-admin.html`
2. 已将上述两个页面从“独立后台壳”替换为“兼容跳转页”：
   - `meta refresh` 指向 `/admin/dashboard.html`
   - 页面保留可见“进入管理中心”链接
   - 页面保留“返回个人中心”链接
   - 通过 `window.location.replace(target)` 自动跳转
   - 自动透传原查询参数到 `/admin/dashboard.html`
3. 已复核收口结果：
   - 两页均不再调用 `/api/admin/dashboard.php`
   - 两页均不再调用 `/api/admin/staff-list.php`
   - 两页均不再调用 `/api/admin/staff-toggle-status.php`
   - 两页均不再调用 `/api/admin/reset-password.php`
   - 两页当前仅保留兼容跳转逻辑

#### 本批结论

1. `mobile/admin.html` 与 `mobile-admin.html` 已从历史移动后台壳收敛为兼容入口
2. 后台正式入口继续唯一固定为 `/admin/dashboard.html`
3. `E1` 在移动后台方向上的主要职责边界已明确，后续应优先继续梳理其他独立工具页或历史副本，而不是再维护第二套移动后台页面

### E1-4

`e1-4-mark-flat-admin-pages-as-copies`

#### 目标

1. 明确工作区中仍存在的平铺后台页是否属于正式页，避免后续继续把它们与 `/admin/*` 正式路由并行维护
2. 为后续“历史副本收敛”提供清晰分类依据

#### 已执行步骤

1. 已抽样核对以下工作区平铺后台页：
   - `/real_sync/admin-dashboard.html`
   - `/real_sync/admin-system-dashboard.html`
   - `/real_sync/admin-workload.html`
2. 已确认其共同特征：
   - 页面内容分别对齐 `/admin/dashboard.html`、`/admin/system-dashboard.html`、`/admin/workload.html` 的后台主题
   - 页面内导航和跳转目标均指向 `/admin/*` 正式路由
   - 当前站内正式入口已广泛统一到 `/admin/dashboard.html` 和 `/admin/*` 子页
3. 已识别到其中一项额外风险：
   - `/real_sync/admin-workload.html` 仍调用 `/api/common/context-test.php`
   - 该接口在线上已被 nginx deny 规则封堵，不应再作为正式页实现基线

#### 本批结论

1. `/real_sync/admin-dashboard.html`、`/real_sync/admin-system-dashboard.html`、`/real_sync/admin-workload.html` 当前应统一视为工作区平铺副本或历史开发副本
2. 它们不应再作为 `/admin/*` 正式后台页的并行正式源继续维护
3. 后续如进入“历史副本清理/兼容收敛”阶段，可优先处理这组三个平铺后台副本
4. `/real_sync/admin-workload.html` 因依赖已封堵的 `/api/common/context-test.php`，风险高于另外两个副本，应在后续收口中优先处理

### E1-5

`e1-5-collapse-flat-admin-copies`

#### 目标

1. 把工作区中已确认不再作为正式源的平铺后台副本收敛为兼容入口
2. 消除 `admin-workload.html` 对已封堵测试接口的继续依赖

#### 已执行步骤

1. 已确认以下三个平铺后台副本当前未发现被正式代码继续作为目标入口引用：
   - `/real_sync/admin-dashboard.html`
   - `/real_sync/admin-system-dashboard.html`
   - `/real_sync/admin-workload.html`
2. 已将上述三个页面统一替换为兼容跳转页：
   - `/real_sync/admin-dashboard.html` -> `/admin/dashboard.html`
   - `/real_sync/admin-system-dashboard.html` -> `/admin/system-dashboard.html`
   - `/real_sync/admin-workload.html` -> `/admin/workload.html`
3. 每个兼容页均保留：
   - `meta refresh`
   - `window.location.replace(target)`
   - 可见的主操作链接
   - 返回上级正式页或工作台的辅助链接
   - 原查询参数透传
4. 已复核结果：
   - 三页都只剩兼容跳转逻辑
   - `/real_sync/admin-workload.html` 已不再引用 `/api/common/context-test.php`

#### 本批结论

1. `admin-dashboard.html`、`admin-system-dashboard.html`、`admin-workload.html` 已从工作区平铺后台副本收敛为兼容入口
2. `/admin/*` 正式后台路由在工作区层面进一步成为唯一正式目标
3. `E1` 当前已基本完成“移动后台旧页 + 平铺后台副本”的主要收口，后续可优先回到独立工具页或转入内容/培训副本阶段

### E1-6

`e1-6-finalize-admin-upload-strategy`

#### 目标

1. 给 `/admin-upload.html` 形成最终收口策略，避免后续再把它误纳入 `/admin/*` 正式后台路由合并
2. 明确这类独立工具页的正式化边界：保留独立职责，但必须接入统一壳层与权限前置

#### 已执行步骤

1. 已复核前序 `E1-1` 结论：
   - `/admin-upload.html` 当前应归类为“独立 AI 配套上传工具页”
   - 它不属于 `/admin/*` 正式后台子页镜像
2. 已结合现有事实确认该页的边界：
   - 页面用途偏 AI 工具配套上传
   - 依赖 `/api/admin/upload.php` 与 `/api/admin/template.php`
   - 两个接口都已接入 `adminRequireAuth('adminCanAccessHeadquarter')`
3. 已确认当前问题不在接口本体，而在页面壳层：
   - 需补正式后台返回链路
   - 需补统一登录态/权限前置
   - 需避免继续以“裸管理页”形态悬挂在根目录
4. 已明确不应采用的方向：
   - 不直接并入 `/admin/*`
   - 不把它改造成总部后台首页或子后台页的一部分
   - 不在本批顺手改动上传/模板下载业务逻辑本体

#### 本批结论

1. `/admin-upload.html` 的最终策略应为：保留独立工具页定位，但补齐正式壳层、统一鉴权前置与返回链路
2. `/admin/*` 正式后台路由与 `admin-upload.html` 的关系应视为“后台体系中的独立工具入口”，而不是“同名镜像页待合并”
3. `E1` 阶段到此已完成后台正式路由、移动后台旧页、平铺后台副本与独立工具页边界的主要分类与收口策略确定

### E1-7

`e1-7-patch-admin-upload-shell`

#### 目标

1. 在现网正式文件上为 `/admin-upload.html` 补齐独立工具页的最小正式化壳层
2. 不改上传/模板下载业务逻辑本体，只补壳层、返回链路、统一鉴权前置与请求头统一

#### 已执行步骤

1. 已完成现网备份目录创建：`/www/mc-backups/20260516-e1-7-admin-upload-shell`
2. 已备份：`/www/mc-backups/20260516-e1-7-admin-upload-shell/admin-upload.html`
3. 已在本地生成完整补丁版本，并通过 scp 上传覆盖现网文件
4. 已在现网 `admin-upload.html` 中完成以下最小化修补：
   - `<head>` 区新增 `window.__SKIP_AUTO_INTERNAL_AUTH__ = true`
   - `<head>` 区新增 `<script src="/internal-auth.js?v=5"></script>`
   - CSS 区新增 `.header-top` 与 `.header-link` 样式
   - `<body>` 区 `.header` 内新增返回条：
     - `<a class="header-link" href="/admin/dashboard.html">返回管理中心</a>`
     - `<a class="header-link" href="/internal.html">返回工作台</a>`
   - JS 区新增 `getToken()`、`authHeaders()`、`canAccessAdmin()` 三个统一工具函数
   - `DOMContentLoaded` 从裸 `loadStats()` 改为先做登录态 + HQ 后台权限校验再放行
   - 无权限时展示拒绝文案并提供返回管理中心链接
   - `/api/auth/me.php` 调用统一带 `authHeaders()`
   - `/api/drill/script-knowledge.php` 统计调用统一带 `authHeaders()` + `cache: 'no-store'`
   - `/api/drill/training-modules.php` 统计调用统一带 `authHeaders()` + `cache: 'no-store'`
   - `/api/admin/upload.php` XHR 上传统一带 `Authorization: Bearer <token>`
   - `/api/admin/template.php` 模板下载调用统一带 `authHeaders()` + `cache: 'no-store'`
5. 已复核现网文件修改结果：
   - `__SKIP_AUTO_INTERNAL_AUTH__` 已生效
   - `internal-auth.js?v=5` 已引入
   - 返回条已插入
   - 统一鉴权前置已生效
   - 所有 fetch/XHR 请求均统一带 Authorization
6. 已验证线上页面返回码：
   - `https://supercalf.com/admin-upload.html` 返回 `200`
   - `https://supercalf.com/admin/dashboard.html` 返回 `200`
   - `https://supercalf.com/internal.html` 返回 `200`

#### 本批结论

1. `/admin-upload.html` 已从裸管理页正式化为具备统一壳层、返回链路与权限前置的独立工具页
2. `E1` 阶段至此完整闭环：后台正式路由、移动后台旧页、平铺后台副本与独立工具页边界均已分类完成并执行了关键修补

### G1-1

`g1-1-collapse-foundation-training-copies`

#### 目标

1. 在 `G` 阶段先选择一组最明确的培训专题平铺副本做最小收敛落地
2. 以 `/training/09-foundation/` 为唯一正式专题入口，停止继续并行维护其平铺与 remote 副本

#### 已执行步骤

1. 已核对以下三个工作区副本内容高度同源，且都对应“销售基础专业培训”专题：
   - `/real_sync/training-09-foundation-index.html`
   - `/real_sync/training-09-foundation-index.remote.html`
   - `/real_sync/index-09-foundation.html`
2. 已结合站内入口事实确认：
   - `new-staff-learning-index.html` 当前已将“销售基础专业培训”入口指向 `/training/09-foundation/`
   - 这三份文件继续并行维护没有必要
3. 已将上述三个副本统一替换为兼容跳转页，正式目标均为：
   - `/training/09-foundation/`
4. 每个兼容页均保留：
   - `meta refresh`
   - `window.location.replace(target)`
   - 可见的“进入正式专题”链接
   - 可见的“返回培训列表”链接
   - 原查询参数透传
5. 已完成本地复核：
   - 三页都只剩兼容跳转逻辑
   - 三页均指向 `/training/09-foundation/`

#### 本批结论

1. `training-09-foundation-index.html`、`training-09-foundation-index.remote.html`、`index-09-foundation.html` 已从培训专题平铺副本收敛为兼容入口
2. `/training/09-foundation/` 在工作区层面进一步成为“销售基础专业培训”的唯一正式专题入口
3. `G` 阶段已经具备可复制模板，后续可按相同方法继续处理其他训练专题平铺副本

### G1-2

`g1-2-collapse-role-training-copy`

#### 目标

1. 继续沿用 `G1-1` 的兼容跳转模式，处理单个平铺培训专题副本
2. 固定 `/training/02-role/` 为“销售角色与工作标准”专题的唯一正式入口

#### 已执行步骤

1. 已核对 `/real_sync/training-02-role.index.html` 当前为完整培训专题页，不只是静态导出片段
2. 已结合站内入口事实确认：
   - `new-staff-learning-index.html` 当前已将“销售角色与工作标准”入口指向 `/training/02-role/`
3. 已将 `/real_sync/training-02-role.index.html` 替换为兼容跳转页，正式目标为：
   - `/training/02-role/`
4. 兼容页保留：
   - `meta refresh`
   - `window.location.replace(target)`
   - 可见的“进入正式专题”链接
   - 可见的“返回培训列表”链接
   - 原查询参数透传
5. 已完成本地复核：
   - 页面当前只剩兼容跳转逻辑
   - 页面统一指向 `/training/02-role/`

#### 本批结论

1. `training-02-role.index.html` 已从培训专题平铺副本收敛为兼容入口
2. `/training/02-role/` 在工作区层面进一步成为“销售角色与工作标准”专题的唯一正式入口
3. `G1` 现已同时覆盖“多副本同专题”与“单副本专题”两类最小收口模式，后续可继续批量推广到其他专题

### G1-3

`g1-3-collapse-reception-training-copy`

#### 目标

1. 把工作区根目录中的平铺培训专题副本继续按相同模板收敛
2. 固定 `/training/03-reception/` 为“首次到店接待8步法”专题的唯一正式入口

#### 已执行步骤

1. 已核对工作区根目录平铺页 `/03-reception.index.html` 当前为完整培训专题副本
2. 已结合站内入口事实确认：
   - `new-staff-learning-index.html` 当前已将“首次到店接待8步法”入口指向 `/training/03-reception/`
3. 已将 `/03-reception.index.html` 替换为兼容跳转页，正式目标为：
   - `/training/03-reception/`
4. 兼容页保留：
   - `meta refresh`
   - `window.location.replace(target)`
   - 可见的“进入正式专题”链接
   - 可见的“返回培训列表”链接
   - 原查询参数透传
5. 已完成本地复核：
   - 页面当前只剩兼容跳转逻辑
   - 页面统一指向 `/training/03-reception/`

#### 本批结论

1. `03-reception.index.html` 已从根目录培训专题平铺副本收敛为兼容入口
2. `/training/03-reception/` 在工作区层面进一步成为“首次到店接待8步法”专题的唯一正式入口
3. 根目录 `01/04/05/06/07/08` 系列平铺专题页与当前模式高度相似，下一步已具备按批次连续处理的条件

### G1-4

`g1-4-collapse-root-training-copy-batch-a`

#### 目标

1. 按与 `G1-3` 相同的兼容跳转模板，批量收敛工作区根目录中一组同模式培训专题平铺副本
2. 固定以下专题目录页为唯一正式入口：
   - `/training/01-brand/`
   - `/training/04-assessment/`
   - `/training/05-trial/`
   - `/training/06-communication/`
   - `/training/07-renewal/`
   - `/training/08-goals/`

#### 已执行步骤

1. 已核对以下 6 个工作区根目录页面当前都属于完整培训专题平铺副本，结构与 `G1-3` 同型：
   - `/01-brand.index.html`
   - `/04-assessment.index.html`
   - `/05-trial.index.html`
   - `/06-communication.index.html`
   - `/07-renewal.index.html`
   - `/08-goals.index.html`
2. 已结合站内入口事实确认：
   - `new-staff-learning-index.html` 当前已分别将上述 6 个专题入口统一指向对应 `/training/<topic>/` 目录页
3. 已将上述 6 个平铺页统一替换为兼容跳转页，正式目标分别为：
   - `/training/01-brand/`
   - `/training/04-assessment/`
   - `/training/05-trial/`
   - `/training/06-communication/`
   - `/training/07-renewal/`
   - `/training/08-goals/`
4. 每个兼容页均保留：
   - `meta refresh`
   - `window.location.replace(target)`
   - 可见的"进入正式专题"链接
   - 可见的"返回培训列表"链接
   - 原查询参数透传
5. 已完成本地复核：
   - 6 个页面当前都只剩兼容跳转逻辑
   - 6 个页面的 `meta refresh`、可见入口链接与 `window.location.replace(target)` 目标均与各自正式专题目录一致

#### 本批结论

1. `01-brand.index.html`、`04-assessment.index.html`、`05-trial.index.html`、`06-communication.index.html`、`07-renewal.index.html`、`08-goals.index.html` 已从根目录培训专题平铺副本收敛为兼容入口
2. `G1` 已覆盖品牌、角色、接待、体测销售、体验课转化、家长沟通、续费转介绍、目标管理、销售基础培训等多组专题的平铺副本收口
3. 当前工作区层面，这一轮已收口的培训专题副本均已与 `new-staff-learning-index.html` 的正式目录入口保持一致，后续可继续转向现网 `/training/` 目录下遗留的 `*-index.html` / `*.index.html` 副本实扫与分批收口

### G1-5

`g1-5-collapse-live-training-flat-copies`

#### 目标

1. 以现网真实基线为准，批量收敛 `/training/` 目录下所有培训专题平铺副本
2. 固定 `/training/<topic>/` 为唯一正式入口，统一替换 `/training/` 内的 `*-index.html` 和 `*.index.html` 平铺副本为兼容跳转页

#### 已执行步骤

1. 已通过 SSH 扫描确认现网 `/training/` 目录下真实存在 15 个平铺副本：
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
2. 已确认现网根目录不存在对应平铺页（`01-brand.index.html` 等返回 404），说明 `G1-4` 的本地收口不与现网冲突
3. 已对比目录版与平铺版文件大小，确认内容高度同源（如 `02-role/index.html` 与 `02-role.index.html` 字节数完全一致）
4. 已创建备份目录：`/www/mc-backups/20260516-g1-5-training-flat-copies/`
5. 已备份全部 15 个平铺副本原始文件到备份目录
6. 已在本地批量生成 15 个兼容跳转页，目标分别为：
   - `/training/01-brand/`、`/training/02-role/`、`/training/03-reception/`
   - `/training/04-assessment/`、`/training/05-trial/`、`/training/06-communication/`
   - `/training/07-renewal/`、`/training/08-goals/`、`/training/09-foundation/`
7. 已通过 SCP 批量上传 15 个兼容跳转页到现网 `/training/` 目录
8. 已验证上传结果：
   - 15 个文件大小均从 30KB~80KB 降至 ~2KB 兼容跳转页
   - 所有 `meta refresh` 和 `window.location.replace(target)` 目标均正确指向对应正式目录
9. 已验证线上 HTTP 返回码：
   - 所有正式目录页 `/training/<topic>/` 返回 `200`
   - 所有存在的兼容跳转页返回 `200`
   - 不存在的命名模式副本（如 `01-brand.index.html`）返回 `404`（符合预期，说明该命名模式本来就不在现网）

#### 本批结论

1. 现网 `/training/` 目录下 15 个培训专题平铺副本已全部从完整专题页收敛为兼容跳转页
2. `/training/<topic>/` 目录页在现网层面成为唯一正式培训专题入口
3. 所有兼容跳转页均保留完整用户体验：`meta refresh`、JS 自动跳转、可见链接入口、查询参数透传
4. `G1` 阶段至此完成从"工作区副本收口"到"现网真实副本收口"的闭环，后续可继续评估 `H` 阶段或其他遗留项

### H1-1

`h1-1-collapse-root-flat-copies-and-block-test-auth`

#### 目标

1. 收敛现网根目录中已有对应目录版的工具页平铺副本
2. 封堵不应公开可达的测试页面

#### 已执行步骤

1. 已通过 SSH 扫描现网根目录 9 个平铺工具/中心页，分类结果：
   - 已收口（无需再动）：`tables.html` -> `/表格中心/`、`mobile-mine.html` -> `/mobile/mine.html`
   - 可立即收口：`weekly-drills.html` -> `/training-center/weekly-drills.html`
   - 需保留为独立页：`fitness-assessment.html`、`fitness-assessment-app.html`、`survey-manage.html`、`stats-center.html`、`training-module.html`、`training-card.html`
   - 高危测试页：`test-auth.html`（暴露认证状态接口）
2. 已备份全部目标文件到 `/www/mc-backups/20260516-h1-1-root-flat-copies/`（含 nginx 配置）
3. 已将 `weekly-drills.html` 替换为兼容跳转页，指向 `/training-center/weekly-drills.html`
4. 初始曾将 `training-card.html` 替换为兼容跳转页，后续在回归核对中发现该页实际承担训练卡详情页职责，因此已从备份恢复原页
5. 已在 nginx 配置新增 `location = /test-auth.html { deny all; return 403; }` 封堵规则
6. 已备份并重载 nginx 配置
7. 已验证线上效果：
   - `test-auth.html` 返回 `403`
   - `weekly-drills.html` 返回 `200`（兼容跳转页）
   - `training-card.html` 返回 `200`（已恢复为独立训练卡详情页）
   - `/training-center/weekly-drills.html` 返回 `200`
   - `/training-cards/` 返回 `200`
   - `/training-center/` 返回 `200`

#### 本批结论

1. `weekly-drills.html` 已从根目录平铺副本收敛为指向 `/training-center/weekly-drills.html` 的兼容跳转页
2. `training-card.html` 经回归核对确认是被 `training-module.html` 调用的独立训练卡详情页，已从备份恢复，不再纳入目录收口对象
3. `test-auth.html` 已在 nginx 层封堵，返回 `403`
4. 6 个需保留为独立页的工具/中心页（`fitness-assessment.html`、`fitness-assessment-app.html`、`survey-manage.html`、`stats-center.html`、`training-module.html`、`training-card.html`）暂不收口，后续需评估补壳层/返回链路

### H1-1A

`h1-1a-restore-training-card-detail-page`

#### 目标

1. 修复 `H1-1` 中对 `training-card.html` 的误收口
2. 恢复训练模块到训练卡详情页的正式链路

#### 已执行步骤

1. 已复核 `training-module.html` 当前跳转链路：
   - `window.location.href = \`/training-card.html?id=${cardId}&module=${moduleId}\``
2. 已复核 `training-card.html` 当前职责：
   - 页面会读取 `id` 与 `module` 参数
   - 页面会调用 `training-cards.php?action=get&id=${cardId}` 加载单卡详情
   - 页面可从详情页返回 `/training-module.html?id=${moduleId}`
3. 已确认 `training-cards/` 目录仅包含 `index.html`、`beginner.html`、`intermediate.html`、`advanced.html`，并不承接训练卡详情参数
4. 已从备份 `/www/mc-backups/20260516-h1-1-root-flat-copies/training-card.html` 恢复现网原页到：
   - `/www/wwwroot/122.51.223.46/training-card.html`
5. 已验证恢复后线上返回码：
   - `https://supercalf.com/training-card.html` 返回 `200`
   - `https://supercalf.com/training-module.html` 返回 `200`

#### 本批结论

1. `training-card.html` 不是目录壳副本，而是训练卡详情页正式入口
2. `training-card.html` 必须与 `training-module.html` 保持双向跳转关系，不应再收敛到 `/training-cards/`

### H1-2

`h1-2-unify-tool-shell-auth`

#### 目标

1. 为仍需保留的独立工具页补统一登录壳层与权限前置
2. 清除页面内残留的独立弱口令/临时登录逻辑

#### 已执行步骤

1. 已通过 SSH 扫描以下 6 个页面的壳层状态与接口依赖：
   - `fitness-assessment.html`
   - `fitness-assessment-app.html`
   - `survey-manage.html`
   - `stats-center.html`
   - `training-module.html`
   - `learning-center-live-index.html`
2. 已确认现状：
   - `fitness-assessment.html`、`fitness-assessment-app.html`、`training-module.html`、`learning-center-live-index.html` 已接入 `internal-auth.js`
   - `survey-manage.html` 未接统一鉴权壳，仍保留 `prompt + auth-jwt.php + 任意密码回退到 123456` 的旧登录逻辑
   - `stats-center.html` 直接调用 `/api/admin/stats.php`，但未做任何登录态与 HQ 权限前置，也未携带 `Authorization`
3. 已核对接口真实权限口径：
   - `/api/admin/stats.php` 后端要求 `adminCanAccessHeadquarter`
   - `/api/survey/*` 后端要求 JWT 登录，不是 HQ 专属，但必须使用正式 Bearer token
4. 已创建备份目录：`/www/mc-backups/20260516-h1-2-tool-shell-auth/`
5. 已备份：
   - `/www/wwwroot/122.51.223.46/survey-manage.html`
   - `/www/wwwroot/122.51.223.46/stats-center.html`
6. 已修补 `survey-manage.html`：
   - 新增 `/internal-auth.js?v=5`
   - 统一改用 `localStorage.getItem('jwt_token') || localStorage.getItem('token')`
   - `DOMContentLoaded` 前置调用 `/api/auth/me.php` 校验登录态
   - 登录失效时清理 token 并跳回 `/internal.html`
   - 删除 `prompt + auth-jwt.php + code || '123456'` 的旧弱口令回退逻辑
   - `/api/survey/*`、`/api/store/list.php` 调用统一复用 Bearer token
7. 已修补 `stats-center.html`：
   - 新增 `/internal-auth.js?v=5`
   - 新增 `getToken()`、`authHeaders()`、`canAccessAdmin()`、`ensureStatsAccess()`
   - 页面初始化前先调用 `/api/auth/me.php` 校验登录态与 HQ 后台权限
   - `/api/admin/stats.php` 调用统一带 `Authorization: Bearer <token>`
   - 未通过校验时跳回 `/internal.html`
8. 已完成线上静态验证：
   - `survey-manage.html` 返回 `200`
   - `stats-center.html` 返回 `200`
   - 现网文件已包含新的统一鉴权代码，且已清除 `123456` 相关回退逻辑

#### 本批结论

1. `survey-manage.html` 已从独立 JWT 壳和弱口令兜底逻辑切换为统一内网 token 壳
2. `stats-center.html` 已补齐 HQ 权限前置与 Bearer 鉴权请求头
3. 阶段 H 中两类高风险壳层漂移问题已被收口：
   - 独立弱口令/提示式登录壳
   - 裸后台统计页未带权限前置

### H2-1

`h2-1-collapse-learning-center-live-alias`

#### 目标

1. 收敛根目录 `learning-center-live-index.html` 历史平铺入口
2. 固定 `/新员工学习/` 为“员工学习中心”的唯一正式目录入口

#### 已执行步骤

1. 已通过 SSH 对比以下 3 个学习中心相关入口：
   - `/learning-center-live-index.html`
   - `/新员工学习/index.html`
   - `/training-center/index.html`
2. 已确认事实：
   - `/新员工学习/` 的标题为“员工学习中心”，导航高亮也指向“学习中心”
   - `/training-center/` 的标题为“培训资料库”，不是同一个业务入口
   - `learning-center-live-index.html` 的标题、导航结构和信息架构都对应“员工学习中心”，因此应收敛到 `/新员工学习/`
3. 已创建备份目录：`/www/mc-backups/20260516-h2-1-learning-center-live-alias/`
4. 已备份 `/www/wwwroot/122.51.223.46/learning-center-live-index.html`
5. 已将 `learning-center-live-index.html` 替换为兼容跳转页，目标固定为 `/新员工学习/`
6. 已完成线上验证：
   - `https://supercalf.com/learning-center-live-index.html` 返回 `200`
   - `https://supercalf.com/新员工学习/` 返回 `200`
   - 现网文件已包含 `meta refresh`、可见正式入口链接与 `window.location.replace(target)`

#### 本批结论

1. `learning-center-live-index.html` 已从历史平铺学习中心壳收敛为指向 `/新员工学习/` 的兼容入口
2. `/新员工学习/` 在现网层面明确成为“员工学习中心”的唯一正式目录入口

### H1-3

`h1-3-unify-training-module-auth`

#### 目标

1. 修复 `training-module.html` 中存在的未定义 `checkAuth()` 和裸 `/api/drill/*` 请求问题
2. 让训练模块页统一复用正式内网 token 壳与 Bearer 请求头

#### 已执行步骤

1. 已通过 SSH 核对 `training-module.html` 当前实现：
   - 页面已引入 `/internal-auth.js?v=5`
   - 页面在 `DOMContentLoaded` 中调用了 `checkAuth()`，但页面内并未定义该函数
   - 页面对以下接口的请求均未带 `Authorization`：
     - `/api/drill/training-modules.php?action=detail&id=...`
     - `/api/drill/training-modules.php?action=cards&id=...`
     - `/api/drill/training-cards.php?action=reset&id=...`
     - `/api/drill/certificates.php?action=issue&module_id=...`
2. 已创建备份目录：`/www/mc-backups/20260516-h1-3-training-module-auth/`
3. 已备份 `/www/wwwroot/122.51.223.46/training-module.html`
4. 已修补页面：
   - 新增 `getToken()`
   - 新增 `authHeaders()`
   - 新增 `checkAuth()`，前置调用 `/api/auth/me.php`
   - 初始化时改为 `checkAuth()` 成功后再加载模块详情和卡片列表
   - 所有 `/api/drill/*` 请求统一带 `Authorization: Bearer <token>` 与 `cache: 'no-store'`
5. 已完成线上验证：
   - `training-module.html` 返回 `200`
   - 现网文件已包含新的统一鉴权代码与 Bearer 请求头

#### 本批结论

1. `training-module.html` 已从“页面引用统一壳，但请求层仍裸连接口”的半收口状态修正为完整统一鉴权页
2. 训练模块相关 `/api/drill/*` 请求已统一纳入正式 Bearer token 体系

### H1-4

`h1-4-clean-fitness-app-dead-login-shell`

#### 目标

1. 清理 `fitness-assessment-app.html` 中已经失效的旧登录显示壳
2. 保留当前生效的统一 Bearer 请求头实现，不改变现有工具流程

#### 已执行步骤

1. 已通过 SSH 核对页面现状：
   - 页面已引入 `/internal-auth.js?v=5`
   - 页面所有记录/API 请求都通过 `buildAuthHeaders()` 走统一请求头
   - 页面残留 `loginPage`、`doLogin()`、空壳 `checkAuth()`，但这些逻辑仅做显示切换，不承担真实鉴权
2. 已创建备份目录：`/www/mc-backups/20260516-h1-4-fitness-app-dead-login-cleanup/`
3. 已备份 `/www/wwwroot/122.51.223.46/fitness-assessment-app.html`
4. 已执行最小清理：
   - 删除无效 `loginPage` DOM
   - 删除 `doLogin()`
   - 删除空壳 `checkAuth()`
   - 删除初始化里无意义的 `checkAuth()` 调用
   - 保留 `buildAuthHeaders()` 和现有记录/AI 接口调用逻辑
5. 已完成线上验证：
   - `fitness-assessment-app.html` 返回 `200`
   - 现网文件已不再包含 `loginPage`、`doLogin()`、空壳 `checkAuth()`

#### 本批结论

1. `fitness-assessment-app.html` 中已经失效的旧密码登录显示壳已被清理
2. 页面当前仅保留正式内网入口与统一 Bearer 请求头体系，不再混杂无效的旧前端登录 UI

### GH-R1

`gh-r1-stage-g-h-recap-and-tail-scan`

#### 目标

1. 对阶段 G/H 已完成对象做抽样复核
2. 输出下一轮剩余尾项清单，避免继续遗漏根目录平铺页与独立工具页

#### 已执行步骤

1. 已抽样验证阶段 G 别名页：
   - `/training/01-brand-index.html`
   - `/training/02-role.index.html`
   - `/training/03-reception.index.html`
   - `/training/09-foundation-index.html`
   均返回 `200`
2. 已抽样验证阶段 H 已修入口：
   - `weekly-drills.html`
   - `learning-center-live-index.html`
   - `survey-manage.html`
   - `stats-center.html`
   - `training-module.html`
   - `fitness-assessment-app.html`
   均返回 `200`
3. 已验证 `test-auth.html` 返回 `403`
4. 已重新盘点现网根目录 HTML 页体量，确认当前状态：
   - 已收口别名小页：`tables.html`、`weekly-drills.html`、`learning-center-live-index.html`
   - 保留独立正式入口页：`admin-upload.html`、`survey-manage.html`、`stats-center.html`、`training-module.html`、`training-card.html`、`fitness-assessment.html`、`fitness-assessment-app.html`
5. 已识别下一批高概率候选页：
   - `coach-class-standards.html`
   - `consultative-sales.html`
   - `monthly-certification.html`
   - `overview.html`
   - `role-paths.html`
   - `sales-foundation.html`
   - `trainer-playbook.html`
6. 已核对这些候选页的内部链接，确认它们与 `/training-center/` 体系在导航结构上高度同型，适合作为后续专项处理对象

#### 本批结论

1. 阶段 G/H 当前已修入口抽样结果正常，没有发现新的显性回归
2. 下一轮最值得推进的是“根目录培训资料平铺页”向 `/training-center/` 正式目录的系统收口

### H2-2

`h2-2-collapse-training-center-root-aliases`

#### 目标

1. 收敛根目录中与 `/training-center/` 正式目录重复的一组培训资料平铺副本
2. 固定 `/training-center/*.html` 为这些培训资料的唯一正式页面入口

#### 已执行步骤

1. 已核对以下 7 组根目录页与 `/training-center/` 目录页：
   - `coach-class-standards.html`
   - `consultative-sales.html`
   - `monthly-certification.html`
   - `overview.html`
   - `role-paths.html`
   - `sales-foundation.html`
   - `trainer-playbook.html`
2. 已确认事实：
   - 根目录页与 `/training-center/` 目录页标题完全一致
   - 文件体量高度接近
   - 现网双份页都可访问，均返回 `200`
   - 内部导航结构明显同型，符合“历史平铺副本 -> 正式目录页”收口条件
3. 已创建备份目录：`/www/mc-backups/20260516-h2-2-training-center-root-aliases/`
4. 已备份上述 7 个根目录页面原件
5. 已将上述 7 个根目录页面统一替换为兼容跳转页，分别指向：
   - `/training-center/coach-class-standards.html`
   - `/training-center/consultative-sales.html`
   - `/training-center/monthly-certification.html`
   - `/training-center/overview.html`
   - `/training-center/role-paths.html`
   - `/training-center/sales-foundation.html`
   - `/training-center/trainer-playbook.html`
6. 每个兼容页均保留：
   - `meta refresh`
   - `window.location.replace(target)`
   - 可见的“进入正式页面”链接
   - “返回培训资料库”链接
   - 查询参数透传
7. 已完成线上验证：
   - 7 个根目录别名页均返回 `200`
   - 7 个 `/training-center/` 正式页面均返回 `200`
   - 现网文件已包含正确的 `/training-center/*.html` 跳转目标

#### 本批结论

1. `coach-class-standards.html`、`consultative-sales.html`、`monthly-certification.html`、`overview.html`、`role-paths.html`、`sales-foundation.html`、`trainer-playbook.html` 已全部从根目录培训资料副本收敛为兼容入口
2. `/training-center/*.html` 在现网层面进一步成为培训资料页的唯一正式入口体系

### GH-R2

`gh-r2-root-tail-closure`

#### 目标

1. 清理阶段 G/H 收口后的根目录剩余候选页判断工作
2. 明确哪些页面已经完成收口，哪些页面应保留为独立正式入口

#### 已执行步骤

1. 已重新盘点根目录剩余候选页，仅剩：
   - `coach-knowledge.html`
   - `internal-beta-checklist.html`
2. 已核对 `coach-knowledge.html`：
   - 页面体量仅 `456` 字节
   - 标题为“跳转中”
   - 当前已直接指向 `/知识库/`
   - 说明该页早已完成兼容收口，无需再处理
3. 已核对 `internal-beta-checklist.html`：
   - 页面体量 `7603` 字节
   - 标题为“内测最小验收清单”
   - 已接入 `/internal-auth.js?v=5`
   - 页面无独立登录壳、无裸 API 调用
   - 页面被 `v4-sync-center.html` 与 `smart-lessons.html` 正式引用
4. 已验证线上返回码：
   - `coach-knowledge.html` 返回 `200`
   - `internal-beta-checklist.html` 返回 `200`
   - `/知识库/` 与 `/training-center/` 均返回 `200`

#### 本批结论

1. `coach-knowledge.html` 已经处于正确的兼容别名状态，无需追加修改
2. `internal-beta-checklist.html` 应保留为独立内测验收页，不属于平铺副本或高风险壳层问题
3. 根目录阶段 G/H 相关尾项已基本清扫完成，后续重点应转向新的业务阶段，而不是继续做同类入口收口

### H2-3

`h2-3-collapse-mobile-root-learning-aliases`

#### 目标

1. 收敛根目录遗留的移动学习体系平铺页
2. 固定 `/mobile/learning.html` 与 `/mobile/pass-map.html` 为唯一正式入口

#### 已执行步骤

1. 已核对 `profile.html`：
   - 当前已是指向 `/mobile/mine.html` 的兼容页
2. 已核对 `pass-map.html`：
   - 与 `/mobile/pass-map.html` 文件内容一致
   - 应归类为根目录历史平铺副本
3. 已核对 `learning.html`：
   - 与 `/mobile/learning.html` 结构基本一致
   - `/mobile/learning.html` 已比根目录页多接入 `/internal-auth.js?v=5` 与 `requirePageAuth({ onAuthed: init })`
   - 站内正式引用均指向 `/mobile/learning.html`
   - 根目录 `learning.html` 应归类为旧版平铺副本
4. 已创建备份目录：`/www/mc-backups/20260516-h2-3-mobile-root-aliases/`
5. 已备份：
   - `/www/wwwroot/122.51.223.46/learning.html`
   - `/www/wwwroot/122.51.223.46/pass-map.html`
6. 已将根目录页面替换为兼容跳转页：
   - `learning.html` -> `/mobile/learning.html`
   - `pass-map.html` -> `/mobile/pass-map.html`
7. 兼容页均保留：
   - `meta refresh`
   - `window.location.replace(target + search)`
   - 可见的正式入口链接
   - 返回上级移动页面链接

#### 本批结论

1. 根目录移动学习体系平铺副本进一步减少
2. `/mobile/learning.html` 与 `/mobile/pass-map.html` 进一步固定为正式移动入口

### GH-R3

`gh-r3-block-wordpress-public-readme-license`

#### 目标

1. 收口 WordPress 默认公开暴露页
2. 降低 `readme.html`、`license.txt` 造成的版本与安装特征暴露

#### 已执行步骤

1. 已核对 `readme.html`：
   - 为标准 WordPress ReadMe 页面
   - 当前可直接返回 `200`
   - 会公开暴露 WordPress 安装说明与版本特征信息
2. 已核对 `license.txt`：
   - 当前也可直接返回 `200`
3. 已确认两者不属于站内正式业务入口，不需要兼容跳转策略
4. 已创建备份目录：`/www/mc-backups/20260516-gh-r3-wordpress-readme-license/`
5. 已备份 nginx 配置：
   - `/www/server/panel/vhost/nginx/122.51.223.46.conf`
6. 已在 nginx HTTPS 主站配置中新增规则：
   - `location = /readme.html { deny all; return 403; }`
   - `location = /license.txt { deny all; return 403; }`
7. 已执行：
   - `nginx -t`
   - `nginx -s reload`
8. 已验证：
   - 服务器本机直连 `readme.html` 返回 `403`
   - 服务器本机直连 `license.txt` 返回 `403`
   - 公网带禁缓存参数后，`readme.html` 与 `license.txt` 均返回 `403`
   - `wp-login.php` 保持 `200`，未误伤正常后台登录入口

#### 本批结论

1. WordPress 默认公开说明页与许可证页已完成现网封堵
2. 本轮属于公开暴露面加固，不涉及业务入口兼容收口

### H2-4

`h2-4-collapse-action-library-root-alias`

#### 目标

1. 收敛根目录遗留的旧动作库公开页
2. 固定 `/action-library/` 为动作库唯一正式入口

#### 已执行步骤

1. 已核对 `lesson-library.html`：
   - 当前已是指向 `/action-library/` 的兼容页
   - 说明现网教案/动作库体系已存在正式目录入口
2. 已核对 `training-cards-action-library.html`：
   - 页面为旧版根目录动作库公开页
   - 未接统一鉴权壳
   - 与 `/action-library/` 职责重叠
3. 已核对 `/action-library/index.html`：
   - 已接 `/internal-auth.js?v=5`
   - 当前为站内正式动作库入口
4. 已创建备份目录：`/www/mc-backups/20260516-h2-4-action-library-root-alias/`
5. 已备份：
   - `/www/wwwroot/122.51.223.46/training-cards-action-library.html`
6. 已将根目录页面替换为兼容跳转页：
   - `training-cards-action-library.html` -> `/action-library/`
7. 兼容页保留：
   - `meta refresh`
   - `window.location.replace(target + search)`
   - 可见的正式入口链接
   - 返回员工首页链接

#### 本批结论

1. 动作库根目录旧公开页已完成收口
2. `/action-library/` 进一步固定为动作库唯一正式入口

### GH-R4

`gh-r4-stage-g-h-root-closure-summary`

#### 目标

1. 对阶段 G/H 的根目录入口收口结果做最终收尾确认
2. 明确哪些页面已完成别名化，哪些页面保留为正式入口

#### 已执行步骤

1. 已重新核对“教案/动作库”入口体系：
   - `lesson-library.html` 已是指向 `/action-library/` 的兼容页
   - `training-cards-action-library.html` 已收口为指向 `/action-library/` 的兼容页
   - `/action-library/` 为当前正式且已接统一鉴权的动作库入口
2. 已重新核对“移动学习体系”入口：
   - `profile.html` -> `/mobile/mine.html`
   - `learning.html` -> `/mobile/learning.html`
   - `pass-map.html` -> `/mobile/pass-map.html`
3. 已重新核对“知识库/制度/培训资料”兼容入口：
   - `knowledge.html` -> `/知识库/`
   - `coach-knowledge.html` -> `/知识库/`
   - `rules.html` -> `/制度标准/`
   - `weekly-drills.html` -> `/training-center/weekly-drills.html`
   - `learning-center-live-index.html` -> `/新员工学习/`
   - `coach-class-standards.html` -> `/training-center/coach-class-standards.html`
   - `consultative-sales.html` -> `/training-center/consultative-sales.html`
   - `monthly-certification.html` -> `/training-center/monthly-certification.html`
   - `overview.html` -> `/training-center/overview.html`
   - `role-paths.html` -> `/training-center/role-paths.html`
   - `sales-foundation.html` -> `/training-center/sales-foundation.html`
   - `trainer-playbook.html` -> `/training-center/trainer-playbook.html`
4. 已重新核对保留正式页：
   - `smart-lessons.html`
   - `v4-sync-center.html`
   - `internal-beta-checklist.html`
   - `周年庆数据看板-V5.html`
   - `fitness-assessment.html`
   - `fitness-assessment-app.html`
   - `survey-manage.html`
   - `stats-center.html`
   - `training-card.html`
   - `training-module.html`
   - `admin-upload.html`

#### 本批结论

1. 阶段 G/H 的根目录入口收口已基本完成
2. 当前根目录已大幅减少历史平铺副本与旧公开页，保留下来的页面大多具备明确业务职责
3. 后续同类工作不应再以“继续扫根目录平铺页”为主，而应转向新的业务问题簇

## 第十九批执行单

### 批次名

`i1-1-stop-default-reset-password-propagation`

### 范围

第一轮只处理后台员工管理相关页面中继续传播默认密码 `123456` 的前端交互文案：

1. `/admin/staffs.html`
2. `/mobile/admin.html`

### 本批目标

1. 阻断后台 UI 继续传播默认密码 `123456`
2. 让重置密码动作改为“管理员显式输入新密码”或“系统生成随机密码”的安全表达
3. 为后续整体下线 `/mobile/admin.html` 旧移动后台页继续铺路

### 当前实施结果

1. 已完成现网备份目录创建：`/www/mc-backups/20260516-i1-1-stop-default-reset-password/`
2. 已完成以下现网文件备份：
   - `/www/mc-backups/20260516-i1-1-stop-default-reset-password/admin-staffs.html`
   - `/www/mc-backups/20260516-i1-1-stop-default-reset-password/mobile-admin.html`
3. 已在服务器文件层完成 `/admin/staffs.html` 调整：
   - 移除“留空则使用 `123456`”提示
   - 改为要求管理员显式输入新密码
   - 增加空值与长度校验
   - 成功提示改为“请通过安全渠道告知该员工”
4. 已在服务器文件层完成 `/mobile/admin.html` 调整：
   - 移除“重置密码为 `123456`”确认文案
   - 改为“系统将生成随机密码”
   - 成功提示改为“请通过安全渠道告知该员工”
5. 已复核后端接口现状：
   - `/api/admin/staff/reset-password.php` 已要求显式传入 `new_password`
   - `/api/admin/reset-password.php` 已生成随机密码，不再写死 `123456`
6. 已确认一个新增治理结论：
   - `/mobile/admin.html` 虽仍在线，但其交互仍属于历史移动后台壳的延续，后续应继续按兼容入口/收口对象处理，而不是继续增强第二套后台能力

### 本批结论

1. 默认密码 `123456` 的前端传播链已在服务器文件层被收口
2. 后续如继续推进，应优先整体下线或收口 `/mobile/admin.html`，避免旧移动后台壳继续残留同类风险

## 第二十批执行单

### 批次名

`i1-2-collapse-mobile-admin-shell`

### 范围

只处理现网历史移动后台页：

1. `/mobile/admin.html`

### 本批目标

1. 终止继续维护第二套移动后台管理壳
2. 收敛 `/mobile/admin.html` 到正式后台 `/admin/dashboard.html`
3. 消除其继续携带旧权限判断、旧交互和旧管理动作的风险

### 当前实施结果

1. 已完成现网备份目录创建：`/www/mc-backups/20260516-i1-2-collapse-mobile-admin-shell/`
2. 已完成以下现网文件备份：
   - `/www/mc-backups/20260516-i1-2-collapse-mobile-admin-shell/mobile-admin.html`
3. 已复核 `mobile/mine.html` 当前事实：
   - `goAdmin()` 本身已直接跳向 `/admin/dashboard.html`
   - 说明移动个人中心的正式后台入口早已不是 `/mobile/admin.html`
4. 已将 `/mobile/admin.html` 替换为兼容跳转页：
   - `/mobile/admin.html` -> `/admin/dashboard.html`
5. 兼容页保留：
   - `meta refresh`
   - `window.location.replace(target + search)`
   - 可见的正式后台链接
   - 返回移动个人中心链接
6. 已通过服务器本机直连确认：
   - `/mobile/admin.html` 返回的兼容页内容正确
   - 跳转目标为 `/admin/dashboard.html`

### 本批结论

1. 历史移动后台页已正式收口
2. 后台正式入口进一步统一到 `/admin/dashboard.html`
3. 后续不应再继续扩展 `/mobile/admin.html` 的任何独立职责

## 第二十一批执行单

### 批次名

`i1-3-unify-internal-login-entry`

### 范围

只处理员工首页中残留的旧前端登录提交流程：

1. `/internal.html`

### 本批目标

1. 移除 `internal.html` 站内直提 `/api/auth-jwt.php` 的旧登录链路
2. 统一员工首页登录入口到现有正式登录页 `/mobile/login.html`
3. 消除 `internal-auth.js + requirePageAuth + 手写登录提交` 三套逻辑并存问题

### 当前实施结果

1. 已完成现网备份目录创建：`/www/mc-backups/20260516-i1-3-unify-internal-login-entry/`
2. 已完成以下现网文件备份：
   - `/www/mc-backups/20260516-i1-3-unify-internal-login-entry/internal.html`
3. 已核对 `internal.html` 现状：
   - 页面已接 `/internal-auth.js?v=5`
   - 页面已使用 `requirePageAuth(...)`
   - 但仍保留手写 `FormData -> /api/auth-jwt.php` 提交流程
4. 已将 `goStaffLogin()` 调整为：
   - 保留手机号/密码非空校验
   - 不再直接请求 `/api/auth-jwt.php`
   - 统一跳转到 `/mobile/login.html?redirect=%2Finternal.html`
5. 已通过服务器本机直连确认：
   - `goStaffLogin()` 已不再包含旧的站内直提登录逻辑

### 本批结论

1. `internal.html` 的登录入口已统一回正式登录页
2. 员工首页不再承担第二套独立前端登录提交流程

## 第二十二批执行单

### 批次名

`i1-4-unify-mobile-pass-map-auth`

### 范围

只处理移动通关地图页中的旧鉴权探测：

1. `/mobile/pass-map.html`

### 本批目标

1. 移除 `mobile/pass-map.html` 对 `/api/auth-jwt.php?action=verify` 的旧依赖
2. 统一移动通关地图页的登录态与角色探测口径到 `/api/auth/me.php`
3. 保持原有岗位切换与通关地图展示逻辑不变

### 当前实施结果

1. 已完成现网备份目录创建：`/www/mc-backups/20260516-i1-4-unify-mobile-pass-map-auth/`
2. 已完成以下现网文件备份：
   - `/www/mc-backups/20260516-i1-4-unify-mobile-pass-map-auth/mobile-pass-map.html`
3. 已核对 `mobile/pass-map.html` 现状：
   - 主数据请求已走 `/api/pass/map.php`
   - 仅 `detectRole()` 仍通过 `/api/auth-jwt.php?action=verify` 获取岗位
4. 已将 `detectRole()` 调整为：
   - 直接请求 `/api/auth/me.php`
   - 继续按 `role` 推导 `userRole`、`canSwitchRole` 与 `currentRole`
5. 已通过服务器本机直连确认：
   - `detectRole()` 已不再依赖旧的 `auth-jwt verify` 接口

### 本批结论

1. 移动通关地图页已完成旧鉴权探测收口
2. 移动端学习链路进一步统一到 `auth/me` 口径

## 第二十三批执行单

### 批次名

`i1-5-validate-mobile-login-token`

### 范围

只处理正式登录页中的旧本地 token 直跳语义：

1. `/mobile/login.html`

### 本批目标

1. 阻止登录页仅凭本地存在 `jwt_token` 就直接判定为已登录
2. 统一用 `/api/auth/me.php` 验证 token 是否仍有效
3. 清理过期或脏 token，避免错误跳转与伪登录态

### 当前实施结果

1. 已完成现网备份目录创建：`/www/mc-backups/20260516-i1-5-validate-mobile-login-token/`
2. 已完成以下现网文件备份：
   - `/www/mc-backups/20260516-i1-5-validate-mobile-login-token/mobile-login.html`
3. 已核对 `mobile/login.html` 现状：
   - 页面本体是正式登录页，继续使用 `/api/auth-jwt.php` 获取登录 token 属于正常职责
   - 但页面原先只要检测到本地 `jwt_token` 就直接跳转，不校验 token 有效性
4. 已新增 `hasValidSession(token)`：
   - 调用 `/api/auth/me.php`
   - 仅在返回成功时判定为有效登录态
5. 已调整 `DOMContentLoaded` 流程：
   - 存在 token 时先验证
   - 无效则清理 `jwt_token` 与 `user_info`
   - 仅在 token 有效时才自动跳转
6. 已通过服务器本机直连确认：
   - 登录页自动跳转逻辑已切换到“先验证、再跳转”

### 本批结论

1. 正式登录页已不再把本地 token 存在本身视为有效登录态
2. 旧登录交互链进一步收口到 `auth/me` 的统一登录态事实来源

## 第二十四批执行单

### 批次名

`i1-6-unify-training-pass-auth`

### 范围

只处理独立通关页的页面壳层鉴权缺口：

1. `/training-pass.html`

### 本批目标

1. 为 `training-pass.html` 补齐统一登录壳与 Bearer 请求头
2. 让页面调用 `/api/drill/*` 时复用正式 token
3. 消除其对未定义/不透明 `checkAuth()` 的隐式依赖

### 当前实施结果

1. 已完成现网备份目录创建：`/www/mc-backups/20260516-i1-6-unify-training-pass-auth/`
2. 已完成以下现网文件备份：
   - `/www/mc-backups/20260516-i1-6-unify-training-pass-auth/training-pass.html`
3. 已核对 `training-pass.html` 现状：
   - 页面为独立正式通关页，不属于平铺副本
   - 页面已接 `/internal-auth.js?v=5`
   - 页面会调用 `/api/drill/training-modules.php` 与 `/api/drill/certificates.php`
   - 但原先未统一携带 Bearer 认证头
4. 已补充页面级统一鉴权辅助：
   - `getToken()`
   - `authHeaders()`
   - `checkAuth()`
5. 已将以下请求统一改为带 `Authorization: Bearer <token>`：
   - `training-modules.php?action=list`
   - `training-modules.php?action=list&role=...`
   - `certificates.php?action=list`
6. `checkAuth()` 已统一改为：
   - 调用 `/api/auth/me.php`
   - 未登录时跳回 `/mobile/login.html?redirect=/training-pass.html`

### 本批结论

1. `training-pass.html` 已从“壳层鉴权不完整的独立页”切换为统一 token 壳
2. 独立训练/通关链路进一步向 `training-module.html` 的正式鉴权模式收敛

## 第二十五批执行单

### 批次名

`i1-7-unify-training-card-auth`

### 范围

只处理训练卡详情页的页面壳层鉴权缺口：

1. `/training-card.html`
2. `/site_current/training-card.html`

### 本批目标

1. 为 `training-card.html` 补齐统一登录壳与 Bearer 请求头
2. 让页面读取训练卡与提交答案时复用正式 token
3. 同步 `site_current` 副本，避免后续发布或切换时回退到旧实现

### 当前实施结果

1. 已完成现网备份目录创建：`/www/mc-backups/20260517-i1-7-unify-training-card-auth/`
2. 已完成以下现网文件备份：
   - `/www/mc-backups/20260517-i1-7-unify-training-card-auth/training-card.html`
   - `/www/mc-backups/20260517-i1-7-unify-training-card-auth/site_current-training-card.html`
3. 已核对 `training-card.html` 现状：
   - 页面已接 `/internal-auth.js?v=5`
   - 页面会调用 `/api/drill/training-cards.php?action=get&id=...`
   - 页面会调用 `/api/drill/training-cards.php?action=submit`
   - 但原先未统一携带 Bearer 认证头
4. 已补充页面级统一鉴权辅助：
   - `getToken()`
   - `authHeaders()`
   - `checkAuth()`
5. 已将以下请求统一改为带 `Authorization: Bearer <token>`：
   - `training-cards.php?action=get&id=...`
   - `training-cards.php?action=submit`
6. `checkAuth()` 已统一改为：
   - 调用 `/api/auth/me.php`
   - 未登录时跳回 `/mobile/login.html?redirect=` 当前页完整路径与查询串
7. 已同步 `site_current/training-card.html` 到与主站一致的已验证版本
8. 已通过服务器本机直连确认：
   - `training-card.html` 实际响应已包含 `getToken()`、`authHeaders()`、`checkAuth()`
   - 读卡与提交答案请求均已接统一认证头

### 本批结论

1. `training-card.html` 已从“训练链路中残留的独立旧壳页”切换为统一 token 壳
2. `training-pass.html`、`training-module.html`、`training-card.html` 三页的训练链路鉴权口径已基本对齐

## 第二十六批执行单

### 批次名

`i1-8-unify-smart-lessons-auth`

### 范围

只处理智慧教案页前端请求未显式复用统一认证头的问题：

1. `/smart-lessons.html`

### 本批目标

1. 让智慧教案页请求 `/smart-lessons-api.php` 时显式复用正式 token
2. 保持页面原有统一登录门禁不变，仅补齐请求层认证头
3. 继续缩小“已接页面门禁但接口请求未统一走 `authHeaders()`”这一类残留

### 当前实施结果

1. 已完成现网备份目录创建：`/www/mc-backups/20260517-i1-8-unify-smart-lessons-auth/`
2. 已完成以下现网文件备份：
   - `/www/mc-backups/20260517-i1-8-unify-smart-lessons-auth/smart-lessons.html`
3. 已核对 `smart-lessons.html` 现状：
   - 页面已接 `/internal-auth.js?v=5`
   - 页面登录门禁由统一脚本自动执行
   - 但原先调用 `/smart-lessons-api.php` 时仍直接写死 `{ 'Content-Type': 'application/json' }`
4. 已将生成教案请求改为：
   - 优先使用 `window.authHeaders({ 'Content-Type': 'application/json' })`
   - 若统一脚本不可用，则退回原有 `Content-Type` 头，避免页面硬崩
5. 已通过服务器本机直连确认：
   - `smart-lessons.html` 实际响应已包含新的请求头构造逻辑

### 本批结论

1. 智慧教案页已从“只靠页面门禁、请求层未显式复用 token”的状态收敛到统一请求口径
2. 独立正式工具页继续向“页面门禁 + 请求层 Bearer 统一”模式靠拢

## 第二十七批执行单

### 批次名

`i1-9-enable-v4-sync-auth-gate`

### 范围

只处理 V4 更新中心页面门禁被显式跳过的问题：

1. `/v4-sync-center.html`

### 本批目标

1. 恢复 `v4-sync-center.html` 的统一自动登录门禁
2. 消除“已接统一脚本但通过 `__SKIP_AUTO_INTERNAL_AUTH__` 跳过鉴权”的页面旁路
3. 保持页面内容、链接结构与导航职责不变，只修正门禁行为

### 当前实施结果

1. 已完成现网备份目录创建：`/www/mc-backups/20260517-i1-9-enable-v4-sync-auth-gate/`
2. 已完成以下现网文件备份：
   - `/www/mc-backups/20260517-i1-9-enable-v4-sync-auth-gate/v4-sync-center.html`
3. 已核对 `v4-sync-center.html` 现状：
   - 页面已接 `/internal-auth.js?v=5`
   - 但头部原先显式设置 `window.__SKIP_AUTO_INTERNAL_AUTH__ = true`
   - 统一脚本在该标记存在时会跳过自动 `requirePageAuth()`
4. 已移除跳过标记：
   - 恢复页面加载后自动执行统一登录门禁
   - 未改动页面结构、文案与链接入口
5. 已通过服务器本机直连确认：
   - `v4-sync-center.html` 响应中已不存在 `__SKIP_AUTO_INTERNAL_AUTH__`
   - `/internal-auth.js?v=5` 仍正常加载

### 本批结论

1. V4 更新中心已恢复为正式受保护内网页面
2. “引了统一鉴权脚本但显式跳过自动门禁”的旁路已被收口

## 第二十八批执行单

### 批次名

`i1-10-enable-static-centers-auth-gate`

### 范围

只处理一组静态正式目录页统一跳过自动鉴权的问题：

1. `/知识库/index.html`
2. `/知识库/viewer.html`
3. `/表格中心/index.html`
4. `/training-center/index.html`
5. `/training-center/coach-class-standards.html`
6. `/training-center/sales-foundation.html`
7. `/training-center/overview.html`
8. `/training-center/trainer-playbook.html`
9. `/training-center/monthly-certification.html`
10. `/training-center/consultative-sales.html`
11. `/training-center/weekly-drills.html`
12. `/training-center/role-paths.html`
13. `/training-cards/index.html`

### 本批目标

1. 恢复上述静态正式目录页的统一自动登录门禁
2. 消除“设置 `__SKIP_AUTO_INTERNAL_AUTH__` 且无手动 `requirePageAuth(...)`”造成的公开旁路
3. 不改动页面结构、文案和内容入口，只修正门禁行为

### 当前实施结果

1. 已完成现网备份目录创建：`/www/mc-backups/20260517-i1-10-enable-static-centers-auth-gate/`
2. 已完成上述目标文件逐个备份
3. 已批量核对这组页面现状：
   - 页面均接入 `/internal-auth.js?v=5`
   - 但原先统一设置 `window.__SKIP_AUTO_INTERNAL_AUTH__ = true`
   - 且页面本身未手动调用 `requirePageAuth(...)`
4. 已移除上述页面中的跳过标记：
   - 恢复页面加载后由统一脚本自动执行登录门禁
   - 未改动页面正文与链接结构
5. 已通过服务器本机直连抽查确认：
   - `/知识库/`
   - `/training-center/`
   - `/training-cards/`
   响应中已不存在 `__SKIP_AUTO_INTERNAL_AUTH__`

### 本批结论

1. 知识库、表格中心、培训中心、培训卡片库这组静态正式页已恢复为受保护内网页面
2. 一批系统性的“统一脚本已接入但自动门禁被跳过”旁路已完成收口

## 第二十九批执行单

### 批次名

`i1-11-frontend-admin-role-gates`

### 范围

只处理后台首页类页面“已登录即可先进入页面壳”的前端角色前置缺口：

1. `/admin/dashboard.html`
2. `/admin/system-dashboard.html`

### 本批目标

1. 在页面层提前拦截无权限员工，避免其先进入后台壳再等待接口 403
2. 保持后端接口权限边界不变，只补齐前端角色前置体验
3. 统一后台首页类页面的“无权限直接回员工首页”行为

### 当前实施结果

1. 已完成现网备份目录创建：`/www/mc-backups/20260517-i1-11-frontend-admin-role-gates/`
2. 已完成以下现网文件备份：
   - `/www/mc-backups/20260517-i1-11-frontend-admin-role-gates/dashboard.html`
   - `/www/mc-backups/20260517-i1-11-frontend-admin-role-gates/system-dashboard.html`
3. 已核对后端接口现状：
   - `/api/admin/dashboard/overview.php` 已要求 `adminCanAccessHeadquarter`
   - `/api/admin/security/login-audit.php` 已要求 `isSuperAdminUser`
4. 已在前端页面层新增角色前置判断：
   - `/admin/dashboard.html` 仅允许总部角色访问
   - `/admin/system-dashboard.html` 仅允许超管角色访问
   - 无权限时直接 `window.location.replace('/internal.html')`
5. 已通过服务器本机直连确认：
   - 两页响应均已包含新的页面层角色前置逻辑

### 本批结论

1. 后台首页类页面已从“页面先打开、接口再拒绝”收口为“页面层直接拦截”
2. 这轮修复提升了权限边界的一致性，也减少了普通员工误入后台壳的暴露面

## 第三十批执行单

### 批次名

`i1-12-frontend-admin-role-gates-extended`

### 范围

只处理后台子页“已登录即可先进入页面壳”的前端角色前置缺口：

1. `/admin/staffs.html`
2. `/admin/performance.html`

### 本批目标

1. 让员工与权限管理页只允许超管进入页面壳
2. 让长期业绩页只允许总部或店长进入页面壳
3. 与后端真实权限口径保持一致，减少无权限用户误入后台子页

### 当前实施结果

1. 已完成现网备份目录创建：`/www/mc-backups/20260517-i1-12-frontend-admin-role-gates-extended/`
2. 已完成以下现网文件备份：
   - `/www/mc-backups/20260517-i1-12-frontend-admin-role-gates-extended/staffs.html`
   - `/www/mc-backups/20260517-i1-12-frontend-admin-role-gates-extended/performance.html`
3. 已核对后端权限口径：
   - `/api/admin/staff/detail.php`、`update.php`、`reset-password.php`、`unbind-wechat.php`、`unlock-account.php` 全部要求 `isSuperAdminUser`
   - `/api/admin/performance/trend.php` 要求 `adminCanAccessPerformance`
4. 已在前端页面层新增角色前置判断：
   - `/admin/staffs.html` 仅允许超管访问
   - `/admin/performance.html` 仅允许总部或店长访问
   - 无权限时统一 `window.location.replace('/internal.html')`
5. 已明确一个保留结论：
   - `/admin/learning.html` 继续保持现状，不做硬拦
   - 因其依赖的统计接口本身允许总部、店长和普通员工按各自权限查看受限范围数据
6. 已通过服务器本机直连确认：
   - 两页响应均已包含新的页面层角色前置逻辑

### 本批结论

1. 后台关键子页进一步从“页面先打开、接口再拒绝”收口为“页面层直接拦截”
2. `staffs` 与 `performance` 的前后端权限口径已进一步对齐

## 第三十一批执行单

### 批次名

`i1-13-frontend-superadmin-role-gates`

### 范围

只处理四个超管后台子页“已登录即可先进入页面壳”的前端角色前置缺口：

1. `/admin/security-login-audit.html`
2. `/admin/security-devices.html`
3. `/admin/system-errors.html`
4. `/admin/operation-logs.html`

### 本批目标

1. 让四个超管后台子页只允许超管进入页面壳
2. 与其后端接口已存在的超管权限要求保持一致
3. 减少普通员工误入审计/设备/异常/操作日志后台页的暴露面

### 当前实施结果

1. 已完成现网备份目录创建：`/www/mc-backups/20260517-i1-13-frontend-superadmin-role-gates/`
2. 已完成以下现网文件备份：
   - `/www/mc-backups/20260517-i1-13-frontend-superadmin-role-gates/security-login-audit.html`
   - `/www/mc-backups/20260517-i1-13-frontend-superadmin-role-gates/security-devices.html`
   - `/www/mc-backups/20260517-i1-13-frontend-superadmin-role-gates/system-errors.html`
   - `/www/mc-backups/20260517-i1-13-frontend-superadmin-role-gates/operation-logs.html`
3. 已核对后端权限口径：
   - `/api/admin/security/login-audit.php` 要求 `isSuperAdminUser`
   - `/api/admin/system/errors.php` 要求 `isSuperAdminUser`
   - `/api/admin/system/operation-logs.php` 要求 `isSuperAdminUser`
   - 设备安全相关后台页也属于超管风险域页面
4. 已在四个页面层新增统一前置判断：
   - 仅允许超管访问
   - 无权限时统一 `window.location.replace('/internal.html')`
5. 已通过服务器本机直连确认：
   - 四页响应均已包含新的超管前置拦截逻辑

### 本批结论

1. 一组超管后台子页已从“页面先打开、接口再拒绝”收口为“页面层直接拦截”
2. 后台审计与安全域页面的前后端权限边界已进一步对齐

## 第三十二批执行单

### 批次名

`i1-14-frontend-workload-role-gate`

### 范围

只处理工作量后台页"已登录即可先进入页面壳"的前端角色前置缺口：

1. `/admin/workload.html`

### 本批目标

1. 让工作量后台页只允许总部或管理层角色进入页面壳
2. 与后端接口已存在的权限要求保持一致
3. 减少普通员工误入工作量监控后台页的暴露面

### 当前实施结果

1. 已完成现网备份目录创建：`/www/mc-backups/20260517-i1-14-frontend-workload-role-gate/`
2. 已完成以下现网文件备份：
   - `/www/mc-backups/20260517-i1-14-frontend-workload-role-gate/workload.html`
3. 已核对后端权限口径：
   - `/api/workload/hq-summary.php` 要求 `appCanViewAll`（仅总部角色）
   - `/api/workload/store-summary.php` 要求 `appRequireEditStore`（仅总部或本店店长）
   - 普通员工对两个接口均无合法访问权限
4. 已新增 `/internal-auth.js?v=5` 接入：
   - 使页面可以使用 `requirePageAuth()` 统一登录门禁
   - 保留 `app-auth.js` + `api-client.js` 供 `ApiClient.get()` 继续正常使用
5. 已替换页面初始化方式：
   - 移除 `document.addEventListener('DOMContentLoaded',init)` 直接调用
   - 改为 `window.requirePageAuth({onAuthed:async(user)=>{...}})` 包裹
   - 在 `onAuthed` 回调中新增角色前置判断：`canAccessWorkload=!!user?.is_hq||['admin','ceo','operation','finance','manager'].includes(role)`
   - 不满足角色条件时 `window.location.replace('/internal.html')`
6. 已通过服务器本机直连确认：
   - 响应已包含 `internal-auth.js`、`requirePageAuth`、`canAccessWorkload`、`location.replace`

### 本批结论

1. 工作量后台页已从"页面先打开、接口再拒绝"收口为"页面层直接拦截"
2. 前后端权限口径已进一步对齐

## 第三十三批执行单

### 批次名

`i1-15-unify-ai-tools-auth`

### 范围

只处理两页 AI 总部工具的统一门禁与请求鉴权缺口：

1. `/ai-analyzer.html`
2. `/ai-drill.html`

### 本批目标

1. 让两页 AI 总部工具只允许总部角色进入页面壳
2. 让关键 POST 请求统一复用 Bearer 认证头
3. 与后端 `adminCanAccessHeadquarter` 权限要求保持一致

### 当前实施结果

1. 已完成现网备份目录创建：`/www/mc-backups/20260517-i1-15-unify-ai-tools-auth/`
2. 已完成以下现网文件备份：
   - `/www/mc-backups/20260517-i1-15-unify-ai-tools-auth/ai-analyzer.html`
   - `/www/mc-backups/20260517-i1-15-unify-ai-tools-auth/ai-drill.html`
3. 已核对后端权限口径：
   - `/api/admin/ai-analyze.php` 要求 `adminRequireAuth('adminCanAccessHeadquarter')`
   - `/api/admin/ai-drill.php` 要求 `adminRequireAuth('adminCanAccessHeadquarter')`
4. 已为两页补齐统一页面门禁：
   - 新增 `/internal-auth.js?v=5`
   - 新增 `canAccessAiTools(user)` 总部角色判断
   - 通过 `window.requirePageAuth({ onAuthed: ... })` 包裹页面初始化
   - 非总部角色统一 `window.location.replace('/internal.html')`
5. 已补齐关键请求的统一认证头：
   - `/ai-analyzer.html` 的上传与确认导入请求已统一复用 `window.authHeaders(...)`
   - `/ai-drill.html` 的 `start/chat/end` 请求已统一复用 `window.authHeaders(...)`
6. 已通过服务器本机直连确认：
   - 两页响应均已包含 `internal-auth.js`、`requirePageAuth`、`canAccessAiTools`、`window.authHeaders`

### 本批结论

1. 两页 AI 总部工具已从“页面先打开、请求再失败”收口为“页面门禁 + 请求认证头统一”
2. AI 工具链路与后台总部权限边界已进一步对齐

## 第三十四批执行单

### 批次名

`i1-16-unify-admin-upload-auth-gate`

### 范围

只处理资料上传页的手写鉴权初始化分支：

1. `/admin-upload.html`

### 本批目标

1. 移除该页对 `__SKIP_AUTO_INTERNAL_AUTH__` + 手写 `/api/auth/me.php` 初始化链路的依赖
2. 统一改为 `requirePageAuth()` 驱动页面进入
3. 保持页面内部业务请求头复用不变，不放大改动面

### 当前实施结果

1. 已完成现网备份目录创建：`/www/mc-backups/20260517-i1-16-unify-admin-upload-auth-gate/`
2. 已完成以下现网文件备份：
   - `/www/mc-backups/20260517-i1-16-unify-admin-upload-auth-gate/admin-upload.html`
3. 已核对后端权限口径：
   - `/api/admin/upload.php` 要求 `adminRequireAuth('adminCanAccessHeadquarter')`
4. 已统一页面初始化鉴权方式：
   - 移除 `window.__SKIP_AUTO_INTERNAL_AUTH__ = true`
   - 新增 `window.requirePageAuth({ onAuthed: async function(user) { ... } })`
   - 沿用现有 `canAccessAdmin(user)` 总部角色判断
   - 无权限时统一 `window.location.replace('/internal.html')`
5. 已保留页面内部业务请求逻辑：
   - `getToken()` 与 `authHeaders()` 继续保留，供上传、模板读取、统计请求复用
   - 避免一次性重构业务层请求实现
6. 已通过服务器本机直连确认：
   - 响应已包含 `requirePageAuth`、`canAccessAdmin`、`location.replace`
   - 响应中已不存在 `__SKIP_AUTO_INTERNAL_AUTH__`

### 本批结论

1. 资料上传页已从“单页手写登录态初始化”收口为统一页面门禁模式
2. 页面进入逻辑与全站正式内网页的鉴权口径已进一步一致

## 第三十五批执行单

### 批次名

`i1-17-collapse-ai-remote-aliases`

### 范围

只处理两页 AI 工具的历史 `.remote.html` 副本：

1. `/ai-analyzer.remote.html`
2. `/ai-drill.remote.html`

### 本批目标

1. 消除根目录新的 AI 工具历史别名暴露面
2. 避免正式页与 `.remote.html` 副本继续分叉
3. 保持旧书签兼容，但统一收口到正式工具页

### 当前实施结果

1. 已完成现网备份目录创建：`/www/mc-backups/20260517-i1-17-collapse-ai-remote-aliases/`
2. 已完成以下现网文件备份：
   - `/www/mc-backups/20260517-i1-17-collapse-ai-remote-aliases/ai-analyzer.remote.html`
   - `/www/mc-backups/20260517-i1-17-collapse-ai-remote-aliases/ai-drill.remote.html`
3. 已核对现网事实：
   - 两个 `.remote.html` 文件在线上真实存在
   - 两个 URL 均可直接返回 `200`
   - 内容与正式 AI 工具页重合，不应继续作为独立维护入口存在
4. 已将两页改为兼容跳转页：
   - `/ai-analyzer.remote.html` -> `/ai-analyzer.html`
   - `/ai-drill.remote.html` -> `/ai-drill.html`
   - 同时保留 `meta refresh` 与 `window.location.replace(...)`
5. 已通过服务器本机直连确认：
   - 两页响应均已包含新的正式页跳转逻辑

### 本批结论

1. AI 工具的历史 `.remote.html` 副本已收口为兼容入口
2. 正式页与历史别名页之间的维护分叉风险已进一步降低

## 第三十六批执行单

### 批次名

`i1-18-unify-stats-center-auth-gate`

### 范围

只处理数据统计中心中“前置校验已写但未实际接入”的鉴权缺口：

1. `/stats-center.html`

### 本批目标

1. 让 `stats-center.html` 真正先经过 HQ 角色前置校验再进入页面壳
2. 统一到 `requirePageAuth()` 驱动的正式页面门禁模式
3. 保留原有 `ensureStatsAccess()` 作为兼容后备分支，不放大改动面

### 当前实施结果

1. 已完成现网备份目录创建：`/www/mc-backups/20260517-i1-18-unify-stats-center-auth-gate/`
2. 已完成以下现网文件备份：
   - `/www/mc-backups/20260517-i1-18-unify-stats-center-auth-gate/stats-center.html`
3. 已核对后端权限口径：
   - `/api/admin/stats.php` 要求 `adminRequireAuth('adminCanAccessHeadquarter')`
4. 已确认原页面缺口：
   - 页面内已存在 `ensureStatsAccess()` 和 `canAccessAdmin()`
   - 但原先实际入口仍为 `document.addEventListener('DOMContentLoaded', loadStats)`
   - 也就是前置校验函数未真正参与页面进入链路
5. 已统一页面初始化方式：
   - 新增 `window.requirePageAuth({ onAuthed: async function(user) { ... } })`
   - 在 `onAuthed` 中执行 `canAccessAdmin(user)` 总部角色判断
   - 无权限时统一 `window.location.replace('/internal.html')`
   - 仍保留 `ensureStatsAccess()` 作为 `requirePageAuth` 不可用时的兼容后备分支
6. 已通过服务器本机直连确认：
   - 响应已包含 `requirePageAuth`、`canAccessAdmin`、`location.replace`、`ensureStatsAccess`

### 本批结论

1. 数据统计中心已从“校验函数存在但未接入”收口为真正生效的页面级 HQ 前置门禁
2. 页面进入逻辑与后端总部权限边界已进一步对齐
