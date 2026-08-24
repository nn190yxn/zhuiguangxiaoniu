# 接口文档

## Web 入口

| 入口 | 路径 | 用户 |
|------|------|------|
| 公开官网 | `/` | 家长与公开访客 |
| 公开资讯 | `/news/` | 搜索、AI 检索与家长 |
| 员工登录 | `/mobile/login.html` | 员工 |
| 员工工作台 | `/internal.html` | 已登录员工 |
| 个人中心 | `/mobile/mine.html` | 已登录员工 |
| 管理后台 | `/admin/dashboard.html` | 管理角色 |

## 公开内容契约

### 文章页面

公开文章位于 `/news/{slug}.html`，主要页面契约包括：

- 唯一 `title`、`description`、canonical 与 H1。
- `og:title`、`og:description`、`og:type`、`og:url` 和文章发布日期。
- `Article`、`FAQPage`、`BreadcrumbList` JSON-LD。
- 页面可见 FAQ 与 FAQPage 内容一致。
- 返回 `/news/` 的入口和至少三篇相关文章。

### 发现文件

| 文件 | 契约 |
|------|------|
| `news/index.html` | CollectionPage、专题 ItemList 和全部文章入口 |
| `sitemap.xml` | 规范 URL、lastmod、changefreq、priority |
| `robots.txt` | 爬虫允许规则和 sitemap 声明 |
| `manifest.webmanifest` | PWA 公开元数据 |

## PHP API 基础契约

API 基础路径为 `/api`。大多数业务端点使用 JSON，请求通过 `Authorization: Bearer <token>` 认证。写请求可使用：

| Header | 用途 |
|--------|------|
| `Authorization` | JWT 身份认证 |
| `X-Request-ID` | 请求追踪 |
| `Idempotency-Key` | 写操作幂等 |
| `X-State-Version` | 客户端状态版本与冲突控制 |

未认证接口主要集中在登录和运行能力发现；具体授权以端点实现和 `business-domain-matrix.json` 为准。

## 小程序业务域

以下接口来自 `mini-program/business-domain-matrix.json`，该文件也是云函数代理的路由登记来源。

| 业务域 | 代表接口 | 认证 |
|--------|----------|------|
| 认证与会话 | `POST /auth-jwt.php`、微信与企微登录绑定 | 部分公开 |
| 运行能力 | `GET /platform/capabilities.php` | 公开 |
| 制度与通知 | `/policy/search.php`、`/policy/detail.php`、`/policy/notify.php` | JWT |
| 学习课程 | `/learning/category.php`、`/learning/list.php`、`/learning/detail.php` | JWT |
| 知识库 | `/knowledge/list.php`、`/knowledge/detail.php`、`/knowledge/progress.php` | JWT |
| 考试 | `/exam/index.php`、`/exam/save.php`、`/exam/submit.php` | JWT |
| Drill v2 | `/drill/v2/home.php`、`attempts.php`、`turns.php`、`results.php` | JWT |
| 工作量 | `/workload/template.php`、`my-report.php`、`save-report.php` | JWT |
| 积分与档案 | `/points/`、`/staff/profile.php`、`/pass/` | JWT |

## 小程序 transport

`mini-program/utils/api.js` 支持四种策略值：

| 模式 | 行为 |
|------|------|
| `direct` | 小程序直接请求 PHP API |
| `cloud` | 通过腾讯云函数代理请求 PHP API |
| `shadow` | 用于迁移期影子请求与结果比较 |
| `versioned` | 按客户端版本和读写类型选择 direct、cloud 或 shadow |

默认请求超时 15 秒，上传超时 60 秒。客户端会规范化未授权、无权限、冲突、校验和服务端错误。

## 云函数事件契约

`api-proxy` 与 `auth-proxy` 接受协议版本 1 的 request 事件：

```json
{
  "protocol_version": 1,
  "type": "request",
  "method": "GET",
  "route": "/knowledge/list.php",
  "header": {
    "Authorization": "Bearer <token>",
    "X-Request-ID": "<request-id>"
  },
  "data": {}
}
```

代理只允许登记路由，限制请求与响应体大小，并返回 `upstream_status`、`body`、`request_id`、`route_id` 和业务域信息。配置网关密钥后，代理向上游附加 HMAC SHA-256 签名头。

## 数据接口

`api/config.php` 通过 PDO 提供 MySQL 连接。数据库变更位于 `database/migrations/`，`database/migration_manifest.php` 描述 migration 对表、字段和索引的预期影响。

主要数据域包括：

- 员工、门店、岗位、组织分配与生命周期。
- 工作量模板、日报、凭证、审核、预警与导出。
- Drill 场景、过程、尝试、轮次、评估、学习与治理。
- 学习、知识、考试、积分、制度通知与提醒。
- 招聘需求、候选人、简历、队列和平台任务。
- 文件资产、AI 调用、同步、作业队列、outbox 和审计。

## 错误与响应

PHP API 普遍返回 JSON；具体字段由业务端点定义。客户端按照 HTTP 状态和 `code` 将错误归类为 `unauthorized`、`forbidden`、`conflict`、`validation`、`server` 或 `http`。冲突响应可携带当前版本、权威状态和恢复动作。
