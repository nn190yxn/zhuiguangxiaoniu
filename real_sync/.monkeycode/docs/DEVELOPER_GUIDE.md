# 开发者指南

## 项目目的

本项目同时维护公开品牌官网和追光小牛内部数字化运营平台。开发时应先确认改动属于公开内容、员工端、管理端、PHP API、小程序、云适配或数据库 migration 中的哪一层。

## 前置条件

- Python 3：静态官网本地预览。
- PHP CLI 与 PDO MySQL：PHP API 和数据库脚本。
- Node.js：`.test.mjs` 自动化测试与云函数本地验证。
- 微信开发者工具：小程序真机和开发者工具验证。
- MySQL：需要执行 API 集成或 migration 时使用。

## 静态官网预览

在仓库根目录运行：

```bash
# 启动静态官网预览
python3 -m http.server 8001 --directory /workspace/real_sync
```

至少检查：首页 `/`、资讯索引 `/news/`、本次文章页和引用的 CSS、图片资源均返回 HTTP 200。

## 公开文章开发

1. 在 `news/` 创建稳定 slug 的静态 HTML。
2. 配置唯一 title、description、canonical、Open Graph 和发布日期。
3. 配置 Article、FAQPage、BreadcrumbList JSON-LD。
4. 确保 FAQPage 与可见 FAQ 完全一致。
5. 在 `news/index.html` 增加专题或归档入口。
6. 在需要时更新 `index.html` 代表入口。
7. 在 `sitemap.xml` 增加规范 URL 与 lastmod。
8. 验证内部链接、JSON-LD、XML、事实口径和 HTTP 200。

公共文章样式位于 `assets/public-insights.css`。新文章应优先复用现有组件类，保持移动端单列布局。

## API 配置

`api/config.php` 从环境变量读取数据库、JWT 和 API 配置；PHP-FPM 环境也支持本地配置文件。仓库只提供 `api/.env.local.php.example` 示例。

| 变量 | 必需 | 用途 |
|------|------|------|
| `DB_HOST` | 是 | MySQL 主机 |
| `DB_NAME` | 是 | 数据库名 |
| `DB_USER` | 是 | 数据库用户 |
| `DB_PASSWORD` | 是 | 数据库密码 |
| `DB_CHARSET` | 否 | 默认 `utf8mb4` |
| `JWT_SECRET` | 是 | JWT 签名 |
| `ALLOWED_ORIGINS` | 否 | CORS 允许来源 |
| `API_BASE_URL` | 否 | API 基础 URL |

真实密钥应由运行环境提供，禁止提交到仓库。

## 自动化测试

仓库测试脚本位于 `scripts/`，主要使用 Node.js 原生测试风格和 PHP CLI。按改动域选择最小相关测试集：

```bash
# 运行单个 Node.js 测试
node --test scripts/miniprogram_api_client.test.mjs

# 运行小程序静态契约检查
node --test scripts/miniprogram_static_contract.test.mjs

# 运行 PHP 自由演练文本回放
php scripts/free_practice_text_replay.test.php

# 检查 migration readiness
node --test scripts/migration_readiness.test.mjs
```

生产发布门禁和大范围回归具有单独脚本，应先阅读对应 `.mjs` 与配置，再在具备所需环境时执行。

## 数据库 migration

- migration 文件位于 `database/migrations/`，名称采用时间戳前缀。
- `database/migration_manifest.php` 描述预期表、字段和索引。
- `database/MigrationRunner.php`、`MigrationReadiness.php` 和测试脚本负责执行与校验。
- 已进入共享环境的 migration 保持不可变；新增变更使用新的 migration。
- 执行真实数据库 migration 前确认目标环境、备份和变更窗口。

## 小程序开发

- 页面注册：`mini-program/app.json`。
- API 客户端：`mini-program/utils/api.js`。
- transport：`mini-program/utils/transports/`。
- 业务域与路由契约：`mini-program/business-domain-matrix.json`。
- 云配置：`mini-program/config/cloud.js`。

新增小程序接口时，应同步业务域矩阵、页面调用、云函数白名单和契约测试。写请求应携带幂等键；涉及状态并发时携带状态版本。

## 认证与权限

- Web 与小程序使用 JWT Bearer 认证。
- `api/config.php` 也兼容服务端 session 身份。
- 管理页面入口按 `admin`、`manager` 或 `is_manager` 控制显示，服务端接口仍需独立授权。
- 新管理动作应接入操作审计，并保持最小权限。

## 常见任务

### 添加公开文章

修改 `news/`、`news/index.html`、必要的首页入口、共享样式和 `sitemap.xml`，随后执行结构化数据、链接和预览检查。

### 添加 API 端点

在对应 `api/{domain}/` 创建端点，复用 API kernel、认证和响应约定。若小程序使用该端点，同步 `business-domain-matrix.json` 和代理契约测试。

### 添加 migration

创建新的 SQL migration，更新 manifest，运行 migration compatibility、idempotency、readiness 和 replay 相关测试。

### 修改小程序页面

同步检查 `.js`、`.json`、`.wxml`、`.wxss`，运行页面涉及的契约和语法测试，再使用微信开发者工具完成端到端验证。

## 事实与安全要求

- 公开品牌内容优先引用仓库正式标准和可回溯公开事实。
- 儿童运动观察与体测应明确专业边界，避免医疗化诊断和效果保证。
- 禁止在文档、代码或提交中写入密码、Token、密钥和生产凭据。
- 修改旧页面时保留用户未授权范围内的现有业务行为。
