# Bug 审计合并报告（55 + 3）

> 本报告合并了前一轮审计的 55 个核心 bug 与本轮新发现的 3 个生产环境 bug，供修复 Agent 复核。

---

## 一、前一轮 55 个核心 Bug 清单（按严重程度排序）

### 高危（9 个）

| # | 文件 | 问题描述 | 严重程度 |
|---|------|---------|---------|
| 1 | `mini-program/app.js:6` | `apiBase` 写死为 `http://122.51.223.46/api`，与正式域名 `supercalf.com` 体系割裂 | P1 |
| 2 | `api/config.php:37-52` | `ALLOWED_ORIGINS` 和 `API_BASE_URL` 默认包含 `http://122.51.223.46`，环境变量缺失时回退到旧入口 | P1 |
| 3 | `api/common/context.php:152-159` | 权限体系存在硬编码 HQ 白名单，权限不可配置、不可审计 | P1 |
| 4 | `staff-import-20260506.json` | 生产目录保留含默认密码 `"password": "123456"` 的数据文件 | P1 |
| 5 | `survey-manage.html:324` | 前端存在默认密码/默认验证码式逻辑 `code || '123456'` | P1 |
| 6 | `wp-content/themes/astra-child/page-home.php` | 主题与页面中仍存在旧 IP 直链 `http://122.51.223.46/...` | P1 |
| 7 | `api/workload/save-report.php:96-111` | 提交日报时创建审核任务，但明显跳过强制凭证检查，审核链路闭环未完成 | P1 |
| 8 | `api/admin/test.php` 等 | 生产目录混杂大量测试、调试、备份脚本 | P1 |
| 9 | `site_current/`、`mini-program-backup-20260504_fix/` 等 | 现网存在多套历史目录和重复代码源 | P1 |

### 中危（32 个，部分列举）

| # | 文件 | 问题描述 | 严重程度 |
|---|------|---------|---------|
| 10 | `api/admin/common.php` | 后台权限体系与业务权限体系分裂，同一账号在不同接口权限可能不一致 | P2 |
| 11 | `api/admin/common.php:12-19` | 后台权限文件中再次重复硬编码 HQ 白名单 | P2 |
| 12 | `api/admin/common.php:47-61` | `adminRequireAuth()` 返回对象依赖 JWT 和 session 混合来源，当前 actor 来源不统一 | P2 |
| 13 | `api/admin/common.php:106-142` | 请求路径中自动建表 `CREATE TABLE IF NOT EXISTS`，生产请求承担 schema 初始化职责 | P2 |
| 14 | `api/admin/common.php:167-199` | 日志脱敏字段覆盖不完整，仍可能遗漏变体敏感字段 | P2 |
| 15 | `api/common/helpers.php:11-18` | 新旧接口错误响应体系未真正统一，新老接口并行 | P2 |
| 16 | `api/common/helpers.php:55-71` | `appLogEvent()` 直接写文件日志，高并发时文件锁竞争 | P2 |
| 17 | `api/auth/me.php:25-45` | 首次登录/默认密码判断逻辑耦合在用户信息接口中 | P2 |
| 18 | `api/auth/me.php` | 旧接口未接入新的上下文权限模型，与 `api/common/context-info.php` 并行存在 | P2 |
| 19 | `api/admin/staff-list.php:5` | 员工列表接口权限颗粒度不够，直接 `adminCanAccessHeadquarter` | P2 |
| 20 | `api/admin/staff-list.php:23-30` | 每行 staff 都做 `exam_count`、`exam_avg`、`pass_count` 子查询，数据量大时性能下降 | P2 |
| 21 | `api/admin/staff-toggle-status.php` | 员工状态切换缺少事务保护，先更新 `staffs` 再更新 `wp_users`，无事务 | P2 |
| 22 | `api/admin/staff/update.php:6` | `isSuperAdminUser($user)` 调用风格不统一，未传 `$staff` | P2 |
| 23 | `api/admin/staff/update.php:19-31` | 更新字段缺少更细的数据格式校验（phone、role、store_id） | P2 |
| 24 | `api/admin/staff/reset-password.php:14-20` | 后台允许直接传明文新密码，口令管理混乱 | P2 |
| 25 | `api/admin/staff/unbind-wechat.php` | 解绑微信是高风险身份操作，但无二次确认机制 | P2 |
| 26 | `api/admin/staff/unlock-account.php:16-18` | 解锁能力缺失时仍返回 success，属于"假成功" | P2 |
| 27 | `api/admin/login-audit.php` / `api/admin/security/login-audit.php` | 登录审计接口存在两份并行实现，维护分叉 | P2 |
| 28 | `api/admin/ai-analyze.php` | 单接口承担上传、解析、OCR、AI 分类、导入确认多个职责 | P2 |
| 29 | `api/admin/ai-analyze.php` | 解析 PDF/DOC 时使用外部命令执行能力（`pdftotext`、`antiword`、`catdoc`） | P2 |
| 30 | `api/admin/ai-analyze.php:7` | 权限只到 HQ 级，不够细颗粒，任何可访问 HQ 后台的人都能做导入分类 | P2 |
| 31 | `api/admin/ai-drill.php` | admin 接口混入业务训练主流，后台边界和业务边界混乱 | P2 |
| 32 | `api/admin/ai-drill.php` | `startDrill()` 直接信任输入中的 `user_id`，存在 impersonation 风险 | P2 |
| 33 | `api/admin/dashboard.php` | 后台总览接口聚合统计过于原始，缺少缓存和查询治理 | P2 |
| 34 | `api/admin/stats.php` | 后台统计接口体量过大，单接口同时统计多个领域聚合 | P2 |
| 35 | `api/admin/template.php` | 模板下载接口直接输出包含"初始密码"字段的 staff 导入样例 | P2 |
| 36 | `api/admin/upload.php` | 后台上传接口没有复用 `api/admin/common.php` 的统一鉴权体系 | P2 |
| 37 | `api/admin/upload.php` | 上传权限模型过粗，`isJwtManager()` 覆盖面不清晰 | P2 |
| 38 | `api/admin/upload.php` | 上传接口内嵌大量导入逻辑，职责过重 | P2 |
| 39 | `api/admin/upload.php` | 上传文件存储路径在 Web 根目录下，介质隔离不足 | P2 |
| 40 | `mobile/learning.html` | 学习中心页面聚合过多业务域，单页耦合过重 | P2 |
| 41 | `internal-auth.js` | 公共鉴权脚本自动重写顶部导航，页面行为对公共脚本强耦合 | P2 |
| 42 | `mobile/knowledge-detail.html` | 详情页把学习、演练、话术、关联知识耦合在一页 | P2 |
| 43 | `real_sync/` 目录结构 | 不是干净部署镜像，而是研发/热修/测试/审查混合工作副本 | P2 |
| 44 | `scripts/verify_staff_login.php` | 测试脚本中存在真实登录验证逻辑，并直接使用默认密码 `123456` | P2 |
| 45 | `scripts/import_staff_cli.php` | 员工导入 CLI 脚本继续固化"初始密码必填"工作流 | P2 |

### 低危（14 个，部分列举）

| # | 文件 | 问题描述 | 严重程度 |
|---|------|---------|---------|
| 46 | `api/admin/login-audit.php` | 两份分页实现不完全一致（`LIMIT $offset, $pageSize` vs `LIMIT ?, ?`） | P3 |
| 47 | `api/admin/template.php` | 模板接口使用 JSON 附件直出，但缺少模板版本号与字段说明 | P3 |
| 48 | `api/admin/ai-analyze.php` | `knowledge_read` 字段为硬编码占位值，接口返回伪业务数据 | P3 |
| 49 | `api/admin/stats.php` | "总尝试次数"统计 SQL 语句混乱，命名与实际含义可能不一致 | P3 |
| 50 | `mobile/learning.html` | 旧版学习中心存在分类 Tab 重复渲染缺陷 | P3 |
| 51 | `mobile/learning.html` | 页面错误态过于粗粝，无法区分未登录、无权限、接口异常、空数据 | P3 |
| 52 | `mobile/knowledge-detail.html` | "播放示范音频"按钮仅触发 Toast，占位功能未闭环 | P3 |
| 53 | `mobile/policy-detail.html` | 分享 fallback 直接调用 `navigator.clipboard.writeText()`，未处理权限失败 | P3 |
| 54 | `mini-program/app.json` | TabBar 声明依赖的页面在当前工作区副本中并不完整 | P3 |
| 55 | `mini-program/pages/learning/list.js` | 课程分页是否还有更多依赖"本次数量是否等于 page_size"判断，分页策略脆弱 | P3 |

---

## 二、本轮新发现的 3 个生产环境 Bug

### Bug 56：微信小程序登录配置缺失（新增，与生产问题直接相关）

- **文件**：`api/auth-jwt.php` -> `getWeChatOpenId()` 函数
- **现象**：
  - `getWeChatOpenId($code)` 函数依赖 `WECHAT_APPID` 和 `WECHAT_APP_SECRET`
  - 服务器上未找到这两个配置的定义（`grep` 不到）
  - 当配置缺失时，函数返回 `null`，微信登录返回 `401 微信授权失败，无法获取用户信息`
- **根因**：
  ```php
  $appid = defined('WECHAT_APPID') ? WECHAT_APPID : getenv('WECHAT_APP_SECRET');
  $secret = defined('WECHAT_APP_SECRET') ? WECHAT_APP_SECRET : getenv('WECHAT_APP_SECRET');
  if (empty($appid) || empty($secret)) {
      error_log('WeChat AppID or Secret not configured');
      return null;
  }
  ```
- **影响**：直接导致"很多人无法登录"（微信一键登录完全不可用）
- **严重程度**：**高危（P1）**
- **修复建议**：
  1. 在 `api/config.php` 或环境变量中配置真实的 `WECHAT_APPID` 和 `WECHAT_APP_SECRET`
  2. 检查微信小程序后台，确认 AppID 和 AppSecret 有效
  3. 如果暂不使用微信登录，应在小程序端禁用该入口，避免用户困惑

---

### Bug 57：`project.config.json` 中 `appid` 为占位符（新增，与生产问题直接相关）

- **文件**：`mini-program/project.config.json`
- **现象**：
  ```json
  {
    "appid": "wxxxxxxx"
  }
  ```
  - `appid` 为占位符 `wxxxxxxx`，不是有效的小程序 ID
- **根因**：小程序配置未更新为真实的微信小程序 ID
- **影响**：
  - 微信小程序开发者工具无法正确识别项目
  - 影响小程序的构建、预览和发布流程
  - 与 Bug 56 协同导致微信登录完全不可用
- **严重程度**：**高危（P1）**
- **修复建议**：
  1. 将 `appid` 更新为真实的微信小程序 ID
  2. 同步更新服务器端的 `WECHAT_APPID` 配置，确保前后端一致

---

### Bug 58：工作量系统图片上传可能未成功（新增，与生产问题直接相关）

- **文件**：`api/workload/evidence-upload.php`、`uploads/workload/evidence/`
- **现象**：
  - 服务器上已有上传文件均为 67 字节的测试图片（PNG 文件头）
  - 实际用户上传的文件大小为 67 字节，明显不是有效图片
  - 上传接口代码逻辑本身正确，但前端可能存在 Base64 编码问题
- **根因分析**：
  - 上传接口支持两种方式：`$_FILES['image_file']` 和 base64 `image_data`
  - 67 字节的文件说明前端可能只上传了文件头或截断数据
  - 或前端在 Base64 编码时发生截断
- **影响**：
  - 用户无法成功上传工作量凭证图片
  - 67 字节的"图片"无法被正常查看
- **严重程度**：**中危（P2）**
- **修复建议**：
  1. 检查小程序端 `pages/workload/index.js` 的上传逻辑
  2. 确认 `wx.chooseImage` 或 `wx.chooseMedia` 返回的图片路径正确
  3. 确认 Base64 编码完整，无截断
  4. 在上传前校验图片大小，小于 1KB 的文件应拒绝上传并提示用户

---

## 三、与生产问题相关的 Bug 汇总

### "很多人无法登录" 的根因

| Bug # | 问题 | 状态 |
|-------|------|------|
| 56 | `WECHAT_APPID` / `WECHAT_APP_SECRET` 配置缺失 | **未修复** |
| 57 | `project.config.json` 中 `appid` 为占位符 | **未修复** |
| 1 | 小程序 `apiBase` 写死为旧 IP（`http://122.51.223.46/api`） | **未修复** |
| 2 | API 配置默认值仍指向旧 IP 和 HTTP | **未修复** |
| 10-12 | 认证体系双轨制（JWT/session 混合） | **未修复** |
| 17 | 首次登录/默认密码判断逻辑耦合在 `me.php` | **未修复** |
| 44 | 测试脚本中存在真实登录验证逻辑 | **未修复** |

### "无法上传图片到工作量系统" 的根因

| Bug # | 问题 | 状态 |
|-------|------|------|
| 58 | 上传文件仅 67 字节，前端可能截断 | **未修复** |
| 7 | 工作量审核链路未闭环（跳过强制凭证检查） | **未修复** |

---

## 四、修复优先级建议

### 立即修复（影响生产）

1. **Bug 56**：配置 `WECHAT_APPID` 和 `WECHAT_APP_SECRET`
2. **Bug 57**：更新 `mini-program/project.config.json` 中的 `appid`
3. **Bug 58**：检查小程序端图片上传逻辑，修复截断问题

### 短期修复（1-2 周内）

4. **Bug 1**：将小程序 `apiBase` 切换为正式 HTTPS 域名
5. **Bug 2**：清理 `api/config.php` 中的旧 IP 默认值
6. **Bug 3**：移除硬编码 HQ 白名单，改为可配置权限
7. **Bug 4**：删除或迁移含默认密码的数据文件
8. **Bug 5**：移除 `survey-manage.html` 中的默认密码逻辑

### 中期修复（1 个月内）

9. **Bug 10-12**：统一后台权限体系
10. **Bug 15-16**：统一错误响应体系，优化日志写入
11. **Bug 19-25**：完善员工管理接口的权限和事务保护
12. **Bug 33-39**：优化后台统计和上传接口

---

## 五、复核清单（供修复 Agent 使用）

- [ ] 服务器上已配置 `WECHAT_APPID` 和 `WECHAT_APP_SECRET`
- [ ] `mini-program/project.config.json` 中的 `appid` 已更新为真实值
- [ ] 小程序端图片上传后文件大小正常（> 1KB）
- [ ] 小程序 `apiBase` 已指向正式 HTTPS 域名
- [ ] `api/config.php` 中已无旧 IP 默认值
- [ ] 硬编码 HQ 白名单已移除或改为配置化
- [ ] 含默认密码的数据文件已删除或迁移
- [ ] 测试脚本（`api/admin/test.php` 等）已移除或迁移到非生产目录

---

> 报告生成时间：2026-06-05
> 审计范围：`/www/wwwroot/122.51.223.46/` 服务器现网 + GitHub 仓库 `real_sync/`
