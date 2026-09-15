# 三方一致性同步与断链排查报告（2026-09-16）

## 一、任务目标

把以下三方整理到「内容一致、互为最新」的状态，作为后续正式修 bug / 升级的前置基线：

1. 生产服务器 Web 根：`/www/wwwroot/122.51.223.46/`
2. GitHub 仓库：`https://github.com/nn190yxn/zhuiguangxiaoniu.git`（分支 `main`）
3. 本地镜像：`/workspace/real_sync/`

## 二、方法与口径

- 以服务器真实文件系统为准做全量枚举，下载后在本地逐文件做 `md5` 字节级比对。
- 排除目录：`.git`、`wp-admin`、`wp-includes`、`wp-content`、`uploads`、`logs`、`data`、`backups`、各类 `.agent-backups` / `.deploy-backups` / `.private` / `_archive`、`node_modules`、`.monkeycode`。
- 服务器上的 WP 核心、密钥、备份文件不纳入仓库，避免敏感信息进 Git。

## 三、同步结果

### 3.1 一致性结论

- 服务器文件：2099 个；仓库文件：2063 个。
- 共同文件：2058 个，**md5 全部一致，差异为 0**。
- 服务器独有：41 个（WP 核心入口、密钥、备份、临时文件，均为有意排除项）。
- 仓库独有：5 个（`project.config.json`、`project.private.config.json` 两个 IDE 配置；`.preview-check/` 下 3 张本地预览图）。

### 3.2 服务器独有清单（有意排除，不进入仓库）

- WP 核心与入口：`wp-load.php`、`wp-login.php`、`wp-settings.php`、`wp-blog-header.php`、`wp-activate.php`、`wp-signup.php`、`wp-mail.php`、`wp-comments-post.php`、`wp-links-opml.php`、`wp-config-sample.php`、`license.txt`、`readme.html`
- 密钥与敏感：`wp-config.php`、`api/.env.local.php`、`api/.env.local.php.wecom-pending-20260624`、`staff-import-20260506.json`、`test-auth.html`、`api/admin/test.php`
- 审计快照：`ai-analyzer.remote.html`、`ai-drill.remote.html`、`setup.sh`、`.htaccess`、`.user.ini`
- 运维备份：`api/config.php.bak-*`、`api/config.php.orig`、`api/ai-runtime.php.bak-*`、`internal.html.bak-*`、`internal.html.workbench`、`mobile/knowledge.html.bak-*`、`mobile/login.html.audit-*`、`mini-program/pages/workload/index.js.bak-*`、`page-zhidu-biaozhun.php.bak-*`、`制度标准/index.html.bak-*`
- 素材与业务文档：`玻璃贴-14.png`（站点 logo，被 44 个页面引用）、`追光小牛_教练薪酬与星级体系方案.xlsx`、`追光小牛_教练薪酬测算表.xlsx`
- 历史录音：`api/wp-content/uploads/drill-recordings/2026/04/29/*.wav`（3 个）

### 3.3 仓库独有清单

- `project.config.json`、`project.private.config.json`：微信开发者工具工程配置（`miniprogramRoot: mini-program/`），不属于线上站点。
- `.preview-check/banner-{compact,d,m}.png`：本地 banner 预览图。

### 3.4 本轮部署到服务器的内容

1. **内容同步 41 个文件**：从 GitHub 较新版回灌 8 个（`knowledge.html`、`learning.html`、`sitemap.xml`、`robots.txt`、`courses/{fitness,motor-skills,sensory-integration,summer-camp}.html`），补齐 18 篇 news 页、12 个 courses 页，以及 3 个缺口文件（`api/exam/list.php`、`api/exam/history.php`、`mini-program/utils/privacy.js`）。部署前备份在 `/www/mc-backups/20260916-content-sync`。
2. **开发工程文件 125 个**：9 个 `database/`（含 3 个已被 `migration_catalog.php` / `migration_manifest.php` 引用的迁移，sha256 与台账完全吻合）、115 个 `scripts/`、1 个 `docs/`。纯新增，无覆盖。

### 3.5 版本方向判定（推翻旧的「服务器永远最新」假设）

- 服务器较新：`api/*`（使用服务器版才有的 `getEffectiveStaffRole()` / `isJwtManager()`）、`mini-program/*`（服务器 196 文件 vs 仓库 161）、`cloudfunctions/api-proxy/index.js`。
- GitHub 较新：`courses/*.html`（含 FAQPage schema、course-table、返回链接）、`sitemap.xml`（64 URL vs 38）、`robots.txt`（多 5 条 Disallow）、`knowledge.html` / `learning.html`（指向 `/knowledge/`、`/learning/`）。

## 四、断链排查

### 4.1 扫描方法

在服务器上枚举 HTML/CSS，提取 `href` / `src` / `action`，按「相对当前文件目录」解析（首轮按根目录解析属误报，已修正），再检查真实文件是否存在。

### 4.2 已修复

| 断链 | 影响 | 修复 |
| --- | --- | --- |
| `/glass-sticker-14.png`（404） | 9 个公共页：`courses/{fitness,motor-skills,sensory-integration,summer-camp}.html`、`stores/{yundong-center,kaixin-mogu,tonglewan,jiufu-cheng,xiaohe-wanke}.html`，每页 2 处（品牌 img + JSON-LD image） | 统一改为站点既有且线上 200 的 `/assets/pwa/icon.svg`（与同族 54 个 news 页一致） |
| `/玻璃贴 -14.png`（文件名多一个空格） | `training/02-role/index.html` | 修正为 `/玻璃贴-14.png` |

修复前备份在 `/www/mc-backups/20260916-linkfix`；部署后 10 个文件 md5 与本地一致，线上实测 200 且已无 `glass-sticker` 残留。

### 4.3 剩余真实断链（待产品决策，未修改）

用户决定「5 个都先撤掉」，已按下表移除入口（备份 `/www/mc-backups/20260916-remove-entries`）。

| 断链 | 原引用位置 | 处理方式 |
| --- | --- | --- |
| `/mobile/history.html` | `mobile-mine.html`、`mobile/mine.html` 的「阅读历史」菜单项与 `showReadHistory()` | 移除菜单项与对应 JS 函数 |
| `/mobile/subscription.html` | 同上「订阅设置」菜单项与 `showSubscription()` | 移除菜单项与对应 JS 函数 |
| `/mobile/pass-map.html` | `mobile/learning.html` 的通关地图快捷入口、「通关进度」整块、`loadPassSummary()` / `renderPassSummary()` / `goToPassMap()` | 移除入口、区块、JS 与相关 CSS |
| `/lessons/` | `coach.html` 的「课程教案库」卡片 | 移除该卡片（`lessons/` 下 56 个课时页文件保留，仅去掉目录入口） |
| `/news/yundong-honor.html` | `index.html` 的新闻卡片 | 移除该新闻卡片（文章本就从未存在） |

撤除后重扫：真实断链从 5 个降为 1 个，仅剩 `/mobile/pass-map.html`，其唯一引用来自根 `pass-map.html` 自身。该页已无任何页面指向，属孤立页（历史书签或搜索引擎直接访问会 404），删除需用户确认。

判别为误报、无需处理的项：`data-action="save"`、`value="skip"` 等属性值被正则误捕；`{{URL}}`、`%1$s`、`$2` 等模板占位符；`wp-content/plugins` 下 Elementor / Tutor 插件内部链接。

## 五、其他发现

- **仓库不跟踪图片素材**：仓库内被跟踪的图片仅 `real_sync/assets/pwa/icon.svg`。站点 logo `玻璃贴-14.png`（140KB）只存在于服务器，仓库内 44 个页面引用它。因此「仓库缺该文件」属既有约定，不代表线上异常；但仅用仓库重建站点会缺 logo。
- **审计台账乱码**：`服务器现网全面审计与API专项问题清单_2026-05-15.md` 全文为 UTF-8 双重编码乱码，且在 `bbbf367` 提交中已是乱码（非本次引入）。已含替换字符，无法完整还原，建议后续重新生成。
- **迁移文件曾被遗漏**：服务器 `migration_catalog.php` / `migration_manifest.php` 引用了 `202608260001/002/003` 三个迁移，但文件缺失；本轮已补齐，sha256 与台账记录完全吻合。

## 六、回滚方式

- 内容同步：`cp -p /www/mc-backups/20260916-content-sync/<path> /www/wwwroot/122.51.223.46/<path>`
- 断链修复：`cp -p /www/mc-backups/20260916-linkfix/<path> /www/wwwroot/122.51.223.46/<path>`
- 开发工程文件为纯新增，如需回滚，删除对应新增文件即可（本轮未删除任何服务器文件）。

## 七、相关提交

- `f7376a8` sync: align repository with production server baseline
- `866e0a5` sync: restore GitHub-newer public content (knowledge, learning, sitemap, robots, courses)
- `42a77d6` fix(links): point public page logos to existing /assets/pwa/icon.svg and fix logo path typo
