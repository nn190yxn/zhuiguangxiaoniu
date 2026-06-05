# 用户指令记忆

本文件记录了用户的指令、偏好和教导，用于在未来的交互中提供参考。

## 格式

### 用户指令条目
用户指令条目应遵循以下格式：

[用户指令摘要]
- Date: [YYYY-MM-DD]
- Context: [提及的场景或时间]
- Instructions:
  - [用户教导或指示的内容，逐行描述]

### 项目知识条目
Agent 在任务执行过程中发现的条目应遵循以下格式：

[项目知识摘要]
- Date: [YYYY-MM-DD]
- Context: Agent 在执行 [具体任务描述] 时发现
- Category: [代码结构|代码模式|代码生成|构建方法|测试方法|依赖关系|环境配置]
- Instructions:
  - [具体的知识点，逐行描述]

## 去重策略
- 添加新条目前，检查是否存在相似或相同的指令
- 若发现重复，跳过新条目或与已有条目合并
- 合并时，更新上下文或日期信息
- 这有助于避免冗余条目，保持记忆文件整洁

## 条目

[当前 Git 仓库与主代码目录口径]
- Date: 2026-06-05
- Context: 用户指出当前工作区存在多个同 remote 克隆，且真实 Git 仓库内部有两套项目副本目录，容易被误认为两个仓库
- Instructions:
  - 当前真实 Git 仓库是 `/workspace/real_sync`，remote 为 `https://github.com/nn190yxn/zhuiguangxiaoniu.git`。
  - 该仓库内的主代码目录是 `/workspace/real_sync/real_sync/`，后续代码审查、修复和提交默认以这套目录为准。
  - `/workspace/real_sync/追光小牛/` 是旧副本目录，只有在用户明确要求处理该目录时才修改。
  - 涉及工作量系统时，优先检查 `real_sync/api/workload/` 与 `real_sync/mini-program/pages/workload/`。

[GitHub 清理以服务器运行文件为基准]
- Date: 2026-06-05
- Context: 用户确认服务器当前运行基本正常，要求清理 GitHub 时避免出现莫名回退
- Instructions:
  - 对 GitHub 进行清理或同步时，优先以服务器 `/www/wwwroot/122.51.223.46/` 的运行文件为真实基线。
  - 同步方向应默认是服务器到 GitHub，避免用 GitHub 中的旧版本覆盖服务器上的正常运行代码。
  - 清理 GitHub 时先同步同路径已追踪文件，再筛选新增候选，最后处理服务器缺失的旧文件。
  - 涉及删除或移除 GitHub 文件前，必须先确认该文件在服务器运行基线中确实不需要。

[正式上线前需做全量验收]
- Date: 2026-05-18
- Context: 用户要求判断整个网站和小程序是否达到正式上线标准，并明确追加全量验收范围
- Instructions:
  - 在宣布“整个网站和整个小程序达到正式上线标准”前，必须补做全站页面清单级验收。
  - 在宣布“整个网站和整个小程序达到正式上线标准”前，必须补做小程序全页面清单级验收。
  - 在宣布“整个网站和整个小程序达到正式上线标准”前，必须补做核心业务流程端到端回归。
  - 在宣布“整个网站和整个小程序达到正式上线标准”前，必须补做性能、异常流、弱网、兼容性、缓存、发布后回归等更完整检查。

[验收阶段可直接使用系统内真实账号]
- Date: 2026-05-17
- Context: 用户在进入角色验收阶段时明确说明系统内已有真实账号，可直接用于测试
- Instructions:
  - 验收阶段如系统内已存在可用真实账号或可复用登录态，可以直接使用这些真实账号完成角色测试。
  - 不必默认等待用户额外提供一套专门测试账号。
  - 若系统内只有账号身份信息、没有可直接复用的密码或登录态，再最小化向用户确认下一步。

[角色验收优先账号]
- Date: 2026-05-17
- Context: 用户在角色验收阶段明确指定测试对象
- Instructions:
  - 角色验收优先使用陈琪琪和盛明菲这两个真实账号。

[现网专项审查统一台账与修复顺序口径]
- Date: 2026-05-15
- Context: 用户在现网全面审计过程中再次明确执行方式
- Instructions:
  - 当前阶段先把所有专项问题尽量找全，不要边审边进入统一修复阶段。
  - 每一轮专项审查的新发现，都必须追加到同一个总清单文件 `服务器现网全面审计与API专项问题清单_2026-05-15.md` 中，不能分散在多个临时文档里。
  - 在进入修复前，要先确保各专项每一轮的问题都已经并入总清单，避免遗漏。
  - 修复阶段应在问题台账尽量完整后再统一规划和执行。

[全面修复需严格备份与可回滚]
- Date: 2026-05-15
- Context: 用户要求以最严格标准制定全面修复方案，并在更新主方案文档和执行表后开始第一批修复
- Instructions:
  - 全面修复必须采用严格变更治理方式执行，先更新主方案文档和执行表，再分批修复。
  - 每一次修复都必须先备份，且必须可以独立回滚。
  - 每一批修复都要有明确的备份点、验证步骤、回滚方案和台账状态更新。
  - 修复顺序应优先处理高风险和高暴露问题，并坚持小批次、可验证、可回退的执行方式。

[剩余工作区文件分层处理规则]
- Date: 2026-05-11
- Context: 用户确认“不需要全部逐个验证”，要求按线上影响分层处理剩余文件
- Instructions:
  - 线上生产相关文件需要验证后再提交或部署，尤其是 `api/admin/staff/reset-password.php`、`api/admin/security/`、`api/admin/system/`、`js/app-auth.js`、`internal.html` 等。
  - `.monkeycode/MEMORY.md`、`.monkeycode/docs/`、`real_sync/.monkeycode/` 属于本地文档/记忆类，可先做敏感信息扫描后批量提交，不需要线上功能验证。
  - `追光小牛/` 虽是本地工作副本，但包含业务代码、SQL、移动端页面和小程序配置，不能整目录无脑批量提交；如需提交应按模块拆分确认。
  - `save.php`、`summary.php`、`auth-change-password.php`、`mobile-login.html`、`周年庆数据看板-V5.html`、`index-09-foundation.html` 需要先确认是否实际部署上线，再决定验证强度。

[追光小牛企业内网为整体审查对象]
- Date: 2026-05-11
- Context: 用户纠正“不是健身教练项目，而是追光小牛企业内网，审查整个项目”
- Instructions:
  - 后续项目说明、审查交接、安全审查和架构梳理必须以“追光小牛企业内网”整个线上项目为对象。
  - 工作量系统只是企业内网中的一个业务模块，不能把它当作整个项目主体。
  - 给外部架构师或程序员的交接说明应覆盖服务器资源、线上 Web 根目录、WordPress、API、H5、管理后台、微信小程序、脚本、数据库、安全边界和运维配置。

[修复执行顺序与铁律]
- Date: 2026-05-08
- Context: 用户在全项目修复阶段明确要求
- Instructions:
  - 修复顺序固定为：P0 致命错误 -> Security 安全问题 -> P1 潜在 Bug -> Logic 逻辑漏洞。
  - 一次只改一个问题，改一个测一个。
  - 先备份再修改，确保可回滚。
  - 每次修改后必须按清单逐项测试确认。
  - 改密接口优先确认 `config.php` 实际位置再改路径。
  - 涉及事务时必须使用 `try-catch` 包裹。
  - 敏感字段脱敏必须覆盖 `phone`、`openid`，避免遗漏。

[执行方式偏好：直接连远程修复]
- Date: 2026-05-08
- Context: 用户在导航统一任务中明确选择“直接连远程修复（Recommended）”
- Instructions:
  - 当本地工作区缺少目标页面而真实基线在远程目录时，优先直接连接远程环境修复并回读校验。
  - 减少让用户手动同步文件的往返，优先一次性在真实目录完成修复与验收。

[当前仓库为文档型起始项目]
- Date: 2026-04-16
- Context: Agent 在执行“个人成长看板/个人仪表盘”需求分析时发现
- Category: 代码结构
- Instructions:
  - 仓库根目录当前只有 `README.md`、`LICENSE`、`skills/` 和 `.monkeycode/`，没有现成的前端或后端应用目录。
  - 当前项目适合按新项目方式初始化个人网站或仪表盘。

[追光小牛项目目录为分散式结构]
- Date: 2026-05-08
- Context: Agent 在执行“全面熟悉和接手追光小牛项目”时发现
- Category: 代码结构
- Instructions:
  - `site_current/` 不是完整项目快照，只包含部分 PHP API、H5 页面和数据库脚本。
  - 微信小程序代码实际位于 `/workspace/追光小牛/mini-program/`，不在 `site_current/mini-program/` 下。
  - 接手项目时需要同时检查 `/workspace/追光小牛/site_current/`、`/workspace/追光小牛/mini-program/` 以及线上目录 `/www/wwwroot/122.51.223.46/`，不能只看 `site_current/`。

[追光小牛项目以线上目录为真实基线]
- Date: 2026-05-08
- Context: 用户提供最终交接文档时补充
- Category: 代码结构
- Instructions:
  - 凡涉及全局结构判断、模块完整性判断、真实运行逻辑判断、联调、排错和上线准备，默认以线上目录 `/www/wwwroot/122.51.223.46/` 作为真实业务基线。
  - 本地 `/workspace/追光小牛/site_current/` 与 `/workspace/追光小牛/mini-program/` 只视为局部副本、阶段性工作副本或裁剪同步结果，不能单独代表完整项目。
  - 小程序上线准备的首要前置不是直接改配置，而是先确认并找齐真实完整的小程序工程文件。
  - 当前首要工作线是微信小程序上线前准备，优先关注工程完整性、真实 AppID、备案域名、HTTPS、合法 request 域名与上线前终审。

[追光小牛真实代码地图]
- Date: 2026-05-08
- Context: 用户提供“当前真实代码地图”时补充
- Category: 代码结构
- Instructions:
  - 线上根目录 `/www/wwwroot/122.51.223.46/` 的主要分层为：WordPress 站点本体、自定义 API、Mobile/H5 页面、微信小程序源码目录、独立业务页面/后台页面、导入/修复/备份文件。
  - `api/` 下的核心模块包括：基础认证层、`admin/`、`pass/`、`drill/`、`learning/`、`knowledge/`、`policy/`、`points/`、`exam/`、`statistics/` 以及 `campaign/*` 等扩展业务。
  - `mobile/` 是员工 H5 前端，已确认主要页面包括登录、我的、学习中心、知识库、制度、演练、考试、通关、积分、通知、订阅和管理页。
  - 线上 `mini-program/` 才是完整微信小程序源码目录，已确认包含首页、登录、我的、学习、知识库、通知、通关、积分、制度、演练、考试等页面组。
  - 当前主线是小程序上线前准备，依赖链为：确认完整工程、确认真实 AppID、确认备案域名、配置 HTTPS、配置微信合法 request 域名、做上线前终审、上传并提交审核。
  - 高风险点包括：`api/.env.local.php` 中敏感配置、`api/statistics/staff.php` 的字符串拼接风险、`mobile/knowledge-detail.html` 的内容直出风险，以及线上大量 `.bak-*` / 导入 / 修复脚本。

[输出应聚焦真实原因与解决方案]
- Date: 2026-05-08
- Context: 用户在排查登录与录入机制问题时明确要求
- Instructions:
  - 回答应优先定位真实原因，不要长时间停留在连续排查过程汇报。
  - 在确认关键事实后，直接给出可执行的解决方案、风险判断和下一步建议。

[周年庆看板业绩录入权限口径]
- Date: 2026-05-08
- Context: 用户在明确周年庆看板录入权限时补充
- Instructions:
  - 店长可以录入业绩数据。
  - 总部运营、财务也可以录入完整数据。
  - 当前系统内需要重点支持的人员是何梓辛和周颖。

[周年庆看板人员与权限最新口径]
- Date: 2026-05-08
- Context: 用户在确认员工名单、岗位归类和总部权限时补充
- Instructions:
  - 汪志金归属小河店，角色为 `sales`。
  - 盛明菲为 `manager`，兼任小河店和小十字店店长。
  - `实习销售` 统一按 `sales` 处理，`实习教练` 统一按 `coach` 处理。
  - 何梓辛，手机号 `18285031172`，角色为总部运营，应有全量权限。
  - 周颖，手机号 `18685147960`，角色为总部财务，应有全量权限。
  - 陈琪琪，手机号 `13885135551`，角色为总经理，应有全部权限。
  - 姚修宁，手机号 `13668501068`，角色为总部运营，应有全部权限。
  - 需要确认并修正当前登录入口，不应继续落到 WordPress 默认登录入口作为业务登录入口。

[周年庆看板为唯一收紧权限模块]
- Date: 2026-05-08
- Context: 用户在最终确认站点开放范围时补充
- Instructions:
  - 网站除周年庆看板外，其他板块暂时开放给所有员工。
  - 仅周年庆看板需要按门店、岗位和总部角色做精细化录入权限控制。

[登录入口文案统一口径]
- Date: 2026-05-08
- Context: 用户在统一站内登录入口文案时补充
- Instructions:
  - 所有登录入口相关文案统一写成 `手机号登录`。

[安全审查任务执行口径]
- Date: 2026-05-08
- Context: 用户在安排网站与小程序安全审查时补充
- Instructions:
  - 安全相关任务当前以审查、核对、汇总为主，不要发现问题就直接修改。
  - 需要先给出真实现状、审查边界、详细步骤、预期结果和报告结构，供外部审查模型执行。

[后台分层与设备安全权限口径]
- Date: 2026-05-08
- Context: 用户在安排后台建设顺序时补充
- Instructions:
  - 先继续推进第二轮安全修复，再做总部运营后台与超级管理员后台的整体规划。
  - 设备登录预警、设备安全告警及相关审计信息统一归属于超级管理员权限，不放给总部运营后台。

[当前工作区仅有后台入口与统计 API 骨架]
- Date: 2026-05-08
- Context: Agent 在继续梳理后台页面与接口映射时发现
- Category: 代码结构
- Instructions:
  - 当前工作区存在 `internal.html`，其中包含指向 `/admin/dashboard.html` 的管理员入口链接，但当前仓库内未找到对应的实际后台页面文件。
  - 当前工作区已存在的后台相关接口主要是 `api/statistics/store.php`、`api/statistics/staff.php`、`api/statistics/device.php`，可作为后续总部运营后台与超级管理员后台的第一批复用数据源。
  - 在本地工作区推进后台建设时，应将“页面壳 + 新接口”与“复用现有 statistics 接口”并行设计，避免误判为已有完整后台。

[继续安全收口后需同步更新记忆]
- Date: 2026-05-08
- Context: 用户在继续审查 WordPress 公开入口前明确要求
- Instructions:
  - 完成阶段性安全修复后，需要把关键结论和操作方式详细写入 `.monkeycode/MEMORY.md`。
  - 需要把登录服务器的方式一并记录，便于后续继续直接在真实线上目录核查与修复。

[追光小牛线上服务器连接方式]
- Date: 2026-05-08
- Context: Agent 在执行真实线上环境安全修复时发现
- Category: 环境配置
- Instructions:
  - 真实业务基线目录位于远程服务器 `root@122.51.223.46:/www/wwwroot/122.51.223.46/`。
  - 真实线上环境可通过 SSH 连接 `root@122.51.223.46`，具体凭据不得写入项目文件或聊天回复。
  - 真实线上环境可通过 SCP 上传文件到 `root@122.51.223.46:<dst>`，具体凭据不得写入项目文件或聊天回复。
  - 真实线上修复时通常先把目标文件同步到 `/workspace/real_sync/` 修改，再上传回远程站点。

[追光小牛线上 WordPress 公开入口收紧策略]
- Date: 2026-05-08
- Context: Agent 在执行 WordPress 公开入口安全收紧时发现
- Category: 环境配置
- Instructions:
  - 站点 Web 服务为 `nginx`，站点配置目录在 `/www/server/panel/vhost/nginx/122.51.223.46.conf`，根目录 `.htaccess` 为空且不应作为主要拦截点。
  - 当前启用主题是 `astra-child`，WordPress 相关运行时收紧优先落在 `/www/wwwroot/122.51.223.46/wp-content/themes/astra-child/functions.php`。
  - `xmlrpc.php` 已在入口文件层直接返回 `403`，同时主题层也通过 `add_filter('xmlrpc_enabled', '__return_false')` 禁用了 XML-RPC。
  - `wp-trackback.php` 已在入口文件层直接返回 `403`，不再接受 Trackback/Pingback 请求。
  - 站点默认 `default_ping_status` 原先为 `open`，主题层已改为默认返回 `closed`，并移除了 `X-Pingback` 等对外暴露信号。
  - `wp-cron.php` 不能直接封禁，因为 WordPress 内部仍有定时任务；已在 `wp-config.php` 中设置 `DISABLE_WP_CRON=true`，关闭访客触发式伪 cron。
  - 当前服务器已新增系统计划任务 `*/5 * * * * /usr/bin/php /www/wwwroot/122.51.223.46/wp-cron.php > /dev/null 2>&1`，用于替代访客触发的 WordPress cron。
  - 当前服务器 `crontab -l` 应保留 5 行：`LANG`、`LC_ALL`、腾讯云 `stargate` 任务、宝塔自定义站点任务、以及新增的 `wp-cron.php` 任务；修改 cron 时必须避免覆盖前两条既有任务。
  - 公开入口外部验证结果：`http://122.51.223.46/xmlrpc.php` 返回 `403`，`http://122.51.223.46/wp-trackback.php` 返回 `403`，`php /www/wwwroot/122.51.223.46/wp-cron.php` 可正常执行且退出码为 `0`。

[追光小牛安全扫描脚本误报规避规则]
- Date: 2026-05-08
- Context: Agent 在继续执行第二轮线上安全复核时发现
- Category: 测试方法
- Instructions:
  - 远程安全扫描脚本位于 `/root/scan_risky_api.py`、`/root/scan_risky_non_api.py`，本地工作副本位于 `/workspace/real_sync/`。
  - API 与非 API 风险扫描在判断“漏鉴权”时，除了已有登录校验标记，还需要排除三类已收口文件：`CLI only` 脚本、入口层直接 `403` 的文件、以及仅供 `require` 使用且禁止直接访问的库文件。
  - `scan_risky_non_api.py` 还应将 `wp-cron.php`、`xmlrpc.php`、`wp-trackback.php` 视为已知特例，避免把 WordPress 标准入口再次当成业务漏鉴权文件。
  - 按上述规则更新后，线上复跑结果为：`TOTAL_PHP 92`、`RISKY_NO_AUTH 0`、`RISKY_NON_API 0`。

[追光小牛 AI 运行时命名冲突排查结论]
- Date: 2026-05-08
- Context: Agent 在修复智能体测图片识别失败时发现
- Category: 代码模式
- Instructions:
  - `/www/wwwroot/122.51.223.46/api/config.php` 与 `/www/wwwroot/122.51.223.46/api/ai-runtime.php` 中都曾定义 `ai_load_settings()`，在同一请求里同时 `require` 时会触发 `PHP Fatal error: Cannot redeclare ai_load_settings()`。
  - 该问题会直接打断 `/api/ai-services.php`、`/api/pass/voice-assess.php` 等依赖 `ai-runtime.php` 的接口执行，前端会表现为“服务暂时不可用”或识别失败，但根因其实是后端 fatal。
  - 当前修复方式是将 `ai-runtime.php` 内部的配置加载函数改名为 `ai_runtime_load_settings()`，并同步更新该文件内所有调用，避免与 `config.php` 冲突。
  - 以后新增 `require config.php` 的 AI 接口时，应优先检查是否存在同名全局函数，避免再次出现运行时命名冲突。

[追光小牛首次登录强制改密控制口径]
- Date: 2026-05-08
- Context: Agent 在修复“首次登录后未强制修改密码”时发现
- Category: 代码模式
- Instructions:
  - 后端首次改密判定来自 `/www/wwwroot/122.51.223.46/api/auth/me.php` 返回的 `must_change_password`，当前逻辑是检测手机号账号的 `wp_users.user_pass` 是否仍为默认密码 `123456`。
  - 仅在 `/mobile/mine.html` 弹改密框是不够的，因为登录成功后如果直接跳到其他业务页，用户会完全绕过首次改密。
  - 正确做法是登录页 `/mobile/login.html` 在登录成功后立刻调用 `/api/auth/me.php`，若 `must_change_password=true`，强制跳到 `/mobile/mine.html?force_change_password=1`。
  - `/mobile/mine.html` 需要在强制改密场景下阻止关闭改密弹窗，直到密码修改成功后再解除强制状态并清理 `force_change_password` 查询参数。

[周年庆看板录入简化口径]
- Date: 2026-05-09
- Context: 用户在要求简化 7 周年看板录入结构时补充
- Instructions:
  - 不要把 7 周年看板里的录入流程做得过于复杂。
  - 各店直播数据、抖音数据、内容运营数据应合并处理，不再拆成独立复杂入口。
  - 如果已有独立数据录入页面，7 周年作战看板内可以不再保留复杂录入界面。
  - 总部录入入口必须在网页端和移动端都能明确看到，不能继续处于不可见或不可登录状态。

[周年庆看板总部运营权限口径]
- Date: 2026-05-09
- Context: 用户在继续收口周年庆数据录入权限时补充
- Instructions:
  - 直播运营和内容运营的数据录入统一并入 `总部运营录入`。
  - 总部运营人员不需要单独的新权限页，只需要开放全权限，让其可以进入任意录入入口。
  - 如果总部运营需要更新业绩，应直接进入 `店长录入` 录入对应业绩数据。

[周年庆看板最新修复优先级]
- Date: 2026-05-09
- Context: 用户在继续修复 7 周年看板和员工入口问题时补充
- Instructions:
  - 看板中继续保留 `总部运营录入` 文案，但核心是给总部运营开放所有录入权限。
  - 需要优先排查并修复教练在移动端和网页端都无法录入数据的问题。
  - 需要继续排查并修复员工进入“我的”后误跳到 `全体系统一原则` 的错误入口链路。

[周年庆指标分层口径]
- Date: 2026-05-09
- Context: 用户在澄清周年庆录入字段类型时补充
- Instructions:
  - 当前周年庆看板里已有的大多是结果指标。
  - 新增的工作量字段主要属于行为指标和过程指标。
  - 后续设计录入、汇总和展示时，必须把结果指标与行为/过程指标分层处理，不能混为同一口径。

[7周年看板后续处理口径]
- Date: 2026-05-09
- Context: 用户在确认长期系统方向时补充
- Instructions:
  - 7 周年看板属于阶段性专题，不再继续做长期功能扩充。
  - 后续重点转向常态化的工作量管理、过程指标录入、结果指标汇总和经营看板体系。
  - 专题看板未来应作为常态系统的数据消费层，而不是主录入系统。

[工作量凭证图片数量上限口径]
- Date: 2026-05-17
- Context: 用户在继续补完工作量凭证上传链路时明确要求前后端统一收口
- Instructions:
  - 单个指标的凭证图片上传最大数必须统一限制在 10 张以内。
  - 该限制需要前端提示、前端拦截和后端强校验三层同时生效，避免规则分叉。

[追光小牛体测图片评级优先级口径]
- Date: 2026-05-08
- Context: Agent 在排查“何奕辰体重偏胖却显示合格/标准”时发现
- Category: 代码模式
- Instructions:
  - 线上体测页 `/www/wwwroot/122.51.223.46/fitness-assessment-app.html` 中，`/api/records/` 仅保存教练、门店、儿童姓名、年龄和日期等统计台账，不保存任何体测详情或 `weight_rating`。
  - 因此体测评级显示偏差不能归因于 `/api/records/` 历史回显，优先排查当前页 `state.imageRatings` 是否被前端交互覆盖。
  - OCR 回填后如果输入框使用 `oninput="clearImageRating(...);autoRate(...)"`，教练对数值做任何手动微调都会立刻删除图片原始评级，导致页面回退到系统 fallback 口径。
  - 身高、体重这类身体形态项目应优先保留图片原始评级；即使图片评级缺失，幼儿组体重 fallback 也应保持 `偏胖/偏瘦/标准` 口径，而不是回落成 `良好/合格/待提升`。

[追光小牛体测 OCR 豆包视觉兜底口径]
- Date: 2026-05-08
- Context: Agent 在增强体测图片识别稳定性时发现
- Category: 代码模式
- Instructions:
  - 体测 OCR 主链路仍为 `百度 OCR + DeepSeek`，豆包只作为视觉兜底，不替代首轮百度 OCR。
  - 当前兜底触发条件已放宽为：任意体测项目只要“已识别出数值但对应 `_rating` 缺失”，就触发豆包视觉兜底。
  - 豆包接入位于 `/www/wwwroot/122.51.223.46/api/ai-runtime.php`，通过 `ai_doubao_vision()` 调用 `https://ark.cn-beijing.volces.com/api/v3/responses`。
  - 豆包配置键保存在 `ai_settings` 表，键名为 `doubao_api_key`、`doubao_model`。
  - 主结果与豆包结果合并时采用“缺字段补齐”策略：主链路已有值则保留，仅用豆包补主链路缺失字段。
  - OCR 日志文件位于 `/www/wwwroot/122.51.223.46/wp-content/uploads/ocr-logs/fitness-ocr.log`，当前会额外记录 `doubao_triggered`、`doubao_reason`、`doubao_filled_fields`、`doubao_hit`，用于后续统计豆包兜底命中率。
  - 服务端必须先对主链路结果与豆包结果统一做归一化，再交给前端使用；否则即使模型已识别出 `体重.rating=偏胖`，也可能因为返回结构是 `体能测评项目`、`身体形态数据`、`BMI评级`、`standing_long_jump`、`ten_meter_shuttle_run` 等别名而无法落到标准键 `weight_rating`、`standing_jump`、`shuttle_run`。

[追光小牛超管页缺表降级口径]
- Date: 2026-05-08
- Context: Agent 在继续补完后台一期剩余超管页时发现
- Category: 代码模式
- Instructions:
  - 超级管理员剩余页面当前基于 `staffs`、`stores`、`device_logins`、`login_audit_logs`、`admin_operation_logs`、`system_error_logs` 组合实现。
  - 其中 `admin_operation_logs` 允许由后台公共层自动建表，确保重置密码、解绑微信、解锁账号等动作可以自动留痕。
  - `system_error_logs` 当前在已读代码与 SQL 中未确认真实落库，因此 `/api/admin/system/errors.php` 必须优先做“表不存在时返回空结果和说明”的兼容降级，不能直接抛错。
  - 员工动作接口也要按字段存在性做兼容：`openid`、`openid_bound_at`、`failed_login_count`、`account_locked_until` 任一缺失时，应跳过对应写入并返回 `supported=false`，避免线上因库结构未完成而报错。
  - 员工资料编辑页 `/admin/staffs.html` 需要先读取 `/api/statistics/store.php` 获取门店下拉数据，再调用 `/api/admin/staff/update.php` 提交姓名、手机号、岗位、门店和启停状态；状态变更时后端还要同步 `wp_users.user_status`，避免只停用 staff 档案而账号仍可登录。

[追光小牛员工中心与管理中心入口收口规则]
- Date: 2026-05-08
- Context: Agent 在修复“页面入口混乱与导航乱跳”问题时发现
- Category: 代码模式
- Instructions:
  - 员工主入口统一为 `/internal.html`，个人中心主入口统一为 `/mobile/mine.html`，管理后台主入口统一为 `/admin/dashboard.html`。
  - `mobile/mine.html` 的“管理中心”入口固定跳转到 `/admin/dashboard.html`，不再跳转旧的移动管理页链路。
  - `mobile/admin.html` 返回按钮固定跳转 `/mobile/mine.html`，禁止使用 `history.back()` 作为主返回逻辑，避免历史栈导致乱跳。
  - `internal.html` 顶部导航固定保留“我的”业务入口，移除下载型文档入口，避免打断主业务流。
  - 管理入口显示规则已更新为：`user.is_hq`、`user.is_admin`、或 `role in ['admin', 'ceo', 'operation', 'finance']` 时显示。
  - `/internal-auth.js` 仍提供统一导航能力，但已不再适用于所有带顶部 `.site-header .topbar .nav` 的页面无条件自动重写。
  - 当前正式现网页里，声明 `window.__SKIP_AUTO_INTERNAL_AUTH__ = true` 的 11 个页面应由页面自带导航负责，包括 `/internal.html`、`/doc-viewer.html` 及各 `/admin/*` 核心后台页。
  - 页面入口清单文档可保留在 `/ENTRY_GUIDE.md` 用于内部排障，但不作为首页右上角常驻入口。

[现网导航托管分层口径]
- Date: 2026-05-15
- Context: Agent 在继续推进 `C2` 公共导航统一来源时发现
- Category: 代码模式
- Instructions:
  - 现网正式根目录当前只有 `/internal-auth.js`，没有正式生效的 `/js-auth.js`；`js-auth.js` 更接近工作区或历史副本问题。
  - 当前导航治理应优先只针对正式现网页推进，不要把 `tmp_upload/`、`tmp_upload2/`、`site_current/` 等同步副本直接视为同一优先级。
  - `window.__SKIP_AUTO_INTERNAL_AUTH__ = true` 现在表示同时跳过自动鉴权接管和自动导航改写。
  - 当前最明确的“页面自带导航负责”正式页共有 11 个：`/doc-viewer.html`、`/internal.html`、以及 `/admin/dashboard.html`、`/admin/learning.html`、`/admin/performance.html`、`/admin/system-dashboard.html`、`/admin/staffs.html`、`/admin/security-login-audit.html`、`/admin/security-devices.html`、`/admin/system-errors.html`、`/admin/operation-logs.html`。
  - `training-center/*.html` 这一组正式页已在后续治理中统一补充 `window.__SKIP_AUTO_INTERNAL_AUTH__ = true`，现已归入“页面自带导航负责”集合。
  - `training-cards/` 目录当前只应把 `/training-cards/index.html` 视为“页面自带导航负责”页；`beginner/intermediate/advanced` 属于侧栏卡片详情页，不能直接复用顶部导航治理策略。
  - `/知识库/index.html` 与 `/知识库/viewer.html` 已统一补充 `window.__SKIP_AUTO_INTERNAL_AUTH__ = true`，现已归入“页面自带导航负责”集合。
  - `/表格中心/index.html` 与 `/v4-sync-center.html` 已统一补充 `window.__SKIP_AUTO_INTERNAL_AUTH__ = true`，现已归入“页面自带导航负责”集合。
  - 其他仍引入 `/internal-auth.js` 的内容页、培训页、工具页暂视为公共脚本托管页或混合过渡页，后续逐步收敛。

[移动端正式 URL 收敛口径]
- Date: 2026-05-15
- Context: Agent 在开始推进 `D1` 移动端双份页收敛时发现
- Category: 代码模式
- Instructions:
  - 当前移动端正式个人中心 URL 应固定为 `/mobile/mine.html`。
  - `mobile-mine.html` 已收敛为兼容跳转页，不再继续与 `/mobile/mine.html` 双份并行维护。
  - 兼容页应保留 `meta refresh`、JS 跳转和可点击备用链接，并自动透传原查询参数到正式页。
  - 当前现网根目录已未发现其他 `mobile-*.html` 平铺兼容页，后续如再出现，应优先按兼容跳转页而不是双份维护处理。

[后台正式路由映射现网基线]
- Date: 2026-05-15
- Context: Agent 在开始推进 `E1` 后台正式路由统一时发现
- Category: 代码结构
- Instructions:
  - 当前现网 `/admin/*.html` 正式后台页已经较为成型，未发现 `/admin/dashboard.html`、`/admin/learning.html`、`/admin/performance.html`、`/admin/workload.html` 等页面存在同名根目录镜像副本。
  - 当前根目录 `admin*.html` 里已确认的正式相关独立页主要是 `/admin-upload.html`，它应视为独立工具页，不要直接等同于 `/admin/*` 正式后台路由副本。
  - `/mobile/admin.html` 应视为移动端后台页，职责边界需要与 `/admin/*` 桌面后台页分开判断。
  - `site_current/` 下的后台相关页面仍只视为同步副本或历史副本，不代表当前正式后台路由映射事实。

[追光小牛后台一期直接开发口径]
- Date: 2026-05-08
- Context: 用户在确认后台建设下一步时明确要求
- Instructions:
  - 管理员后台不再停留在继续规划或反复确认阶段，直接推进一期开发。
  - 当前优先落地 `/admin/dashboard.html` 与 `/api/admin/dashboard/overview.php`，先实现最小可用首页。
  - 若规格中的理想数据表暂未落库，可先基于现有 `statistics` 接口、`monthly_statistics`、周年庆录入表做兼容聚合，但需保持真实鉴权和权限边界。

[追光小牛后台一期执行与复验口径]
- Date: 2026-05-08
- Context: 用户在确认继续执行后台一期时补充
- Instructions:
  - 需要同时推进 `1+2`，即继续开发并同步落地下一批一期页面，而不是只停在首页。
  - 每完成一段后台开发后都要及时复验，再继续下一段，直到本轮开发计划完成。
  - 对于幼儿组图片中常见的 `BMI=19.7 + BMI评级=偏胖` 场景，如果 `weight` 已识别出但 `weight_rating` 仍缺失，可将 `bmi_rating` 作为最后一层窄口径兜底回填到 `weight_rating`，前提是 `bmi_rating` 属于 `偏胖/偏瘦/标准/正常/偏高/偏低` 之一。

[常态化工作量与经营系统建设口径]
- Date: 2026-05-09
- Context: 用户在确认周年庆看板后续方向时明确要求，Agent 在继续落地系统设计时补充
- Category: 代码结构
- Instructions:
  - 7 周年看板不再继续扩充长期字段，后续仅保运行和修 bug。
  - 长期建设主线转为常态化工作量与经营系统，优先覆盖销售和教练两个岗位。
  - 系统设计必须按“行为层、过程层、结果层、自动计算层”分层，避免把工作量和活动结果混写。
  - 结果指标应尽量由过程数据自动计算生成，率值字段不作为人工直填项。
  - 首批落地文档位于 `.monkeycode/docs/normalized-workload-ops-scope.md`、`.monkeycode/docs/normalized-workload-ops-schema.sql`、`.monkeycode/docs/normalized-workload-ops-api.md`。

[工作量系统启动前置顺序]
- Date: 2026-05-09
- Context: 用户在确认全站较乱后，要求判断工作量系统应在调改前还是调改后启动
- Instructions:
  - 工作量系统不应在全站完全调改完成后再启动，也不应在完全不收口的状态下直接开做。
  - 正确顺序是先完成与工作量系统强相关的最小必要调改，再立即启动工作量系统开发。
  - 最小必要调改至少包括：统一权限口径、统一岗位口径、统一门店映射、统一登录态读取、明确长期系统与周年庆专题边界。

[旧系统最小架构收口方案]
- Date: 2026-05-09
- Context: 用户要求以 10 年架构全栈视角判断破碎网站和小程序的最佳修复方案后确认执行
- Instructions:
  - 不做全站大重构，也不继续在旧结构上无边界堆功能。
  - 先冻结旧结构继续扩张，只允许 P0 bug、登录、权限、数据提交、安全和线上业务兜底类修复。
  - 先做最小架构收口：统一真实基线、统一登录态、统一权限上下文、统一岗位和门店映射、统一主入口。
  - 工作量常态化系统必须作为新标准链路落地，使用统一登录态、统一权限上下文、统一岗位码和统一门店映射。
  - 周年庆系统后续只作为专题展示、专题临时录入或常态系统的数据消费层，不能继续承接长期销售/教练日报和长期经营指标。
  - 小程序后续接同一套 `/api/workload/*`，不再单独发展另一套小程序专用数据口径。

[旧系统第一轮公共层收口结果]
- Date: 2026-05-09
- Context: Agent 在开始修复旧系统时完成第一轮最小公共层落地
- Category: 代码模式
- Instructions:
  - 后端新增统一员工上下文文件 `/www/wwwroot/122.51.223.46/api/common/context.php`，提供 `appRoleCode()`、`appRoleLabel()`、`appStoreNameById()`、`appGetCurrentStaffContext()`、`appRequireStaffContext()`。
  - 前端新增统一认证与请求工具 `/www/wwwroot/122.51.223.46/js/app-auth.js` 和 `/www/wwwroot/122.51.223.46/js/api-client.js`，后续新页面应优先使用这两个工具，不再各自散写 token 读取和请求封装。
  - 只读验证接口 `/www/wwwroot/122.51.223.46/api/common/context-test.php` 已用于验证公共上下文，真实教练账号 `18385534850` 返回 `ROLE=coach`、`STORE=小十字`、`CAN_EDIT_OWN=1`。
  - 后续工作量系统 API 必须基于 `/api/common/context.php` 的统一上下文开发，避免重复出现前后端权限口径不一致。

[旧系统收口补充治理要求]
- Date: 2026-05-09
- Context: 用户在审阅旧系统最小架构收口方案后补充关键治理要求
- Instructions:
  - 在继续重构或修复前必须补上备份与版本控制：线上目录完整快照、数据库备份，以及最低限度的 Git 版本记录，避免 FTP 式上传导致不可回滚。
  - 需要区分生产环境与开发/测试环境；线上目录仍是事实源，但不能长期直接在生产目录试错，后续应建立同机不同端口或不同子目录的测试环境，验证后再同步生产。
  - 统一 API 响应格式为 `{ "code": 0, "message": "success", "data": ... }`；错误统一返回 `{ "code": 403, "message": "错误说明", "data": null }`，前端只处理一套结构。
  - 所有接口必须有统一数据校验层，不能信任前端传入的 `store_id`、`staff_id` 等权限敏感字段；必须结合 `appGetCurrentStaffContext()` 的真实身份校验。
  - 统一登录态还必须包含 token 过期处理：前端统一在请求层拦截 `401`，跳登录或后续接 refresh token；小程序端也必须统一检查 token 过期。
  - 所有网页和小程序前端请求禁止继续散写 `fetch` 或 `wx.request` 直连业务接口，新代码必须走统一请求层，统一带 token、错误处理、超时处理、日志和后续埋点。
  - 旧数据、旧页面、旧接口需要先归档不删除：不再使用的表、页面、接口应进入归档策略，观察期后再清理；废弃接口可返回 `410 Gone` 并给出明确提示。
  - 需要补轻量级日志与问题追踪：关键后端接口至少记录谁、什么时间、调用什么接口、成功或失败，优先使用按天切分的轻量文件日志，便于排查用户反馈。

[旧系统第0阶段治理底座结果]
- Date: 2026-05-09
- Context: Agent 根据用户要求继续执行旧系统收口前的备份与版本控制治理
- Category: 环境配置
- Instructions:
  - 已创建生产站点完整快照：`/www/mc-backups/20260510-002447/site-122.51.223.46.tar.gz`。
  - 已创建数据库备份：`/www/mc-backups/20260510-002447/database-_122_51_223_46-notablespaces.sql.gz`；因 MySQL 用户缺少 `PROCESS` 权限，备份脚本必须使用 `mysqldump --no-tablespaces`。
  - 已在生产目录 `/www/wwwroot/122.51.223.46/` 初始化最低限度 Git 仓库，首次基线提交为 `87ee733 chore: capture production baseline before cleanup`。
  - `wp-config.php` 与 `api/config.php` 保持未跟踪，避免数据库密钥进入 Git；后续提交前仍需检查敏感配置不要进入版本库。
  - 由于生产目录属主为 `www`，root 执行 Git 时需使用 `git -c safe.directory=/www/wwwroot/122.51.223.46 -C /www/wwwroot/122.51.223.46 ...`，不要修改全局 Git 配置。

[旧系统公共协议层收口结果]
- Date: 2026-05-09
- Context: Agent 继续第 1 阶段公共协议收口时完成
- Category: 代码模式
- Instructions:
  - 线上新增公共工具 `/www/wwwroot/122.51.223.46/api/common/helpers.php`，包含统一成功/错误响应封装、输入校验、日期/枚举校验、请求 ID 和轻量日志 `appLogEvent()`。
  - `/api/common/context.php` 已接入 `helpers.php`，未登录时通过统一错误响应返回 `401`，并记录 `auth.required_failed` 日志。
  - `/api/common/context-test.php` 已接入统一响应和轻量日志，真实账号 `18385534850` 验证通过：`LOGIN=0`、`CONTEXT_CODE=0`、`ROLE=coach`、`STORE=小十字`、`CAN_EDIT_OWN=1`。
  - 轻量接口日志写入 `/www/wwwroot/122.51.223.46/logs/api-YYYY-MM-DD.log`，日志会自动脱敏 `password/token/jwt/authorization/openid/secret/api_key` 等字段。
  - 本轮线上 Git 提交为 `535a804 chore: add common api protocol helpers`。

[旧系统前端统一请求层收口结果]
- Date: 2026-05-09
- Context: Agent 继续第 1 阶段公共协议收口时完成
- Category: 代码模式
- Instructions:
  - `/www/wwwroot/122.51.223.46/js/app-auth.js` 已增强 JWT payload 解析、token 过期判断、统一清理登录态和跳转 `/mobile/login.html?redirect=...`。
  - `/www/wwwroot/122.51.223.46/js/api-client.js` 已增强默认 15 秒超时、`AbortController` 取消、网络错误统一提示、`401` 统一跳登录、`redirectOnUnauthorized=false` 可关闭自动跳转。
  - 后续新网页前端禁止直接散写 `fetch` 调业务接口，应优先使用 `ApiClient.get()`、`ApiClient.post()` 并通过 `AppAuth.authHeaders()` 自动带 token。
  - 本轮线上 Git 提交为 `62b4e54 chore: harden shared frontend api client`。

[旧系统共享权限校验收口结果]
- Date: 2026-05-09
- Context: Agent 继续第 1 阶段公共协议收口时完成
- Category: 代码模式
- Instructions:
  - `/www/wwwroot/122.51.223.46/api/common/context.php` 已新增共享权限函数：`appCanViewAll()`、`appCanEditAll()`、`appCanViewStore()`、`appCanEditStore()`、`appCanEditOwn()`、`appCanOperateStaff()`。
  - 同文件已新增强制校验函数：`appRequireViewStore()`、`appRequireEditStore()`、`appRequireOperateStaff()`，失败时统一返回 `403` 并写轻量权限拒绝日志。
  - 真实教练账号 `18385534850` 验证结果符合预期：可看本店、可编辑本人、不能编辑整店、不能看全量、不能编辑全量。
  - 后续 `/api/workload/*` 必须优先使用这些权限函数校验 `store_id`、`staff_id`，禁止信任前端传入的门店或员工参数。
  - 本轮线上 Git 提交为 `8fbf972 chore: add shared permission checks`。

[旧系统收口节奏调整]
- Date: 2026-05-09
- Context: 用户指出全局旧脚本盘点容易变成无底洞，并确认按定向安全收口方式继续
- Instructions:
  - 不做全局旧资产盘点作为工作量系统前置，避免在历史脚本和旧页面调用关系里陷入无底洞。
  - 先做定向安全收口：扫描公开 PHP 脚本中无鉴权且具备写操作、重置密码、导入数据、修复数据、修改权限等能力的高风险项。
  - `.bak-*` 文件只做归档不删除，优先移出 Web 主路径或放入 `_archive/bak-YYYYMMDD/`，并保留清单。
  - 工作量系统 API 与旧页面清理并行推进，不等待旧资产全部清完。
  - 小程序不等 UI 全面重构，先同步 `utils/api.js`/`utils/auth.js` 的统一请求和 token 处理口径，确保后续直接接同一套 `/api/workload/*`。

[旧系统定向安全收口与备份归档结果]
- Date: 2026-05-09
- Context: Agent 按用户确认的新节奏执行 P0 定向安全收口和 `.bak-*` 归档
- Category: 环境配置
- Instructions:
  - 高风险公开 PHP 扫描脚本位于 `/www/wwwroot/122.51.223.46/scripts/scan_high_risk_public_php.php`，报告位于 `/www/mc-backups/20260510-002447/high-risk-public-php-after-guards-v3.json`。
  - 已对 `api/.env.local.php`、`api/admin/test.php`、`site_current/api/**`、`fix_layout.php`、`homepage_design.php`、`render_internal_pages.php`、`subpages.php` 加直接访问保护，不删除文件。
  - 直接访问保护报告位于 `/www/mc-backups/20260510-002447/direct-access-guards-v2.json`，本轮 Git 提交为 `7742f22 chore: guard high risk public maintenance scripts`。
  - `.bak-*` 候选清单位于 `/www/mc-backups/20260510-002447/bak-candidates.json`，共 102 个，已全部移动到 Web 根目录外 `/www/mc-archive/122.51.223.46/bak-20260510/`。
  - `.bak-*` 归档结果位于 `/www/mc-backups/20260510-002447/bak-archive-result.json`，归档目录内有 `archive-manifest.json`。
  - `.bak-*` 归档提交已改写为 `cddb044 chore: archive legacy backup files outside webroot`；`api/config.php` 与 `wp-config.php` 保持未跟踪，避免敏感配置进入 Git。
  - 归档后已验证 `/internal.html`、`/mobile/login.html`、`/周年庆数据看板-V5.html` 返回 200，公共上下文真实账号验证仍正常。

[工作量日报 API 最小链路结果]
- Date: 2026-05-09
- Context: Agent 在完成旧系统定向收口后开始实现常态化工作量系统 API
- Category: 代码模式
- Instructions:
  - 线上已新增 `/www/wwwroot/122.51.223.46/api/workload/_common.php`，负责创建工作量相关表、初始化销售/教练指标字典和日报模板。
  - 线上已新增 `/api/workload/template.php`、`/api/workload/my-report.php`、`/api/workload/save-report.php`，全部基于 `/api/common/context.php`、`/api/common/helpers.php` 和共享权限函数实现。
  - 当前 V1 已支持销售与教练日报模板；保存日报时校验日期、门店、岗位、必填指标、系统计算指标和本人/门店权限。
  - 真实教练账号 `18385534850` 已跑通：`TEMPLATE_CODE=0`、`TEMPLATE_ITEMS=7`、`SAVE_CODE=0`、`REPORT_ID=1`、`MY_REPORT_CODE=0`、`MY_REPORT_VALUES=3`。
  - 验证脚本位于 `/www/wwwroot/122.51.223.46/scripts/check_workload_flow.php`。
  - 本轮线上 Git 提交为 `163310f feat: add workload daily report apis`。

[小程序统一请求层收口结果]
- Date: 2026-05-09
- Context: Agent 按用户要求避免小程序与网页端再次分裂，在工作量系统 API 后同步小程序请求层
- Category: 代码模式
- Instructions:
  - 线上小程序真实目录为 `/www/wwwroot/122.51.223.46/mini-program/`，此前没有独立 `utils` 请求层，多数页面通过 `app.request()` 调接口，少数页面仍有直接 `wx.request`。
  - 已新增 `/mini-program/utils/auth.js`，统一管理 `token`、`jwt_token`、`userInfo`、`user_info` 的读取、写入和清理。
  - 已新增 `/mini-program/utils/api.js`，统一处理自动带 `Authorization`、15 秒超时、`401` 跳登录、统一 `{code,message,data}` 错误结构。
  - 已改造 `/mini-program/app.js`，保留既有 `app.request(options)` 调用方式，但内部委托到 `utils/api.js`，降低批量改页面风险。
  - 后续小程序新页面禁止直接散写 `wx.request` 调业务接口，应优先走 `app.request()` 或 `utils/api.js`。
  - 本轮线上 Git 提交为 `024a962 chore: align mini program api client`。

[移动端工作量日报入口结果]
- Date: 2026-05-09
- Context: Agent 在工作量 API 跑通后实现网页端移动入口
- Category: 代码模式
- Instructions:
  - 线上新增 `/www/wwwroot/122.51.223.46/mobile/workload.html`，接入 `/js/app-auth.js`、`/js/api-client.js` 和 `/api/workload/template.php`、`/api/workload/my-report.php`、`/api/workload/save-report.php`。
  - `/mobile/mine.html` 已新增“工作量日报”入口，跳转 `/mobile/workload.html`。
  - 当前页面支持自动读取当前用户上下文、按岗位加载模板、回填当天日报、保存草稿和提交日报。
  - 已验证 `/mobile/workload.html` 返回 200，真实账号 `18385534850` 的工作量 API 链路仍跑通。
  - 本轮线上 Git 提交为 `245bc79 feat: add mobile workload report entry`。

[小程序工作量日报入口结果]
- Date: 2026-05-09
- Context: Agent 在网页端工作量入口后继续实现小程序入口，确保接同一套 `/api/workload/*`
- Category: 代码模式
- Instructions:
  - 线上小程序已新增页面 `/www/wwwroot/122.51.223.46/mini-program/pages/workload/index.*`，并在 `app.json` 注册 `pages/workload/index`。
  - 小程序“我的”页 `/mini-program/pages/mine/mine.js`、`mine.wxml` 已新增“工作量日报”入口，跳转 `/pages/workload/index`。
  - 小程序工作量页通过既有 `app.request()` 调用统一请求层，接入 `/workload/template.php`、`/workload/my-report.php`、`/workload/save-report.php`，不新增小程序专用 API。
  - 当前实现支持读取员工上下文、加载岗位模板、回填日报、保存草稿和提交日报。
  - 本轮线上 Git 提交为 `91436ae feat: add mini program workload report page`。

[工作量汇总与后台页面结果]
- Date: 2026-05-09
- Context: Agent 在工作量日报前台和小程序入口后继续实现管理端查看能力
- Category: 代码模式
- Instructions:
  - 线上新增 `/www/wwwroot/122.51.223.46/api/workload/store-summary.php`，用于店长/员工查看有权限门店的日报明细、岗位汇总和指标汇总。
  - 线上新增 `/api/workload/hq-summary.php`，仅总部/管理员可查看指定日期范围内的总部汇总。
  - 线上新增 `/admin/workload.html`，作为管理端工作量中心页面，支持门店日报与总部汇总两种模式。
  - 验证脚本 `/scripts/check_workload_summary.php` 已确认：教练 `18385534850` 可看本店汇总但访问总部汇总返回 `403`，总部账号 `13668501068` 可查看总部汇总。
  - 已验证 `/admin/workload.html` 返回 200。
  - 本轮线上 Git 提交为 `6bb6463 feat: add workload summary admin view`。

[工作量中心入口串联结果]
- Date: 2026-05-09
- Context: Agent 在完成管理端工作量中心后继续串联后台首页和工作台入口
- Category: 代码模式
- Instructions:
  - `/admin/dashboard.html` 快捷入口已新增“工作量中心”，跳转 `/admin/workload.html`。
  - `/internal.html` 核心工作入口已新增“工作量日报”，跳转 `/mobile/workload.html`。
  - 线上验证 `/admin/dashboard.html` 与 `/internal.html` 均返回 200，且对应链接存在。
  - 复验工作量汇总权限：教练 `18385534850` 访问总部汇总仍返回 `403`，总部账号 `13668501068` 可查看总部汇总。
  - 本轮线上 Git 提交为 `082421c chore: link workload center from dashboards`。

[工作量提交缺口看板结果]
- Date: 2026-05-09
- Context: Agent 在工作量中心入口串联后继续增强管理看板
- Category: 代码模式
- Instructions:
  - `/api/workload/store-summary.php` 已新增应交人数、缺交人数、草稿人数、提交率、缺交名单和草稿名单字段。
  - 应交人员当前按 `staffs.status=1` 且角色属于销售/教练相关口径统计，包括 `sales`、`coach`、`consultant`、`实习销售`、`实习教练`、`销售`、`教练`。
  - `/admin/workload.html` 门店模式已展示提交率卡片、未提交名单和草稿未提交名单。
  - 线上验证 `/admin/workload.html` 返回 200，`/api/workload/store-summary.php` PHP 语法通过。
  - 真实账号 `18385534850` 验证小十字店当前 `expected_count=8`、`missing_count=7`、`submission_rate=12.5`，访问总部汇总仍返回 `403`。
  - 总部账号 `13668501068` 验证总部汇总仍返回 `code=0`。
  - 本轮线上 Git 提交为 `58a22fd feat: show workload submission gaps`。

[总部工作量提交率排行结果]
- Date: 2026-05-09
- Context: Agent 在门店缺交名单后继续增强总部工作量视角
- Category: 代码模式
- Instructions:
  - `/api/workload/hq-summary.php` 已新增 `store_submission_rows`，按门店返回应交、已交、草稿、缺交和提交率。
  - 总部应交口径按日期范围天数乘以各门店在职销售/教练相关人员数计算。
  - `/admin/workload.html` 总部模式已展示“门店提交率排行”，按提交率低到高排序，提交率相同时缺交多的优先。
  - 线上验证 `/admin/workload.html` 返回 200，`/api/workload/hq-summary.php` PHP 语法通过。
  - 真实验证：教练 `18385534850` 访问总部汇总仍返回 `403`，总部账号 `13668501068` 返回 `HQ_STORE_ROWS=5`。
  - 本轮线上 Git 提交为 `d2336f0 feat: add hq workload submission ranking`。

[工作量总部下钻门店结果]
- Date: 2026-05-09
- Context: Agent 在总部工作量提交率排行后继续增强管理页交互
- Category: 代码模式
- Instructions:
  - `/admin/workload.html` 总部模式的门店提交率排行行已支持点击下钻。
  - 点击门店行会自动填充 `store_id`，切换到门店日报模式，并按当前结束日期加载该店缺交名单。
  - 线上验证 `/admin/workload.html` 返回 200，页面包含 `function drillStore` 和下钻提示文案。
  - 复验工作量权限链路：教练 `18385534850` 总部汇总仍为 `403`，总部账号 `13668501068` 汇总仍为 `code=0`。
  - 本轮线上 Git 提交为 `1168db3 feat: drill into workload store gaps`。

[工作量中心 CSV 导出结果]
- Date: 2026-05-09
- Context: Agent 在总部排行下钻后继续增强工作量中心日常跟进能力
- Category: 代码模式
- Instructions:
  - `/admin/workload.html` 已新增“导出 CSV”按钮。
  - 总部模式导出当前日期范围的门店提交率排行，字段包括门店、应交、已交、草稿、缺交和提交率。
  - 门店模式导出当前门店日期的未提交名单、草稿名单和日报明细。
  - 导出实现为前端 Blob 生成 CSV，并加 UTF-8 BOM，便于 Excel 打开中文。
  - 线上验证 `/admin/workload.html` 返回 200，页面包含 `function exportCsv`、`function downloadCsv` 和“导出 CSV”按钮。
  - 复验工作量权限链路正常：教练访问总部汇总仍为 `403`，总部账号汇总仍为 `code=0`。
  - 本轮线上 Git 提交为 `9ee8917 feat: export workload center csv`。

[工作量提醒文案复制结果]
- Date: 2026-05-09
- Context: Agent 在工作量中心 CSV 导出后继续增强日常运营跟进能力
- Category: 代码模式
- Instructions:
  - `/admin/workload.html` 已新增“复制提醒”按钮。
  - 门店模式会基于当前未提交名单和草稿名单生成微信群提醒文案。
  - 总部模式会基于门店提交率排行提取存在缺交的门店，生成总部跟进提醒文案。
  - 复制优先使用 `navigator.clipboard.writeText`，失败时回退到 `textarea + execCommand('copy')`。
  - 线上验证 `/admin/workload.html` 返回 200，页面包含 `function buildReminder`、`function copyReminder`、`function fallbackCopy` 和“复制提醒”按钮。
  - 复验工作量权限链路正常：教练访问总部汇总仍为 `403`，总部账号 `13668501068` 重置密码后汇总返回 `code=0`。
  - 本轮线上 Git 提交为 `d3b061d feat: copy workload reminder text`。

[系统后台登录审计修复结果]
- Date: 2026-05-09
- Context: 用户反馈 `/admin/system-dashboard.html` 系统后台进入后显示服务器错误
- Category: 代码模式
- Instructions:
  - 根因一：`/api/admin/security/login-audit.php` 调用了不存在的 `isSuperAdminUser()`，已改用现有 `adminCanAccessHeadquarter($user, $staff)` 权限口径。
  - 根因二：`ensureLoginAuditTable()` 函数缺失，已在 `/api/admin/common.php` 新增幂等建表函数，创建 `login_audit_logs` 表和必要索引。
  - 新增验证脚本 `/scripts/check_admin_login_audit.php`，用总部账号登录后请求 `/api/admin/security/login-audit.php`，只输出状态码、消息和列表数量，不输出 token。
  - 线上验证 `/admin/system-dashboard.html` 返回 200，登录审计 API 返回 `LOGIN_AUDIT_CODE=0`，`login_audit_logs` 表已存在。
  - 回归验证工作量权限链路正常。
  - 本轮线上 Git 提交为 `42bd883 fix: restore system dashboard login audit`。

[工作量系统上线前审计口径]
- Date: 2026-05-09
- Context: 用户要求对已开发部署的工作量系统做上线前系统性缺陷审查
- Instructions:
  - 本轮目标不是重新开发，而是按审计标准系统性发现并修复已知和潜在缺陷。
  - 审查覆盖 `/api/workload/*`、网页端录入与看板、小程序端、新公共层实际调用、新旧代码边界衔接。
  - 审查原则为只修不拆、数据不丢、逐项验证；涉及数据修改必须先备份再操作。
  - P0 必须全部通过才能开放真实用户，P1 一周内修复，P2 按需处理。
  - 每个发现的问题按 WL-xxx 格式记录，P0/P1 修复后必须真实账号验证并提交 Git。
  - 收尾顺序固定为：H5 页面端到端测试、小程序源码与可执行性测试、XSS 与非法输入专项验证、登录审计写入补齐、空角色/无模板体验优化、输出上线前审计报告。

[工作量系统二期规划与交接要求]
- Date: 2026-05-09
- Context: 用户要求基于现有工作量系统继续规划“全量凭证上传 + 总部全量审核”的升级方案，并交接给下一个 Agent
- Instructions:
  - 必须按最严格、最完善的标准输出完整升级方案，包含业务口径、数据结构、API、前端改造、后台审核、审计要求、上线步骤、回滚与验证。
  - 方案必须明确：新功能要融合到现有工作量系统，不得再做成两套系统，不得让小程序出现两条并行业务线。
  - 需要把之前工作量系统的总体思路、审计标准、权限口径、公共层约束、现有已修复问题一起纳入新方案，不能只写新增审核功能。
  - 文档必须可直接交给下一个 Agent 接手实施，需写清每一步工作顺序、规范、约束条件、验证要求、P0/P1 风险点和提交要求。

[文档阅读器 401 修复结果]
- Date: 2026-05-09
- Context: 用户反馈学习中心文档进入后显示“文档读取失败：HTTP 401”
- Category: 代码模式
- Instructions:
  - 根因是 `doc-viewer.html` 请求 `/doc-content.php?doc=...` 时只带 `credentials: same-origin`，没有带站内 JWT `Authorization` 头；而 `doc-content.php` 要求登录。
  - 已在 `doc-viewer.html` 的 fetch 中使用 `window.authHeaders()` 补齐鉴权头，并将 401 错误提示改为“请先登录后阅读文档”。
  - 新增验证脚本 `/scripts/check_doc_viewer.php`。
  - 已验证未登录访问 `doc-content.php` 仍返回 401，登录后 `v4-00`、`v4-00a`、`v4-02`、`k-09f` 均返回 200 且有正文长度。
  - 本轮线上 Git 提交为 `12648c9 fix: send auth headers for document viewer`。

[文档登录改密回跳修复结果]
- Date: 2026-05-09
- Context: 用户反馈文档页提示登录，登录后被要求改密，改密成功再回学习中心仍显示需要登录才能阅读
- Category: 代码模式
- Instructions:
  - 根因是 `/mobile/login.html` 首次改密跳转到 `/mobile/mine.html?force_change_password=1` 时丢失原始 `redirect`，且 `/mobile/mine.html` 改密成功后固定跳 `/internal.html`，没有刷新改密后的 JWT。
  - 已让登录页把原始目标作为 `redirect` 传给改密页，并记录 `last_login_username`。
  - 已让改密成功后用新密码调用 `/api/auth-jwt.php` 刷新 `jwt_token` 和 `user_info`，再跳回原始目标文档页。
  - 新增验证脚本 `/scripts/check_doc_login_redirect.php`。
  - 已验证登录页、我的页均返回 200，页面包含 redirect 保留、用户名记录、改密后刷新登录态、改密后回跳逻辑；文档登录后读取仍返回 200。
  - 本轮线上 Git 提交为 `75502de fix: preserve document redirect after password change`。

[文档阅读鉴权竞态修复结果]
- Date: 2026-05-09
- Context: 用户反馈文档页顶部显示已登录，但正文仍提示“请先登录后阅读文档”
- Category: 代码模式
- Instructions:
  - 根因是 `doc-viewer.html` 在 `internal-auth.js` 初始化完成前就执行了正文请求，第一次请求 `/doc-content.php` 时 `window.authHeaders` 尚未挂载，导致匿名请求返回 401。
  - 已在 `doc-viewer.html` 设置 `window.__SKIP_AUTO_INTERNAL_AUTH__ = true`，并改为在 `DOMContentLoaded` 后通过 `window.requirePageAuth({ onAuthed })` 完成鉴权，再调用 `loadDocument()`。
  - 已验证页面包含 `DOC_SKIP_AUTO_AUTH=YES`、`DOC_REQUIRE_PAGE_AUTH=YES`、`DOC_DOM_READY=YES`，登录后正文接口返回 200。
  - 本轮线上 Git 提交为 `579c687 fix: wait for auth before loading documents`。

[排障方式要求：先做全面链路检查]
- Date: 2026-05-27
- Context: 用户要求不要继续按“遇到一个问题修一个问题”的方式推进 H5 工作量问题排查
- Instructions:
  - 遇到线上功能异常时，优先做整条链路的全面检查，而不是只针对当前单点报错做局部修补。
  - 对 H5 页面需一次性覆盖入口、缓存版本、登录态、鉴权、接口返回、旧页面兼容、日志与回归验证。
  - 汇报时优先给出完整问题面和系统性修复结论，减少来回试错。
