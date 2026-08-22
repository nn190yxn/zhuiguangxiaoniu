# 追光小牛企业内网接口

## HTTP 约定

PHP API 位于 `real_sync/api/`，部署后以 `/api/` 为 URL 前缀。多数接口返回 JSON，统一成功结构为：

```json
{
  "code": 0,
  "message": "success",
  "data": {}
}
```

业务校验、认证和权限错误通过非零 `code`、对应 HTTP 状态及 `message` 表达。少量历史模块直接输出 JSON，接入前需按端点核对响应字段。

## API Kernel 契约

新端点和迁移端点可加载 `real_sync/api/kernel/bootstrap.php`。`platformApiContext()` 从服务端请求和端点元数据创建 `PlatformRequestContext`，端点元数据可声明 `client`、`version`、`domain`、`actor_user_id` 和 `actor_staff_id`。合法 `X-Request-ID` 在上下文、响应头和响应包络中保持一致；非法或缺失值由服务端替换。

`platformApiResponse()` 创建成功响应，`platformApiErrorResponse()` 创建错误响应。两者返回可检查的 `PlatformApiResponse` 对象，调用 `send()` 后设置 HTTP 状态、`Content-Type`、`X-Request-ID` 并输出统一 JSON 包络。核心加载不依赖数据库、Session 或部署配置。

端点可调用 `platformApiInstallExceptionHandler()` 安装 Kernel 异常出口。`PlatformApiException` 保留声明的 HTTP 状态、业务码、消息和脱敏后的错误数据；输入异常映射为 HTTP 400，领域异常映射为 HTTP 422，其他异常映射为 HTTP 500 与 `internal_error`。全部异常通过 `PlatformApiLogger` 写入带请求上下文的脱敏结构化日志。

加载现有员工和后台公共层后，端点可调用 `platformApiAuthContext()` 获得 `PlatformAuthContext`。该上下文使用现有角色规范化、会话版本和后台具名权限映射，`requireAuthenticated()` 对未登录请求抛出 HTTP 401，`requirePermission()` 对权限不足抛出 HTTP 403。`visibleStoreIds()` 将客户端门店筛选与服务端 `all|stores|self` 范围取交集，`canAccessStaff()` 同时处理本人、授权门店和总部全量范围。

状态写接口使用 `PlatformStateVersion::advance($currentVersion, $expectedVersion, $context)` 执行乐观锁校验。缺少版本返回 `state_version_required`；过期版本返回 HTTP 409 和 `version_conflict`，`data` 包含 `conflict_type`、`base_version`、`current_version`、`authoritative_state`、`recovery_action`、`retryable` 及可选稳定对象标识。成功返回值严格大于当前版本，旧有两参数调用保持兼容。

### 平台能力版本

`GET /api/platform/capabilities.php` 为公开只读能力发现端点，不访问数据库。响应 `data` 包含 `api_kernel_version`、`response_contract_version`、`supported_clients`、`capabilities` 和 `meta`；`meta` 固定包含 `api_kernel_version`、`response_contract_version`、`endpoint_version` 和端点能力列表。端点版本 `1.3.0` 保留小程序设备会话、刷新轮换和同步协议，并增加 `mini_program_feature_versions`。`mini_program.contract_version=1.0`，`fallback_mode=explicit_allowlist`；每个功能通过 `enabled` 和 `minimum_client_version` 声明展示边界。`sync_contract` 发布协议版本、同步端点、A/B/C 等级、409 状态和后台恢复校验方式；旧客户端继续依据 `legacy_bearer_compatible=true` 使用历史 Bearer Token。

`GET /api/platform/health.php?check=live|ready|dependencies` 提供分层健康检查。省略 `check` 时默认使用 `ready`；`live` 检查应用进程，`ready` 检查数据库与迁移结构，`dependencies` 追加 `platform_jobs` 队列、Worker 和外部依赖配置状态。队列检查返回 `oldest_pending_age_seconds`，最老 `pending|retry_wait` 任务达到 300 秒时状态为 `degraded`；仅存在 outbox 表时队列状态为 `not_configured`。响应 `data.check` 标识检查层级，`data.health` 包含 `status`、`checked_at` 和各检查项；敏感配置只返回配置名称及布尔状态。阻断性状态为 `unhealthy` 时返回 HTTP 503，其他状态返回 HTTP 200。

`GET /api/admin/staff/data-health.php` 与 `GET /api/wecom/status.php` 保留原业务字段，并在 `data.meta` 中追加相同版本结构。两个端点分别要求 `staff.audit_view` 与 `system.settings`，所有响应包含 `request_id` 响应字段及 `X-Request-ID` 响应头。

### 业务域兼容入口

`PlatformBusinessDomainRegistry` 为首批迁移域发布以下 `1.0.0` 代表端点。各入口保留原 URL 与业务字段，通过 Kernel 统一方法检查、认证、权限、请求 ID、异常、审计和兼容元数据。

| 业务域 | 稳定功能 ID | 代表端点 | 方法与权限 | 稳定语义 |
| --- | --- | --- | --- | --- |
| 身份 | `IAM-001`、`IAM-004` | `/api/auth/me.php` | `GET`，已认证员工 | 服务端当前身份上下文 |
| 组织 | `IAM-009` | `/api/admin/organization/tree.php` | `GET`，`organization.manage` | 复用组织树领域服务 |
| 学习 | `BIZ-014` | `/api/learning/lesson.php` | `GET`，已认证员工 | 读取课时并原子完成进度，课程首次完成只奖励一次 |
| 知识 | `BIZ-015` | `/api/knowledge/list.php` | `GET`，已认证员工 | 可见角色和阶段由服务端员工档案派生 |
| 考试 | `BIZ-016` | `/api/exam/save.php` | `POST`，已认证员工 | 草稿自动保存；显式 `state_version` 冲突返回 HTTP 409 |
| 制度 | `BIZ-018`、`MSG-004` | `/api/policy/notify.php` | `GET list`；`POST read|confirm|send`；`send` 要求 `policy.notify_send` | 收件箱、阅读确认和受控通知发送 |
| 演练 | `BIZ-006` | `/api/drill/v2/home.php` | `GET`，已认证员工 | 保留 v2 首页、任务状态和 AI Runtime 语义 |
| 技能复盘 | `BIZ-009` | `/api/skill/upload-recording.php` | `POST`，已认证员工 | 录音写入私有存储并通过统一任务队列处理 |
| 提醒 | `MSG-003` | `/api/reminder/jobs.php` | `GET` 查询；`POST` 要求 `reminder.manage` | 手工运行写入 `reminder.schedule.tick` 平台任务 |
| 企业微信 | `MSG-001` | `/api/wecom/sync-members.php` | `GET` 查询；`POST` 要求 `wecom.sync` | 手工同步写入 `wecom.members.sync` 平台任务 |
| 内容专题 | `BIZ-019`、`BIZ-020`、`BIZ-021`、`BIZ-022` | `/api/campaign/list.php` | `GET`，已认证员工 | 问卷、活动、夏令营和体测兼容承接 |
| 工作量 | `BIZ-001` 至 `BIZ-005` | `/api/workload/my-report.php`、`/api/workload/save-report.php` | `GET` 或 `POST`，已认证员工 | 保留日报字段并返回等级 A 同步对象和持久化状态版本 |
| 招聘 | `BIZ-010` 至 `BIZ-013` | `/api/admin/recruitment/candidates.php` | `GET`，`recruitment.resume_view` | 候选人查询保留历史字段并返回兼容元数据和状态版本 |

考试和工作量历史客户端可省略 `state_version` 继续保存；提供版本的客户端必须使用最近一次响应版本。工作量显式旧版本返回 HTTP 409 和 `version_conflict`，成功写入在业务事务中递增版本并记录 `submission` 同步对象。制度通知的 `action` 与 HTTP 方法固定映射，未知动作返回 HTTP 400，方法不匹配返回 HTTP 405。

招聘联系写入 `POST /api/admin/recruitment/candidate-contact.php` 要求 `recruitment.resume_contact`，并以 `state_version` 执行乐观锁。`POST /api/admin/recruitment/hire-approval.php` 要求 `recruitment.hire_approve`；`POST /api/admin/recruitment/hire-to-employee.php` 要求 `recruitment.hire_convert` 与 `Idempotency-Key`。转换服务锁定投递、审批和幂等记录，校验已批准需求、已完成简历、A/B 等级、预约队列、已约联系状态和批准录用状态，再在同一事务中调用员工生命周期服务并写入转换结果与 outbox。同键同请求返回首次 `response_json`，同键不同请求返回 HTTP 409。

### 多端同步

`GET /api/platform/sync.php?action=levels` 返回 `sync_contract_version` 与同步等级元数据。端点要求有效员工身份，A/B/C 最大陈旧时间分别为 30、300 和 1800 秒。

`GET /api/platform/sync.php?action=changes` 接受可选 `cursor`、`limit`、`domain` 和 `object_type`。响应 `data` 包含 `items`、`tombstones`、`next_cursor`、`has_more`、`sync_anchor` 和 `etag`。游标使用服务端签名并绑定员工授权范围、会话版本和筛选条件；签名异常返回 `invalid_sync_cursor`，范围变化返回 `sync_cursor_scope_changed`。客户端可发送 `If-None-Match`，结果未变化时端点返回 HTTP 304。活动变更携带稳定对象标识、`state_version`、`updated_at`、同步等级、ETag 和权威状态；删除、撤销和权限失效分别使用 `deleted`、`revoked` 和 `permission_revoked` 墓碑。

`GET /api/platform/sync.php?action=draft` 按 `domain`、`object_type` 和 `object_id` 读取当前员工未过期草稿。`PUT` 使用同一动作，并接收 `draft_version`、`base_state_version`、`payload`、`source_client`、可选 `source_device_id` 和 `ttl_seconds`；新草稿从 `draft_version=0` 提交，服务端成功后返回递增版本。设备并发冲突返回 HTTP 409、`draft_version_conflict` 和当前服务端草稿，较旧业务基础版本返回 `base_version_conflict`。`DELETE` 携带当前 `draft_version`，服务将草稿标记删除并把墓碑写入增量结果。草稿负载采用业务域字段白名单，单条上限 64KB，有效期上限 24 小时。

### 异步副作用

业务写入在已开启的 PDO 事务内调用 `PlatformOutboxService::enqueue()`，传入稳定事件键、业务事务键、幂等键、事件类型和载荷。服务保存规范 JSON 与 SHA-256；相同幂等键和摘要返回首次事件，同键不同摘要抛出 `outbox_idempotency_conflict`，缺少事务抛出 `outbox_transaction_required`。

Worker 取得 `PlatformJobLease` 后调用 `beginSideEffect()` 与确认或失败方法。每次状态写入验证活动租约，并在收据中核对 `job_id`、`worker_id` 和 `fencing_token`；租约失效抛出 `job_lease_lost`。人工 `replay()` 记录操作人、原因和次数并保留原事件身份与载荷；已确认收据通过独立补偿状态执行恢复操作。

任务恢复契约由 `scripts/platform_job_recovery.property.test.mjs` 验证。Worker 中断后，租约到期允许其他 Worker 使用递增 fencing token 重领；旧 Worker 的心跳、任务结果和副作用确认均被拒绝。可重试失败按有上限指数退避进入 `retry_wait`，达到尝试上限进入 `dead_letter` 和人工恢复；人工 outbox 重放仅重置投递状态并增加重放审计字段，事件键、幂等键、载荷和摘要保持不变。

### 文件资产与访问

业务服务通过 `PlatformFileAssetService::register()` 登记文件元数据。输入包含 `asset_class`、`purpose_code`、所有者、可选业务对象、原始名称、实际 `mime_type`、`byte_size`、64 位小写 SHA-256、`storage_driver`、相对 `storage_key`、`retention_policy_code`、可选留存和下载期限，以及创建主体。服务拒绝绝对路径、父目录跳转、反斜杠存储键、带路径的原始名称、分类不支持的存储驱动、超出分类上限的文件和不完整生命周期，并从四类策略派生 `access_mode`。

`grant()` 保存与资产绑定的 `read`、`download` 或 `manage` 授权。授权主体可绑定 `scope_type` 与 `scope_id`，并保存原因、可选有效期和授权主体；撤销通过持久化 `revoked_at` 表达。`authorize()` 接收资产、当前主体、动作、为当前资产读取的授权集合以及 `request_id`，按资产活动状态、留存、下载期限、公开分类、所有者、授权资产 ID、主体、权限、范围、有效期和撤销状态返回 `allowed`、`reason_code`、命中权限和范围。每次判定写入 `platform_file_access_events`，审计字段包含资产、操作者、动作、允许或拒绝、原因码、范围、请求 ID、访问原因和发生时间，审计载荷不包含物理存储键。

`PlatformPrivateFileStorage::storeUploadedFile()` 接收标准 PHP 上传数组和存储选项；`storeFile()` 供已受信任的临时文件流程使用。选项包含 MIME 白名单、大小上限、业务命名空间和可选预期 SHA-256。成功结果返回实际 MIME、实际大小、SHA-256、`local_private` 驱动和随机相对存储键。`storeBytes()` 用于 Adapter 写入已由业务层验证的分片或生成内容。

`prepareDownload()` 仅接受一个或多个私有相对键，在输出任何字节前逐项完成格式、存在性、符号链接和私有根目录边界校验，再返回内部流式计划；`stream()` 按计划顺序输出内容，可附带私有禁缓存、长度、文件名与 `nosniff` 响应头。`cleanupExpired()` 将 `retention_until <= now` 的活动对象视为到期，逐项返回 `deleted` 或 `missing`；重复清理保持幂等。`WorkloadPlatformFileAdapter::prepareDownload()` 进一步要求导出任务状态为完成、下载期限有效且文件位于批准目录。`RecruitmentPlatformFileAdapter` 将新简历登记为 `sensitive_source`，下载先执行资产授权；缺少 `platform_asset_id` 的历史记录通过受控私有根兼容读取。

### AI 能力契约

业务 Adapter 通过 `PlatformAiCapabilityGateway::invoke()` 调用统一能力层。请求字段为 `capability`、固定 `contract_version=ai-capability.v1`、合法 `request_id`、`purpose`、`data_classification`、`input`、可选 `preferred_provider`、`timeout_ms`、`max_attempts`、`idempotency_key`、`retention_policy_code`、可选 `retention_until` 和 `approval_context`。当前生产能力为 `text.generate`、`assessment.score`、`vision.extract`、`ocr.extract` 和 `speech.transcribe`；`image.generate` 固定返回 `capability_unsupported`，调用次数为零。

供应商执行器由调用方注入，并接收能力、契约版本、请求 ID、用途、原始业务输入、当前剩余超时、尝试序号和幂等键。成功结果必须包含 `model`、`processing_version` 与 `output`，网关返回 `status=completed`、请求与能力身份、requested/actual provider、处理元数据、耗时、尝试次数、fallback 标志和业务输出。审批回调接收用途、数据分类、候选供应商、请求 ID、留存策略和审批上下文；默认仅允许 `public` 与 `internal`，个人、敏感和受限数据要求显式审批。

稳定错误码包括 `request_invalid`、`capability_unsupported`、`approval_denied`、`provider_unconfigured`、`authentication_failed`、`rate_limited`、`timeout`、`transport_failed`、`provider_unavailable`、`response_invalid` 和 `internal_error`。可恢复供应商故障标记 `retryable=true` 与 `recovery_required=true`，供现有 Worker 或业务 Adapter 保持原恢复状态。每次最终结果通过 `PlatformAiInvocationStore` 保存一条调用摘要，原始输入、输出和供应商响应不进入该记录。成功结果以 `request_id` 对应唯一调用摘要，双方的 `capability`、`contract_version`、`processing_version`、实际供应商和 `status` 必须一致。

权威 Runtime 提供 `ai_gateway_text_generate(prompt, systemPrompt, purpose, options)` 与 `ai_gateway_ocr_extract(imageInput, purpose, options)` 两个受控辅助接口。个人数据调用默认关闭，业务入口需要在 `options` 中显式传入 `business_authorized=true` 和可审计 `approval_id`。体测 `POST /api/ai-services.php` 保持 `action=ocr|plan|summer_camp_report`、请求字段及响应结构不变：`ocr` 映射到百度 `ocr.extract` 和本地确定性字段解析，`plan` 与 `summer_camp_report` 映射到 DeepSeek `text.generate`。`GET /api/ai-services.php` 的 `ocrReady` 只依赖百度 OCR 配置。

Drill v2 继续由 `DrillAiAdapter` 输出 `content`、`intent` 和受控 `metadata`。Adapter 内部使用用途 `sales_drill_text_generate` 调用权威 Runtime，审批标识为演练业务运行时，模型元数据读取 Runtime 的实际 DeepSeek 模型配置；原始供应商响应仍不进入业务表。

招聘 `RecruitmentPlatformAiAdapter` 固定使用 DeepSeek `text.generate`，`RecruitmentPlatformOcrAdapter` 固定使用百度 `ocr.extract`。两者先通过外部处理门禁读取批准记录，并把 `business_authorized=true`、`approval_id`、敏感个人数据分类、留存策略和稳定幂等键传给 Runtime；审批记录或审批标识缺失时返回 HTTP 503。

## 认证

### PWA 启动路由

`GET /mobile/` 是 Manifest、登录默认恢复和浏览器兼容入口共同使用的 PWA 启动路由。可选查询参数 `redirect` 接受同源 `/mobile/` 白名单页面及其查询参数和哈希；外域、非白名单和无效 URL 统一解析为 `/mobile/mine.html`。`GET /internal.html` 保留登录展示和历史浏览器兼容能力，认证恢复后通过同一受控解析器进入 `/mobile/`。

### PWA 响应式与键盘交互

工作量、演练、学习和个人中心使用共享应用壳，并以 `<768`、`768–1023`、`>=1024` 作为手机、平板和桌面布局边界。横屏且可用高度不超过 600 像素时，固定导航、操作栏和模态内容必须回流。移动页面 viewport 保留用户缩放能力，长文本、图片和视频需要在 200% 浏览器缩放下保持在可视区域内。

学习分类作为 ARIA tablist 暴露，左右方向键移动激活项，`aria-selected` 与 roving tabindex 同步更新。自定义模态打开后将焦点移入首个有效控件，Tab 与 Shift+Tab 在模态内循环，Escape 关闭模态，关闭后焦点返回原触发元素。演练多步骤 Sheet 在内部步骤切换期间保持首次打开时记录的返回焦点。

主要认证方式为 HTTP Bearer JWT：

```http
Authorization: Bearer <JWT_TOKEN>
```

`real_sync/api/config.php` 负责读取 JWT 和数据库配置。Token 解析会关联 WordPress 用户与员工状态。部分网站入口保留 PHP Session 兼容路径。

版本化会话公共层位于 `real_sync/api/auth/SessionFactory.php`、`SessionService.php` 和 `SessionStore.php`。调用方使用 `platformSessionService($db)` 创建服务，`issue($identity, $clientType, $deviceId, $identityHash)` 返回 `access_token`、`token_type=Bearer`、`access_expires_in=900`、单次使用的 `refresh_token`、`refresh_expires_in=2592000`、`session_id` 和 `session_version`。小程序客户端必须传入 64 位微信身份摘要；其他客户端可省略第四个参数。`refresh($refreshToken, $currentSessionVersion)` 原子轮换刷新凭据；无效、过期、已撤销、版本变化和复用分别返回 HTTP 401 业务码 `invalid_refresh_token`、`refresh_token_expired`、`session_revoked`、`session_version_changed` 和 `refresh_token_reused`。刷新令牌复用同时撤销对应会话族并记录安全事件。

`POST /api/auth/refresh.php` 是 PWA 刷新与退出端点。普通刷新要求受信任 `Origin`、`platform_refresh` HttpOnly Cookie、`platform_csrf` Cookie 和同值 `X-CSRF-Token` 请求头；服务继续校验 CSRF 签名绑定的会话、WordPress 账号、员工生命周期、当前会话版本和刷新令牌轮换状态。成功响应只返回新访问令牌、有效期、会话 ID 和会话版本，新刷新令牌通过原受限 Cookie 轮换。`POST /api/auth/refresh.php?action=logout` 使用相同安全校验，撤销会话族并清除两个 Cookie。

PWA 请求层为每次业务请求保留其实际使用的访问令牌。多个请求延迟返回 401 时，首个响应完成刷新轮换，后续响应检测到内存令牌已更新后直接使用新令牌重放；标签内共享刷新 Promise，标签间通过 Web Locks 串行刷新，跨标签广播仅携带会话事件与版本。

### PWA ApiClient

PWA 业务页面通过 `window.ApiClient` 调用 API。`get()`、`post()`、`put()` 和 `delete()` 保留统一 JSON 响应，底层 `request()` 默认等待 15 秒并为每次请求附加 `X-Request-ID`；调用方可传 `timeout`、`requestId`、`idempotencyKey`、`stateVersion` 和 `stateVersionField`。网络、超时、401、403、409、400/422、5xx 和其他 HTTP 错误分别映射为 `network`、`timeout`、`unauthorized`、`forbidden`、`conflict`、`validation`、`server` 和 `http`，错误对象同时保留状态码、业务码、请求 ID 与原始响应。

条件读取传入 `etag: true`，客户端保存响应 `ETag` 并在后续同键请求发送 `If-None-Match`；HTTP 304 返回 `not_modified: true`。增量读取通过 `cursor` 传入服务端游标，响应内层 `data.next_cursor` 会提升到顶层 `next_cursor`。状态写入通过 `stateVersion` 注入默认 `state_version` 字段；HTTP 409 错误暴露 `conflictType`、`baseVersion`、`currentVersion`、`authoritativeState`、`recoveryAction` 和 `retryable`。`onConflict` 只有返回 `{ retry: true }` 时触发一次受控重试。

### PWA DraftStore

页面先调用 `DraftStore.setIdentity({ userId, staffId, sessionVersion })`，再通过 `create({ domain, objectType, objectId, schemaVersion, allowedFields })` 获取对象级草稿句柄。句柄提供 `getLocal()`、`saveLocal()`、`clearLocal()`、`getRemote()`、`saveRemote()` 和 `deleteRemote()`；远端方法统一调用 `action=draft` 接口，并固定发送 `source_client=pwa`、稳定设备 ID 和 `ttl_seconds=86400`。本地记录只包含批准 payload、对象身份、schema 版本、草稿版本、业务基础版本、设备 ID 与起止时间。`DraftStore.clearSensitive()` 只清理 `zgxn_sensitive_draft:` 前缀记录，保留普通用户偏好与稳定设备 ID。

### PWA Service Worker 消息

页面向 waiting Worker 发送 `{ "type": "GET_VERSION" }` 并传入 `MessagePort`，Worker 返回 `{ "type": "VERSION", "version": "5" }`。用户确认更新后，页面发送字符串 `SKIP_WAITING`；Worker 同时接受 `{ "type": "SKIP_WAITING" }` 结构化消息。`controllerchange` 只在本次页面已确认更新时触发受控刷新。刷新前的当前路径和时间戳临时保存在当前标签页 `sessionStorage` 的 `zgxn_pwa_update_recovery` 中，有效期为 5 分钟；恢复成功后清除。

Runtime 在更新恢复和离线转在线时调用 `AppAuth.ensureAccessToken(false)`。成功后发布 `pwa:session-restored`，其 `detail` 包含 `reason` 和可选恢复路径；网络恢复流程随后发布 `pwa:network-restored`，其 `detail.sessionRestored` 表示会话重验结果。会话重验失败时进入现有 PWA 登录恢复路径。

`POST /api/auth/mini-program-session.php?action=refresh` 接收 JSON 字段 `refresh_token` 和 `device_id`。服务要求会话客户端为 `mini_program`，并核验会话设备、员工与 WordPress 账号状态、员工生命周期、微信或企业微信身份摘要及当前 `session_version`；成功响应在 `data` 中返回轮换后的 `token`、`refresh_token`、`expire`、`refresh_expire`、`session_id`、`session_version` 和 `session_type=device`。`action=logout` 使用相同设备与身份校验后撤销会话族。身份或设备变化、账号不可用、会话版本变化和刷新凭据失效均返回 HTTP 401，并设置 `data.reauthentication_required=true`。

`auth-jwt.php` 的密码、微信、企业微信登录和绑定动作在请求包含 `client_type=mini_program` 时尝试签发设备会话。已绑定身份返回上述完整设备会话字段；密码登录后仍待绑定的员工继续获得兼容 JWT，用于完成现有绑定门禁，绑定成功响应立即升级为设备会话。

云开发小程序媒体登记使用 `media-ticket` 云函数事件协议：`protocol_version=1`、`type=media_ticket`、`purpose`、`business_type`、`business_id`、`idempotency_key` 和 `file`。`file` 包含 `fileID`、`mime_type`、`byte_size` 和 64 位小写 SHA-256。用途白名单包含 `workload_evidence`、`profile_avatar`、`knowledge_media` 和 `drill_audio`，工作量图片上限 5MB，演练音频上限 50MB。云函数向 PHP `POST /api/cloud/media-ingest.php` 转发时附加 `X-Cloud-*` 网关签名头和原始幂等键。

`POST /api/cloud/media-ingest.php` 校验网关签名和当前员工身份后，按幂等键写入 `platform_cloud_media_mappings`，响应字段包含 `asset_key`、`purpose`、`business_type`、`business_id`、`source_fingerprint`、`fileID`、`mime_type`、`byte_size`、`sha256`、`status`、`retry_count` 和 `error_code`。状态取值为 `pending`、`ready`、`failed` 和 `expired`。历史媒体镜像通过 `source_fingerprint` 去重，同一历史源 ready 映射复用，同一幂等键重复登记返回首次结果。

`real_sync/api/auth-jwt.php` 通过请求动作提供以下能力：

| 动作 | 用途 |
| --- | --- |
| `login` | WordPress 用户名或邮箱密码登录 |
| `wxlogin` | 微信 OpenID 登录 |
| `wxbind` | 验证账号密码并绑定微信 |
| `wecomlogin` | 企业微信成员登录 |
| `wecombind` | 验证账号密码并绑定企业微信 |
| `verify` | 校验 Token 和当前身份 |
| `refresh` | 刷新 Token |

## 权限上下文

`real_sync/api/common/context.php` 是业务接口的统一身份入口。接口按需调用员工上下文、门店范围和个人范围检查。后台接口通过 `real_sync/api/admin/common.php` 复用统一权限。

员工与组织后台使用以下具名权限点：`staff.view_all`、`staff.create`、`staff.edit`、`staff.offboard`、`staff.restore`、`staff.reset_password`、`staff.purge`、`organization.manage`、`role.manage_privileged`、`staff.audit_view` 和 `system.settings`。制度通知发送使用 `policy.notify_send`。总部运营与系统管理员共享前十项员工管理权限，制度发送权限授予系统管理员与负责人，系统设置权限仅属于系统管理员。

主要范围：

| 身份 | 数据范围 |
| --- | --- |
| 总部角色 | 全部门店和员工 |
| 店长 | 授权门店 |
| 普通员工 | 员工本人 |
| 系统管理员 | 全量员工、组织、审计和系统设置 |

## API 目录

| 业务域 | 路径 | 主要职责 |
| --- | --- | --- |
| 认证 | `api/auth-jwt.php`、`api/auth/` | 登录、Token、当前用户、改密 |
| 员工 | `api/staff/`、`api/admin/staff/` | 员工列表、详情、编辑、安全操作 |
| 组织 | `api/admin/organization/` | 岗位字典及后续门店、任职管理 |
| 批量导入 | `api/admin/staff-import.php` | JSON 或 CSV 员工导入 |
| 工作量 | `api/workload/` | 日报、凭证、审核和统计 |
| 后台工作量 | `api/admin/workload/` | 总部汇总和管理查询 |
| 学习 | `api/learning/` | 课程、进度和学习数据 |
| 知识库 | `api/knowledge/` | 分类、列表和详情 |
| 考试 | `api/exam/` | 试卷、答题和成绩 |
| 通关 | `api/pass/` | 通关进度和认证 |
| AI 演练 | `api/drill/`、`api/ai-services.php` | 演练、转写和分析 |
| 制度 | `api/policy/` | 制度通知和阅读状态 |
| 提醒 | `api/reminder/` | 规则、订阅、任务和通知 |
| 企业微信 | `api/wecom/` | 同步、绑定、审计、消息和回调 |
| 积分 | `api/points/` | 积分记录和统计 |
| 问卷 | `api/survey/` | 提交、详情、统计和导出 |
| 活动 | `api/campaign/` | 活动业务接口 |
| 暑期评估 | `api/summer-camp/` | 评估业务接口 |
| 统计 | `api/statistics/` | 员工、门店和设备统计 |
| 搜索 | `api/search/` | 跨业务搜索 |
| 待办 | `api/todos/` | 员工待办 |
| 上传 | `api/upload.php`、`api/admin/upload.php` | 文件上传 |
| 安全审计 | `api/admin/security/`、`api/admin/system/` | 登录和操作审计 |

## 销售演练 v2 公共契约

员工演练 v2 端点通过 `api/drill/v2/_common.php` 启动，只接受端点声明的方法，并使用 `appGetCurrentStaffContext()` 确认当前员工。标准响应为 `{ "code": 0, "message": "success", "data": {}, "request_id": "..." }`；错误保留相同字段，并使用对应 HTTP 状态。跨域公共配置允许 `Authorization`、`Idempotency-Key` 和 `X-Request-ID` 请求头。

管理演练 v2 端点通过 `api/admin/drill/v2/_common.php` 启动，并按模块校验 `drill.content_manage`、`drill.knowledge_manage`、`drill.rubric_calibrate`、`drill.plan_publish`、`drill.review`、`drill.coaching`、`drill.analytics_all` 或 `drill.migration_manage`。缺少登录态返回 HTTP 401，缺少目标权限返回 HTTP 403。

创建类请求通过 `Idempotency-Key` 传入最长 128 字符的稳定键。服务以当前用户、业务动作和键定位请求；首次执行返回 `idempotent=false`，相同请求重放保存的业务结果并返回 `idempotent=true`，同键不同请求或首次请求尚在处理时返回 HTTP 409。

内容版本使用 `draft`、`in_review`、`published` 和 `archived` 状态。提交审核、退回修改、审核通过发布和归档按固定状态机执行，内容字段仅在草稿状态开放写入；已发布场景或评分规则的修订创建递增版本。演练实例创建契约固定保存 `scenario_version_id`、`persona_snapshot`、`persona_snapshot_hash` 和 `rubric_version_id`，后续发布不会替换已有实例的内容引用。对应完整 HTTP 管理端点将在内容管理任务中接入。

知识点、移动学习资源和知识映射使用 `draft`、`review_pending`、`published` 和 `retired` 状态。`DrillLearningService` 提供 `createKnowledgePointDraft()`、`createLearningResourceDraft()`、`createMappingDraft()` 和对应状态转换；映射发布返回覆盖统计，缺少知识或移动资源时保持审核中并返回 `publication_blocked=true` 与失败项。`DrillRubricService` 在评分规则发布事务内调用 `assertRubricPublishable()`，保证每个可补强关键项具备已发布知识点和移动资源。

员工侧领域契约由 `preparationLearning()`、`generateRecommendations()` 和 `recordProgress()` 组成。准备学习按员工、训练域和评分版本返回映射版本、知识点、移动资源及进度；评分后推荐只基于已完成评分中的未达标关键项，并为每条推荐锁定对话证据、知识点版本、资源版本及映射哈希；学习完成响应包含知识点状态和原演练再次练习上下文。对应 HTTP 端点由后续员工端和管理端接口任务接入。

训练计划领域契约由 `DrillPlanService::createDraft()` 和 `publish()` 提供。发布要求 `drill.plan_publish` 权限、有效时间窗、至少一名有效复核人和 8 至 64 字符发布键；同一键只接受相同时间窗、复核人、目标范围和计划定义。发布响应包含 `publication_id`、`publication_no`、状态、目标数和任务数，重放额外返回 `idempotent_replay=true`。`DrillAssignmentService::transition()` 使用 `status_version` 乐观锁处理状态事件，`refreshPrerequisites()` 从受控事实解析器重新评估发布时策略并追加历史快照，`enqueueDueReminders()` 以通知键幂等创建截止提醒。HTTP 端点由后续员工端和管理端接口任务接入。

员工音频上传、访问和转写契约由 `POST /api/drill/v2/audio-assets.php`、`POST /api/drill/v2/audio-chunks.php`、`GET|POST /api/drill/v2/audio-access.php` 和 `POST /api/drill/v2/audio-transcripts.php` 提供，写入端点均要求登录态和 `Idempotency-Key`。音频资源请求绑定 `attempt_id`，校验实例属于当前员工、`mime_type` 属于允许音频格式、`byte_size` 不超过 50MB、`checksum` 为 64 位 SHA-256；`real_call_review` 真实录音必须提供 `consent_status=granted`、授权依据、用途、访问范围和留存期限，默认留存 180 天。分片上传请求绑定 `audio_asset_id`，校验资源属于当前员工、`chunk_no` 为正数、`byte_size` 不超过 5MB、`content_base64` 可解码且长度和 SHA-256 摘要与声明一致；请求可携带 `transcript_text`、`provider`、`model`、`confidence` 和 `raw_response_ref` 写入 `partial` 临时转写，真实录音授权失效或到期时阻止转写。最终转写请求按 `expected_chunks` 检查完整分片集合，按 `chunk_no` 重排临时转写并写入 `final` 转写；也可通过 `final_transcript_text` 保存供应商最终文本。

`GET|POST /api/drill/v2/audio-access.php?audio_asset_id=<ID>` 完成首次权限和留存校验，返回 `url` 与 `download_url`，响应排除物理 `storage_path`。客户端访问该 URL 的 `download=1` 形式时，端点再次执行当前身份、对象所有权或 `reviewer|coach|admin` 范围、真实录音授权和留存校验，记录包含请求 ID、范围、访问原因、时间与允许或拒绝结果的 Drill 审计，再以私有禁缓存响应顺序流式输出分片。到期治理通过同一 Adapter 清理资产标记与分片，保留资源元数据、评分、复核和认证结果。同一资源摘要或同一分片序号重复提交相同内容返回幂等重放；同一分片序号提交不同摘要或大小返回业务错误并要求重传对应分片。端点使用统一 v2 响应结构，幂等键冲突按 `DrillIdempotencyException` 的状态码返回。

旧接口继续位于 `api/drill/`。`scripts/drill-api-baseline.json` 是并行期契约基线，记录 13 个端点及 `drill_scripts.id`、`script_knowledge.id`、`drill_recordings.id`、`script_analysis_records.id`、`script_ai_feedback.id` 等独立 ID 空间。修改旧端点后必须运行快照检查并确认风险信号变化属于计划内迁移。

## 员工管理接口基线

现有员工后台主要使用以下接口：

| 方法 | 路径 | 行为 |
| --- | --- | --- |
| `GET` | `/api/admin/staff/list.php` | 按关键词、门店、主岗位、角色、生命周期、离职范围和分页返回员工白名单字段 |
| `GET` | `/api/admin/staff-list.php` | 兼容入口，转接 `/api/admin/staff/list.php` |
| `GET` | `/api/admin/staff/detail.php` | 返回员工、当前与历史任职、账号状态、业务摘要、可用操作、设备、近期登录审计和目标员工操作审计 |
| `GET` | `/api/admin/staff/export.php` | 按员工一览当前筛选和权限流式导出 UTF-8 CSV |
| `GET` | `/api/admin/staff/data-health.php` | 实时检查员工标识、组织引用、角色能力和账号档案关联健康状态 |
| `GET|POST` | `/api/admin/staff/profile-corrections.php` | 分页查询并批准或驳回员工档案更正申请 |
| `POST` | `/api/admin/staff/update.php` | 更新姓名、手机号、角色、门店或状态 |
| `POST` | `/api/admin/staff/reset-password.php` | 重置员工账号密码 |
| `POST` | `/api/admin/staff/unbind-wechat.php` | 解除微信绑定 |
| `POST` | `/api/admin/staff/unlock-account.php` | 解除账号锁定 |
| `POST` | `/api/admin/staff-import.php` | 通过可重试批次逐行创建员工、WordPress 账号和主任职 |
| `POST` | `/api/admin/staff/create.php` | 在单一事务中创建 WordPress 账号、员工档案、主任职和操作审计 |
| `POST` | `/api/admin/staff/offboard.php` | 离职归档并停用账号、关闭任职、撤销既有会话与订阅 |
| `POST` | `/api/admin/staff/restore.php` | 恢复离职员工账号并创建新的主岗与兼岗记录 |
| `POST` | `/api/admin/staff/privileged-role-confirm.php` | 由另一名系统管理员签发高权限角色变更确认令牌 |
| `POST` | `/api/admin/staff/purge-check.php` | 检查误建员工业务关联并在零关联时签发短时清理确认令牌 |
| `POST` | `/api/admin/staff/purge.php` | 使用有效确认令牌清理零业务关联的误建员工身份链 |

员工新增接口要求总部运营或系统管理员身份。请求字段包括 `name`、`phone`、`store_id`、`position_id`、`role`、`initial_password`，并支持 `employee_no`、`username`、`email`、`entry_date` 和 `stage`。缺省工号由配置前缀、宽度和起始值生成；缺省账号与邮箱从最终工号派生。工号、手机号或账号冲突时接口返回 HTTP 409，`data.conflict_fields` 标识冲突类型，`data.existing_profiles` 提供脱敏档案摘要。成功响应的 `data.item` 包含员工、组织和账号标识，不返回初始密码。

`admin/staffs.html` 通过三步抽屉调用员工新增接口。页面使用组织字典中启用的门店与岗位，客户端预检姓名、手机号、可选工号、入职日期、组织、角色、可选账号和默认密码复杂度，确认摘要仅展示脱敏手机号及密码已设置状态。提交按钮使用本地进行中状态防止重复请求；服务端冲突字段与脱敏档案摘要直接进入抽屉错误区。

员工批量导入接受 JSON `records`、上传的 JSON 或 CSV，并支持调用方提供 UUID `batch_key`。每行字段与单员工新增一致，中文列名可使用工号、姓名、手机号、门店ID、岗位ID、角色、初始密码、账号、邮箱、入职日期和阶段。响应包含 `batch_key`、批次状态、成功与失败数量、`retryable_batch_key` 和逐行摘要、校验结果、员工 ID、重试次数；同时保留 `created`、`updated`、`linked`、`skipped`、`errors` 兼容字段。部分失败时使用同一批次键和相同行数重新提交，服务只处理失败行。相同完成批次保持幂等返回，处理中批次、其他员工占用的批次键或行数变化返回 HTTP 409。

`admin/staffs.html` 的批量导入标签支持 CSV/JSON 选择和拖放、CSV 模板下载、中英文字段自动映射与手动调整。页面先对必填项、手机号、组织 ID、角色和密码复杂度进行逐行格式预检查，再以 JSON `{ records, batch_key? }` 请求接口；服务端仍负责最终业务校验。响应逐行显示状态、重试次数和校验说明；存在 `retryable_batch_key` 时，页面使用相同记录数量与原批次键重新提交失败批次。

员工列表参数包括 `keyword`、`store_id`、`position_id`、`role`、`lifecycle_status`、`include_offboarded`、`page` 和 `page_size`。总部运营和系统管理员可查看完整手机号与账号字段。列表与详情查询均使用显式字段白名单。

员工导出接口接受与列表相同的筛选参数，忽略请求中的分页参数并从第一行开始导出，最多输出 20,000 行。CSV 包含工号、姓名、当前门店、主岗位、系统角色、人员阶段、生命周期、账号状态、入职与离职时间和创建时间；手机号、登录账号和邮箱沿用调用角色的原值或脱敏策略。超过上限返回 HTTP 400，文件使用 UTF-8 BOM 并转义表格公式前缀。

员工数据健康接口仅允许拥有 `staff.audit_view` 权限的总部运营和系统管理员访问。响应 `data` 包含 `checked_at`、`healthy`、`total_issues`、分类 `counts` 和分类 `issues`；检查范围包括重复工号、重复手机号、重复账号关联、无效门店、无效岗位、角色不一致、在职员工缺账号和内部员工角色账号缺档案。服务实时只读计算结果，问题修复后再次请求即可获得关闭后的最新状态。

`admin/staffs.html` 的数据健康标签展示 `duplicate_employee_numbers`、`duplicate_phones`、`duplicate_accounts`、`invalid_stores`、`invalid_positions`、`role_mismatches` 和 `orphan_identities` 七类计数及问题清单。员工问题入口打开对应详情，孤立身份入口回到员工目录修复上下文；页面不直接修改健康检查结果，管理流程修复后通过“重新检查”再次请求接口确认关闭。

员工本人使用 `GET /api/staff/profile.php` 读取姓名、工号、联系方式、门店、主岗位、当前兼岗、入职日期和账号状态。`GET /api/staff/profile-corrections.php` 只返回本人历史申请；`POST` 接受 `changes` 对象及 `request_reason`，允许申请更正 `name`、`phone`、`store_id`、`primary_position_id` 和 `entry_date`。相同字段快照的待处理申请幂等返回。

总部运营和系统管理员使用 `GET /api/admin/staff/profile-corrections.php`，按 `status`、`page`、`page_size` 查询申请。`POST` 接受 `request_id`、`status=approved|rejected` 和 `handler_comment`，事务锁定待处理申请并保存处理人员、意见和时间；重复处理返回 HTTP 409。批准仅记录处理结论，实际资料更新继续调用员工编辑接口，以保留组织任职校验和完整审计。

`POST /api/admin/staff/update.php` 由总部运营和系统管理员使用。请求通过 `staff_id` 或 `id` 标识员工，可更新 `name`、`phone`、`stage` 和 `status`；组织变化同时传入 `store_id`、`position_id` 或 `primary_position_id`、`role`、`effective_date`。所有编辑必须提供 `change_reason` 或 `reason`，离职员工保持只读。

员工编辑在同一事务中执行员工行锁、手机号唯一性检查、组织与角色校验、主岗区间变更、基础资料或当前快速字段更新、账号启停同步和审计。手机号或任职冲突返回 HTTP 409，字段与组织校验失败返回 HTTP 400。成功响应包含 `data.item` 和组织变化时的 `data.organization_assignment`。

涉及 `admin` 的角色提升或降权需要先由另一名在职系统管理员调用 `POST /api/admin/staff/privileged-role-confirm.php`。确认请求包含 `requester_user_id`、`staff_id` 和 `target_role`，成功时返回有效期 5 分钟的 `confirmation_token`；后续编辑或恢复请求通过 `privileged_role_confirmation_token` 提交。令牌绑定请求人、审批人、目标员工、角色变化和目标会话版本，篡改、过期或状态变化返回 HTTP 400。操作审计保存变更前后权限列表、审批双方标识和确认 JTI，完整令牌不会写入日志。

`POST /api/admin/staff/offboard.php` 由总部运营和系统管理员使用。请求通过 `staff_id` 或 `id` 标识员工，要求 `offboard_date`、`offboard_reason` 和 `confirmed=true`；`effective_date`、`reason` 与 `confirm` 作为兼容字段。离职日期采用最后在岗日口径且不能晚于当前日期。成功响应包含只读员工快照、关闭后的任职记录及设备、小程序订阅和制度订阅撤销数量；重复离职或字段校验失败返回 HTTP 400。

编辑、兼容启停和离职操作均在事务中执行最后管理员检查。目标是最后一个在职系统管理员时，停用、离职或降权返回 HTTP 409 和明确保护原因。

`POST /api/admin/staff/restore.php` 使用相同管理权限。请求要求 `restore_date`、`store_id`、`position_id`、`role`、`account_status=active`、`secondary_assignments` 数组和 `restore_reason`；恢复日期必须晚于原离职日期且不能晚于当前日期。兼岗数组允许为空，每项重新提供门店、岗位、角色及可选结束日期。服务在单一事务中恢复员工和 WordPress 账号、递增会话版本并创建新任职，任职冲突返回 HTTP 409，字段或组织校验失败返回 HTTP 400。

`POST /api/admin/staff/purge-check.php` 仅允许总部运营和系统管理员访问，请求通过 `staff_id` 或 `id` 标识员工。响应按身份基线、登录设备、工作量、学习通关、演练审核、通知消息、积分、其他业务和操作人历史返回检查项、状态、原始计数与阻断计数。存在业务关联或检查不完整时返回 `recommendation=offboard` 且不签发令牌；完整零关联时返回 `eligible_for_purge=true`、`recommendation=purge`、关联摘要值以及有效期 5 分钟的 `confirmation_token`。确认令牌绑定当前操作者、目标员工、关联账号、员工会话版本和本次关联摘要，仅供后续受控清理接口校验。

`POST /api/admin/staff/purge.php` 使用相同的总部运营或系统管理员权限。请求要求 `staff_id`、`purge_reason`、`confirmed=true` 和预检返回的 `confirmation_token`，操作人不能清理自己的员工身份或关联账号。服务锁定目标身份后重新检查全部业务关联，并重新校验令牌签名、有效期、操作者、员工、账号、工号摘要、会话版本和关联摘要。关联变化或检查不完整返回 HTTP 409、`recommendation=offboard` 和最新摘要；令牌无效或请求字段错误返回 HTTP 400。成功响应包含被清理的员工、账号标识、各身份表删除数量和关联摘要值。

## 岗位字典接口

`GET|POST /api/admin/organization/positions.php` 由总部运营和系统管理员使用。接口调用 `OrganizationService`，所有写操作在 PDO 事务中完成并记录操作审计。

`GET` 查询参数：

| 参数 | 行为 |
| --- | --- |
| `id` | 返回指定岗位 |
| `status` | `all`、`1` 或 `0`，默认返回全部状态 |
| `keyword` | 按岗位编码或名称模糊查询 |

列表按 `sort_order`、`id` 升序返回。岗位响应包含编码、名称、`applicable_roles`、排序、状态，以及当前员工、当前任职和历史任职引用计数。

`POST` 使用 `action` 区分 `create`、`update` 和 `set_status`。创建与编辑字段包括 `position_code`、`position_name`、`applicable_roles`、`sort_order` 和 `status`；更新与状态变更同时传入 `id`。岗位编码统一转为小写并保持全局唯一，适用角色经统一角色映射规范化和去重。

重复编码返回 HTTP 409 和 `data.conflict_field=position_code`。停用岗位时，当前有效员工或任职引用会返回 HTTP 409 和 `data.reference_summary`。已结束的历史任职继续保留，停用后的岗位无法用于新增员工。

`GET|POST /api/admin/organization/stores.php` 使用相同的权限、动作分发和响应约定。`GET` 支持 `id`、`status`、`keyword`；`POST` 支持 `create`、`update`、`set_status`。门店字段包括 `store_code`、`name`、`manager_staff_id`、`sort_order` 和 `status`，门店编码统一转为大写稳定标识。

负责人允许为空，设置或变更时必须指向当前在职员工。服务同步维护稳定的 `manager_staff_id` 和历史后台仍在读取的 `manager_name`，负责人未变化时保留历史姓名。停用门店前同时检查员工当前快速归属和在职员工的当前有效任职；存在引用时返回 HTTP 409 和 `data.reference_summary`。员工新增、后台批量导入、CLI 导入和旧门店选项入口均只接受启用门店。

## 任职领域服务

`OrganizationService` 提供 `changePrimaryAssignment()`、`createSecondaryAssignment()`、`endSecondaryAssignment()` 和 `getAssignment()`。主岗变更支持复用 `StaffLifecycleService::update()` 已开启的外层事务，使员工资料和组织历史保持原子提交。

主岗变更输入包含员工 ID、`store_id`、`position_id`、`system_role` 或 `role`、`effective_date` 或 `start_date`、`change_reason`。兼岗创建还支持可空 `end_date`，兼岗结束输入结束生效日期和原因。服务只接受在职员工、启用门店、启用岗位及岗位允许的系统角色。

任职区间采用闭区间。同日同内容请求返回 `idempotent=true`；同日不同主岗、同一员工多个覆盖生效日的主岗、相同门店岗位角色的兼岗区间重叠均返回 `OrganizationAssignmentConflictException`。已结束历史任职拒绝修改。主岗变化按当前日期同步员工快速字段，全部写操作在员工级串行事务中保存操作人、变更原因及审计快照。

## 组织架构树

### `GET /api/admin/organization/tree.php`

总部运营和系统管理员可访问。接口读取数据库当前日期，返回当前有效的总部、门店、岗位和员工关系。

`data.tree` 是根节点为 `headquarters` 的树，门店节点保留无任职的启用门店，岗位节点按门店内有效任职生成，员工节点代表具体主岗或兼岗关系。`data.list` 提供 `stores`、`positions` 和 `staff` 三份平铺数据；`data.summary` 提供门店、岗位、去重员工、任职及主兼岗数量。员工节点只输出工号、姓名和任职字段，不输出联系方式、账号及设备安全信息。

`admin/staffs.html` 的组织架构标签调用该接口，并在同一响应上切换树形与列表展示。门店设置和岗位设置分别调用字典接口的 `GET status=all` 与 `POST action=create|update|set_status`。设置抽屉展示 `reference_summary.current_staff_count`、`current_assignment_count` 和 `historical_assignment_count`；停用存在当前引用时，页面展示 HTTP 409 返回的阻断数量。岗位保存要求至少一个 `applicable_roles`，门店负责人候选来自当前在职员工目录。

专题设计中的离职、恢复、门店、任职变更和资料更正接口继续按 `.monkeycode/specs/2026-07-24-workload-governance-mini-program-launch/design.md` 分任务实施。

## 岗位工作量标准接口

岗位标准管理写接口要求 `workload.standard_manage` 权限和 `Idempotency-Key`。总部运营与系统管理员可查询版本，系统管理员具备规则配置能力；所有写入在事务中保存操作审计。

| 方法 | 路径 | 行为 |
| --- | --- | --- |
| `GET|POST` | `/api/admin/workload/standards.php` | 查询版本和详情，创建或更新草稿基本信息 |
| `POST` | `/api/admin/workload/standard-items.php` | 增加、编辑、排序或移除草稿项目 |
| `POST` | `/api/admin/workload/standard-copy.php` | 将已发布版本复制为新草稿并返回项目差异 |
| `POST` | `/api/admin/workload/standard-delete.php` | 删除未发布且未被日报引用的草稿 |
| `POST` | `/api/admin/workload/standard-publish.php` | 校验岗位、项目和版本区间，创建独立模板并按日期发布 |
| `POST` | `/api/admin/workload/standard-disable.php` | 缩短已发布版本截止日期并保留历史绑定 |
| `POST` | `/api/admin/workload/standard-import.php` | 上传 CSV/XLSX，创建逐行预检和岗位差异批次 |
| `GET|POST` | `/api/admin/workload/standard-import-batches.php` | 查询导入批次，确认生成岗位草稿并可按生效日期发布 |

项目规则字段包括编码、名称、单位、值类型、必填、允许零值、数值范围、目标值、凭证数量、审核方式、统计方向和排序。发布与停用响应返回受影响版本和 `cache_invalidation_scope`；重复幂等请求返回首次成功结果。

导入文件最大 5MB、最多 10000 行，接受 `.csv` 与 `.xlsx`。标准表头为 `role_code`、`metric_code`、`metric_name`、`unit`、`is_required`、`allow_zero`、`min_value`、`max_value`、`target_value`、`need_evidence`、`min_evidence_count`、`max_evidence_count`、`audit_mode`、`statistic_direction` 和 `sort_order`，同时接受对应中文名称；`value_type` 为可选字段，缺省为 `number`。预检响应的 `summary.roles` 按岗位返回 `added`、`modified`、`disabled`、`unchanged`、`error_rows`、`can_confirm` 和目标版本，`rows` 保留原始行号、规范字段、差异动作和错误列表。

确认请求提交 `batch_id`、`action=confirm`、可选 `effective_from`、`publish`，以及按岗位映射的 `minimum_positive_metrics` 和 `requires_daily_report`。无错误岗位在一个事务中创建规则与项目草稿；错误岗位保留在批次中。`publish=true` 时逐岗位调用标准发布事务，批次状态为 `published` 或 `partially_published`，后者可继续重试未发布草稿。

## 日报状态接口

`GET /api/workload/my-report.php` 按 `report_date`、`store_id` 和规范角色读取当前员工日报。响应在既有 `report`、`values` 基础上增加 `obligation`、`completion_status`、`deadline_at`、`is_writable`、`audit_tasks`、`needs_resubmit_count`、`pending_items` 和 `is_weekly_rest_day`。审核任务包含审核意见、当前凭证数、审核时凭证数、补充状态和 `required_action`；动作值包括 `await_review`、`review_rejection`、`supplement_evidence`、`request_reaudit` 和 `none`。已过次日 `00:00:00` 的 `missing` 或 `draft` 会立即显示为 `locked_missing`。

`POST /api/workload/save-report.php` 保存当前员工的 `draft` 或 `submitted` 日报。日报、项目值、审核任务和义务在同一事务中写入，事务前后均以数据库时间校验上海业务截止点。保存链路按岗位和业务日期解析规则版本，校验最低正数项目数、岗位必填、零值、数值范围和凭证数量，并将版本 ID 绑定到日报。成功响应包含 `report_id`、`submit_status`、`obligation_id`、`completion_status`、`deadline_at`、`metric_version` 和 `rule_version`；已提交、周一公休日、未来日期或已锁定日报返回业务错误。

`POST /api/workload/correct-report.php` 接受 `report_id`、`values`、`correction_reason` 和可选 `remarks`。总部全量管理角色可处理全部门店，店长可处理所属门店。服务仅处理已到截止时间的既有日报，并在事务中保存更正前后快照、原因、操作人及 `corrected` 义务状态；当前审核任务同步进入 `superseded`，更正后的全量审核正值创建递增版本的 `pending` 后继任务，审核日志记录真实管理操作人。

经营统计接口默认只聚合 `workload_source_policies.included_by_default=1` 的日报，并在响应中返回当前纳入统计的来源代码。员工本人日报、凭证、提醒、历史回填和关联清理保留全来源可见性，便于合成数据审计与生命周期管理。日报保存的 `source` 必须已登记在来源策略表中。

经营统计接口统一返回 `metric_version`、`metric_version_id`、`generated_at`、`filters`、`source_scope` 和 `metric_policy`。保存日报时响应返回绑定的 `metric_version`，数据库记录保存对应 `metric_version_id`；统计查询审计记录携带相同版本编码和 ID。

`real_sync/api/workload/services/WorkloadAnalyticsQueryService.php` 提供统计端点和导出复用的内部查询契约。输入支持 `date_from`、`date_to`、`store_ids`、`role_codes`、`staff_ids`、`metric_codes`、`report_statuses`、`audit_statuses` 和 `sources`，单值参数同时接受对应单数字段；日期范围上限为 366 天，列表上限为 200 项。缺省来源使用来源策略中的默认经营来源，显式来源逐项校验登记状态。权限结果分为 `all`、`stores` 和 `staff`：总部运营与系统管理员读取全量，店长读取当前有效管理任职覆盖的门店，普通员工固定本人。事实响应以日期、门店、员工、岗位和项目为唯一粒度，包含日报 ID、名称字段、日报状态、审核状态、来源、凭证数及四值。

内部 `statistics()` 契约在事实响应上按项目聚合已提交日报。每个 `metrics` 项返回 `sample_size`、已提交日报/员工/门店数、原始正数/有效正数/原始零值日报数、四值总量和 `low_sample`。`selection_rate`、`effective_selection_rate`、`zero_rate`、`staff_coverage`、`store_coverage` 以及三类均值都使用 `{numerator, denominator, value}` 结构；比例保留 4 位小数，均值与总值保留 2 位小数，分母为零时值为 `0.0`。全体员工人均、参与员工人均和每应交人日均值统一使用有效值总量作为分子；应交人日由具体统计调用方提供，负数输入返回业务校验错误。低样本门槛为 10 份已提交日报和 3 名已提交员工。顶层同时返回 `metric_version`、`metric_version_id`、`generated_at`、`data_cutoff_at`、`filters`、`source_scope`、`metric_policy` 和 `permission_scope`。

统计过滤与守恒契约测试锁定以下行为：全部筛选条件采用交集语义；组织条件读取日报的 `store_id` 与 `role_code` 历史快照；缺省经营范围排除合成来源；`pending`、`approved`、`rejected`、`needs_resubmit` 和 `not_required` 保持各自四值映射；日报数、正数与零值计数及四值总量在互斥门店分区前后保持可加守恒；重复事实按日报 ID 去重。

`GET /api/workload/analytics/store-completion.php` 提供门店周期完成和门店项目矩阵。请求复用统一统计筛选，主要参数为 `date_from`、`date_to`、`store_id|store_ids`、`role_code|role_codes` 和 `source|sources`；缺省日期为当天，最大范围为 366 天。接口只接受 GET，请求必须具备员工上下文，并将筛选与本人、店长授权门店或总部全量权限取交集。

响应顶层包含统一口径元数据、`data_cutoff_at`、`permission_scope` 和 `period`。`period.calendar` 逐日标识 `business_day` 或 `weekly_rest_day`。`store_summaries` 和 `daily_trend` 返回应交数、完成数、五类 `status_counts`、排除来源数和 `{numerator, denominator, value}` 完成率；完成分子包含 `submitted` 与 `corrected`。`status_details` 返回日期、门店、员工、岗位、存储状态、经营口径状态、日报来源、来源范围标志和 `drilldown_token`。来源范围之外的已关联日报在经营口径状态中按 `missing` 统计，并通过 `stored_completion_status` 与 `source_in_scope=false` 保留审计可见性。

`store_metric_matrix` 按门店、岗位和项目返回样本量、四值总量、项目比例、低样本标志、完成率、岗位应交人日均值、义务员工人均值、提交员工人均值、义务员工覆盖率、提交员工覆盖率、`effective_value_rank` 和 `raw_value_rank`。`staff_rows` 包含该门店岗位全部应交员工的项目单元格；员工或项目没有已提交事实时仍返回零值结构。门店矩阵的每应交人日分母使用该门店与项目所属岗位的应交义务数，排名按项目在可见门店范围内独立计算。

`GET /api/workload/analytics/metric-selection.php` 提供项目选取、覆盖和排名分析。接口只接受 GET，使用与统一统计内核相同的日期、门店、岗位、员工、项目、日报状态、审核状态和来源筛选，并与员工本人、店长授权门店或总部全量权限取交集。响应顶层包含统一口径元数据、`data_cutoff_at`、`permission_scope`、`project_summaries`、`store_rankings` 和 `staff_rankings`。

`project_summaries` 按 `role_code + metric_code` 返回样本量、应交人日、四值、选取率、有效选取率、零值率、员工覆盖率、门店覆盖率、三类有效值均值、低样本状态，以及门店覆盖、员工覆盖、有效值和原始值排名。`store_rankings` 按门店、岗位和项目返回同一聚合口径，并增加 `effective_value_rank`、`raw_value_rank`、`staff_coverage_rank`、`all_store_effective_average`、`all_store_raw_average` 和 `top_quartile_effective_reference`。`staff_rankings` 返回门店内有效值与原始值排名、全部可见门店同岗位有效值与原始值排名、门店同岗位有效值均值和全部门店同岗位有效值均值。相同数值共享密集排名，项目、门店或应交员工没有已提交事实时仍返回零值行；存在待审核事实的行通过 `has_pending_review=true` 提示解释风险。

`GET /api/workload/analytics/staff-profile.php` 提供员工完整周期画像。必填参数为正整数 `staff_id`，日期和来源等筛选沿用统一统计内核，`granularity` 支持 `day`、`week` 和 `month`，缺省为 `day`。接口只接受 GET，并将目标员工与员工本人、店长授权门店或总部全量权限进行校验；店长可读取当前归属授权门店或所选周期内存在授权门店历史义务的员工。

响应顶层包含统一口径元数据、`permission_scope`、员工当前基础身份、周期、`summary`、`daily_records`、`trend`、`comparison` 和 `rankings`。`daily_records` 按业务日期、历史门店和历史岗位合并义务与日报，返回应交类型、原因、完成状态、截止时间、备注、提交时间和全部适用项目；每个项目包含原始值、待审核值、有效值、驳回值、凭证列表、当前审核任务、审核状态和审核意见，无日报或无项目事实时保留零值行。`trend` 按所选粒度返回周期起止和项目聚合。

`comparison` 以所选周期内周二至周日的营业日数量为基准，向前解析上期及过去四个等营业日周期。项目行返回本期值、上期值、变化量、变化率、`comparable|new|flat|down_to_zero` 状态、过去四期均值、本期和上期样本量及低样本标志。`rankings` 复用项目选取服务，返回目标员工在历史门店同岗位及全部可见门店同岗位的有效值和原始值排名、对应均值及员工有效值前 25% 参考。

`WorkloadBusinessPeriodService::resolve()` 接受 `period_type`，可选值为 `day`、`business_week`、`month_to_date`、`full_month`、`quarter` 和 `custom`。日、业务周、月累计、完整月和季度使用 `anchor_date|date` 定位周期；自定义周期使用 `date_from` 与 `date_to`，范围上限为 366 天。返回值包含 `current_period`、`previous_period`、`comparison_current_period`、`comparison_previous_period` 和 `alignment`；每个周期披露起止日期、排除周一后的 `business_dates` 与 `business_day_count`，比较周期按两期较少营业日数截齐。

`WorkloadComparisonService::compare()` 接受本期值、上期值、两期样本数、两期低样本状态和最多四个历史值。上期大于零时返回变化率及 `comparable`，本期降为零时返回 `down_to_zero`；上期为零时变化率为 `null`，状态为 `new` 或 `flat`。`average()` 返回 `{numerator, denominator, value}`，`topQuartileReference()` 返回可见集合降序排列后的前 25% 边界值，`benchmarks()` 组合样本数、均值和前 25% 参考值。

`GET /api/workload/analytics/cross-analysis.php` 提供通用二维交叉分析。`primary_dimension` 与 `secondary_dimension` 支持 `store`、`metric|project`、`staff` 和 `time`，两者必须互异；`time_granularity` 支持 `day`、`business_week`、`month` 和 `quarter`。接口接受统一统计筛选，也可通过 `period_type`、`anchor_date|date` 或自定义日期解析营业日周期。响应包含统一口径元数据、权限范围、周期、全局汇总和 `matrix`；每个单元返回两个维度值、四值、应交与完成人日、样本、低样本、完成率、义务员工覆盖率、选取率、每应交人日均值、有效值排名及 `/api/workload/analytics/metric-detail.php` 下钻参数。

周期交叉集成测试覆盖周一、跨月、跨季度、低样本、上期为零和历史门店任职快照，并验证互不重叠的门店员工单元聚合后等于最细事实与义务总量。

正确性属性 16 的属性测试以 `WorkloadBusinessPeriodService` 的 `comparison_current_period` 和 `comparison_previous_period` 为断言对象，随机验证两侧 `business_day_count` 相等且所有日期均排除周一。

正确性属性 17 的属性测试覆盖门店、项目、员工和时间四类维度的全部互异组合，验证 `matrix` 单元四值合计与最细事实记录聚合一致；重复的 `report_id:metric_code` 事实键保持幂等。

工作量接口响应中的 `permission_scope` 统一包含 `scope_type`、`store_ids`、`staff_id`、`ranking_scope` 和 `can_manage_configuration`。`ranking_scope` 取 `self`、`stores` 或 `all`，用于后续统计、排名、下钻和导出范围控制。

`GET /api/workload/analytics/metric-detail.php` 复用统计过滤器和权限事实查询，支持日期、门店、岗位、员工、指标、日报状态、审核状态和来源筛选，并返回分页明细、`permission_scope` 与口径元数据。导出权限通过同一 `permission_scope.can_export` 契约传递给后续导出入口。

权限矩阵测试覆盖本人范围、授权单店、多店范围、总部全量和管理员全量；排名范围分别为 `self`、`stores` 和 `all`。

属性测试进一步验证权限响应中的 `scope_type`、`store_ids`、`staff_id` 和 `ranking_scope` 共同决定事实可见范围，`can_export` 保持在授权范围内可用。

`POST /api/workload/exports.php` 当前支持 `store_completion` 和 `metric_selection`。请求体包含 `export_type` 及对应统计筛选条件，响应为带 UTF-8 BOM 的 CSV 下载；文件前置元数据包含导出类型、生成时间、统计口径版本、查询条件和字段说明。响应头包含 `X-Export-Row-Count`，导出完成写入审计事件。

同一接口支持 `staff_full_data` 和 `metric_full_dimension`，两类明细导出复用事实筛选，输出原始值、待审核值、有效值、拒绝值、日报状态、审核状态、凭证数量及来源；项目全维度额外输出门店、员工、岗位和项目名称字段。

导出结果超过 20,000 行时，`POST /api/workload/exports.php` 返回 HTTP 202 和 `job_id`。`GET /api/workload/exports.php?id={job_id}` 查询任务状态；增加 `download=1` 下载已完成且未过期的文件。查询与下载均要求当前员工为任务发起人，且当前权限范围哈希与发起时一致。

`GET /api/workload/analytics/operating-funnel.php` 提供销售过程漏斗和教练计划完成率。接口只接受 GET，日期、门店、岗位、员工、日报状态、审核状态和来源筛选复用统一统计内核，并与本人、店长授权门店或总部全量权限取交集。项目筛选在该端点中固定展开为漏斗及关系版本所需的全部项目，响应顶层包含统一口径元数据、`permission_scope` 和按 `date_to` 生效的 `relation_version`。

`sales_funnel.stages` 固定返回新增资源、实际邀约、实际到店、成交人数和新签金额，每个阶段包含原始值、待审核值、有效值、驳回值、样本量和低样本状态。`sales_funnel.conversion_rates` 返回资源邀约、邀约到店和到店成交关系；`coach_plan_completion.rates` 返回耗课和沟通计划完成率。每个关系包含分子、分母项目四值、`effective_rate`、`raw_rate`、关系样本量、低样本状态和待审核标志。比率结构为 `{numerator, denominator, value, state}`，分母大于零时状态为 `comparable`，分母为零且分子为正时为 `new`，两者均为零时为 `empty`。`configured_conversions` 返回当前关系版本中属于通用转化分组的后续配置项。

门店周期完成接口的正确性属性 4 规定：相同日期、门店、员工、岗位和完成状态筛选下，每个门店的应交数等于 `required_status=required` 的义务明细数量，门店应交数之和等于全部下钻明细数量。周一等 `exempt` 义务单元排除在应交分母之外。该属性已建立测试门禁并由门店周期接口使用。

门店周期完成接口的正确性属性 5 规定：相同日期、门店、员工和岗位范围内，`missing`、`draft`、`submitted`、`locked_missing` 与 `corrected` 五类完成状态数量之和等于 `required_status=required` 的应交义务数量。每个应交义务只贡献一个状态计数，`exempt` 不进入应交分母。该属性已建立随机状态转换与组合范围测试门禁并应用于外部统计响应。

项目选取统计的正确性属性 6 规定：每个指标的 `selection_rate.numerator` 小于或等于 `selection_rate.denominator`。分母为按 `report_id` 去重后的已提交日报数，分子为其中两位小数舍入后原始值大于零的日报数；草稿、无效事实和同指标重复日报不增加计数。零分母返回 `0.0`，非零选取率保持在 `0` 至 `1` 范围内。

`GET /api/workload/dashboard.php`、`GET /api/workload/hq-summary.php`、`GET /api/workload/store-summary.php`、`GET /api/workload/staff-detail.php`、`GET /api/workload/staff-activity.php` 和 `GET /api/admin/workload/summary.php` 的项目值统一返回 `raw_value`、`pending_value` 与 `effective_value`。聚合行分别表示原始总值、全量审核待处理总值和已审核有效总值；历史兼容字段 `metric_value`、`value`、`score` 与 `score_total` 表示有效值，明细中的 `numeric_value` 继续表示员工填报原值。后台汇总的 `summary`、`by_role`、`by_staff` 和日报列表同步披露三值。

`GET /api/workload/template.php` 接受可选 `role` 和 `date`，按业务日期返回生效的 `rule_version`、`minimum_positive_metrics` 以及项目级 `required`、`allow_zero`、`min_value`、`max_value`、凭证上下限和 `audit_mode`。凭证上传与待补凭证读取日报已经绑定的 `rule_version_id`；未绑定的历史日报按日报日期和岗位确定规则。

`GET /api/workload/audit-list.php` 要求总部全量管理角色或店长门店范围。接口支持日期、门店、员工、岗位、项目、`status`、`include_history`、`page` 和 `page_size`；默认只返回 `superseded_at IS NULL` 的当前任务，传入 `include_history=1` 时包含历史版本。店长请求固定与当前有效授权门店取交集。历史版本通过最后一条 `after_status=superseded` 的审核日志恢复废止前状态并返回 `trace_status`；`status=pending|approved|rejected|needs_resubmit` 按追溯状态筛选，`status=superseded` 按存储状态筛选。每条记录返回凭证 URL、`task_version`、`previous_task_id`、`superseded_at`、`trace_status`、四值和按时间排序的 `audit_logs`；顶层 `pagination` 返回准确总数与总页数。

`POST /api/workload/audit-action.php` 接受 `task_id`、`action` 和可选 `comment`，其中 `action` 支持 `approved`、`rejected`、`needs_resubmit`。接口使用当前认证员工身份和门店权限，事务中锁定审核任务并写入审核日志；已 `superseded` 的历史任务返回冲突错误。

`GET|POST /api/workload/alerts.php` 提供预警管理闭环。GET 支持日期、门店、员工、岗位、项目、状态、级别、规则、来源、分页筛选，并与总部全量或店长授权门店范围取交集；每条记录通过 `evidence` 披露结构化事实证据，并返回规则依据、影响范围、`handler_comment` 处理意见和来源范围。POST 接受 `event_id` 和最多 500 字 `comment`，`action=resolve` 为默认动作；接口只处理开放事件，在事务中锁定记录、保存处理人和时间、写入 `alert.resolve` 操作审计并按日期、门店、员工、岗位和项目失效统计缓存，已处理事件返回 `idempotent=true`。

`POST /api/workload/evidence-upload.php` 对草稿日报沿用原凭证上传规则。已提交日报仅允许日报所有者为当前 `needs_resubmit` 审核任务补充对应项目凭证；接口在同一事务中完成所有权检查、日报与审核任务锁定、凭证上限复核和记录写入。

`POST /api/workload/audit-resubmit.php` 接受 `task_id`，并从认证上下文读取员工身份。接口只处理该员工本人当前 `needs_resubmit` 任务，要求凭证数量高于审核时基线且满足日报绑定岗位规则；成功后将旧任务标记为 `superseded`，创建 `task_version + 1` 的 `pending` 后继任务并写入审核日志。重复请求返回同一个当前后继任务，响应通过 `idempotent` 标识幂等结果。

## 小程序请求层

`real_sync/mini-program/utils/api.js` 是小程序业务代码的统一网络入口。`request()`、`get()`、`post()` 和 `uploadFile()` 为每次操作附加 `X-Request-ID`；调用方通过 `idempotencyKey` 发送 `Idempotency-Key`，通过 `stateVersion` 和可选 `stateVersionField` 注入乐观锁版本。普通请求默认超时 15 秒，上传默认超时 60 秒。HTTP、业务、网络、超时、冲突和上传协议错误转换为包含 `statusCode`、`code`、`category`、`requestId`、`url` 与 `retryable` 的错误；409 错误同时暴露基础版本、当前版本、权威状态和恢复动作。

受认证请求和上传共享设备会话单飞刷新队列，401 最多重放一次；本地无 Token 时直接进入 `reauthentication` 且不调用网络 API。登录与微信或企业微信绑定通过 `auth=false` 跳过旧 Token 和刷新流程。上传使用调用方提供的 64 位 SHA-256，或通过 `wx.getFileInfo` 计算摘要，并以 `file_sha256` 连同状态版本写入表单。`real_sync/mini-program/utils/auth.js` 兼容 `token` 与 `jwt_token` 两个历史本地存储键；不完整设备会话会清理旧刷新凭据，刷新失败统一进入 `reauthentication`。

积分聚合页使用 `GET /api/points/index.php` 读取积分概览，使用 `GET /api/points/ranking.php?limit=20` 读取权威排行，使用 `GET|POST /api/points/exchange.php` 读取商品并提交兑换，使用 `POST /api/points/checkin.php` 完成每日签到。排行接口要求登录，`limit` 限制在 1 至 100，按 `accumulated_points DESC, user_id ASC` 返回稳定排名，并附带当前用户的累计积分、可用积分和名次。兑换与签到通过统一请求层发送 `Idempotency-Key`，页面失败重试复用首次操作键；冲突响应进入明确刷新动作。

## Worker 接口

平台异步任务统一由以下命令消费：

```bash
php scripts/platform-job-worker.php
```

Worker 从 `platform_jobs` 领取任务，通过 `api/platform/jobs/registry.php` 分派 Handler，并在标准输出写入包含领取、完成、重试、dead-letter 和空闲状态的 JSON 运行摘要。每次 Handler 执行携带 `job_id`、`worker_id` 与 `fencing_token`；租约丢失立即以 `job_lease_lost` 中止当前提交。

| `job_type` | 入队 Adapter | Handler |
| --- | --- | --- |
| `reminder.schedule.tick` | `api/reminder/reminder-worker.php [date] [phase]` | `ReminderJobHandler` |
| `wecom.members.sync` | `api/wecom/sync-worker.php [root_department_id]` | `WecomJobHandler` |
| `skill.review.process` | `api/skill/upload-recording.php` 与 `api/skill/skill-worker.php` | `SkillReviewJobHandler` |
| `drill.governance.expire_audio` | `scripts/drill-governance-worker.php --apply` | `DrillGovernanceJobHandler` |

所有 Adapter 在已开启的 PDO 事务内调用 `PlatformJobQueue::enqueue()`，使用稳定幂等键、规范 JSON 和 SHA-256 `payload_hash`。提醒 Adapter 保留上海业务日期与五阶段契约；企微 Adapter 保留根部门参数；技能补捞每次选择最早一条待处理记录；演练治理默认 dry-run 同步输出预览。部署时在受控环境中分别配置入队 Cron 与统一 dispatcher，并记录实际命令、频率和日志位置。

企业微信同步继续写入 `wecom_sync_logs`，空成员响应触发保护性失败。技能 Handler 保留 `pending → transcribing → analyzing → completed|failed` 状态语义，并从部署根目录解析录音和 SKILL 路径。

工作量义务 Worker 命令：

```bash
# 生成上海当天义务
php api/workload/obligation-worker.php

# 生成指定业务日期义务
php api/workload/obligation-worker.php 2026-07-28
```

成功时标准输出以 `[workload.obligation]` 开头，后接 JSON 汇总。周一返回 `day_type=weekly_rest_day` 和零应交数；周二至周日返回 `day_type=business_day`。服务按业务日期任职、员工生命周期、门店状态、岗位状态和规范角色筛选候选，并通过日报义务唯一键重复执行。

历史义务回填命令：

```bash
# 回填一个已结束业务日期
php api/workload/obligation-backfill-worker.php 2026-07-28

# 回填闭区间内的已结束业务日期
php api/workload/obligation-backfill-worker.php 2026-07-01 2026-07-28
```

成功时标准输出以 `[workload.obligation-backfill]` 开头，后接 JSON 汇总。参数使用上海业务日期，结束日期必须早于当天。服务优先绑定 `workload_daily_reports` 保存的门店和角色快照，再使用 `staff_assignments` 生效区间补齐销售、教练的可确认历史义务。报告快照、任职补建和幂等更新共享同一事务，补建来源为 `backfill`，已更正义务保持原状态。

日报义务锁定命令：

```bash
# 使用数据库当前时间锁定到期义务
php api/workload/obligation-lock-worker.php

# 使用指定上海时间执行可复现锁定
php api/workload/obligation-lock-worker.php "2026-07-29 00:00:00"
```

成功时标准输出以 `[workload.obligation-lock]` 开头，后接 `locked_at`、`locked_missing_count`、`locked_draft_count` 和 `locked_count`。服务仅更新 `required` 且状态为 `missing` 或 `draft` 的到期义务。

## 数据库接口

## 工作量预警与质量门禁

- `api/workload/alert-worker.php`：CLI 幂等生成草稿、缺交、锁定缺交、审核积压和经营建议事件，记录运行结果并隔离通知失败。
- `scripts/check_miniprogram_routes.mjs`：校验 `app.json` 注册页面、页面基础文件及 JavaScript/WXML 固定路由。
- `scripts/check_miniprogram_contracts.mjs`：聚合页面注册、导航与 Tab 清单、统一请求层、设备会话、状态同步、统一上传和能力版本七类静态契约，并以 `mini_program_contracts` 接入平台预检。
- `scripts/check_miniprogram_release.mjs`：校验普通微信小程序域名能力、隐私声明和构建配置；微信后台域名与真机行为继续人工验收。
- `scripts/platform_function_coverage.mjs`：声明全部 89 个稳定功能 ID 的端面、生命周期、目标生命周期、可执行项、自动测试、静态证据、生产路径和发布验证状态。默认执行结构与证据检查；`--run-local` 去重运行关联的 Node 测试并输出覆盖组数、测试文件数及通过、失败、跳过计数。数量、ID、生命周期或证据漂移以非零退出码 fail closed。
- `scripts/platform_preflight.mjs`：聚合 inventory、`function_coverage`、契约快照、小程序路由、小程序契约、PWA 和冻结路径检查。输出 `checks` 与 `metrics`；覆盖指标包含 89 个功能组、46 个测试文件、当前与目标生命周期统计及发布验证状态统计。
- `scripts/platform_regression_preflight.mjs`：读取 `platform_regression_preflight.config.json` 并执行 19 个发布前阶段。JSON 报告包含总状态、退出码、阻断阶段、外部阻断、待审批项、波次 0 至 6 证据，以及每阶段名称、命令、耗时、状态和摘要；关键本地失败 fail closed，数据库 readiness 的明确环境缺失归为 `blocked_external`，DevTools 摘要列出缺失条件代码，生产发布归为 `approval_required`。
- `scripts/verify-workload-governance.mjs`：串联 PHP 语法、迁移、Node 契约、属性、权限、前端和小程序门禁；任务 28.1 完整验收检查 144 个 PHP 文件并通过 106 个 Node 测试文件。

历史入口治理管理 API：

- `GET api/admin/platform/legacy-endpoints.php`：要求 `legacy_endpoint.view`，按入口、消费者、业务域和迁移状态查询调用聚合、观察窗和退役 blocker。
- `POST api/admin/platform/legacy-endpoint-status.php`：要求 `legacy_endpoint.manage`，更新迁移状态、owner、替代入口和观察截止条件；进入 `deprecated` 前执行完整退役判断。
- `POST api/admin/platform/legacy-endpoint-retirement-submit.php`：要求 `legacy_endpoint.retirement_submit`，提交回滚计划和契约回归、消费者清单、替代入口健康、回滚演练四项证据。
- `POST api/admin/platform/legacy-endpoint-retirement-approve.php`：要求 `legacy_endpoint.retirement_approve`，由提交人之外的员工批准；并发审批通过行锁和状态条件收敛为单一终态。

治理数据由 `platform_legacy_endpoints`、`platform_legacy_endpoint_invocations`、`platform_legacy_endpoint_retirement_approvals` 和 `platform_legacy_endpoint_audit_events` 四表保存。调用记录使用请求 ID 与入口维度生成幂等键；统计写入异常保持业务响应可用。退役 blocker 包含迁移状态、观察窗、窗口调用量、替代入口、owner、审批、回滚计划和证据完整性，schema 差异统一返回 `schema_not_ready`。

当前版本化迁移为：

```text
real_sync/database/migrations/202607240001_staff_organization.sql
real_sync/database/migrations/202607240002_workload_governance.sql
real_sync/database/migrations/202607240003_admin_operation_audit.sql
real_sync/database/migrations/202607240004_staff_employee_number_sequence.sql
real_sync/database/migrations/202607240005_workload_audit_task_history.sql
real_sync/database/migrations/202607240006_workload_audit_resubmission.sql
real_sync/database/migrations/202607240007_workload_metric_relations.sql
real_sync/database/migrations/202608210004_drill_persona_five_dimensions.sql
```

员工组织迁移要求既有 `staffs`、`stores` 表，并新增岗位、任职、导入和资料更正结构。执行前应检查员工工号、`user_id` 和门店编码重复数据，因为迁移会为这些字段建立唯一索引。

工作量治理迁移要求既有工作量日报、指标、模板和审核表。它新增日报义务、来源策略、口径版本、岗位规则版本、预警、导出和管理更正结构，并为现有日报、指标值和审核任务补充统计索引。迁移内的初始历史义务范围仅包含已存在日报；运行时回填服务再按明确的历史任职区间补齐可确认缺交。

操作审计迁移创建 `admin_operation_logs`，员工新增服务依赖该表记录脱敏审计。迁移 CLI `real_sync/scripts/migrate.php` 支持 `apply`、`status`、`compatibility`、`readiness`、`verify`、`rollback-plan` 和 `--dry-run`。`compatibility` 返回 `compatible`、`checked_versions`、`issues` 和固定策略名 `expand-migrate-contract`；问题类型覆盖 checksum 漂移、字段删除或重命名、不安全新增字段、N/N-1 契约缺失、状态降级缺失和功能开关缺失。`apply` 执行前运行兼容门禁，`readiness` 依次核对兼容声明、56 个迁移的结构清单和数据检查，任一差异以非零退出码阻止批次。Admin 身份审计、企微、提醒、技能、周年活动和暑期评估端点通过 `platformRequireMigrationReadiness()` 检查各自依赖的 `202607310005` 至 `202607310009`；统一任务入口继续检查 `202607310010` 至 `202607310012`，文件服务与 AI 摘要迁移分别登记为 `202607310013`、`202607310014`。工作量完整 readiness 额外检查 `202608020001`，招聘录用闭环依赖 `202608020002`，历史入口治理依赖 `202608020003`；五维销售画像种子依赖 `202608210004`，缺失或停用任一预期值时 readiness 返回差异。结构缺失时返回 `503/schema_not_ready`，请求和 Worker 均保持 fail closed。

迁移重放 CLI `real_sync/scripts/migration-replay.php` 支持 `dry-run`、`verify` 和 `rollback-plan`。数据库模式要求 `--since=DATETIME`，可选 `--until=DATETIME` 与 `--limit=1..10000`；固定证据和 CI 可使用 `--stdin` 输入 JSON。输出契约版本为 `migration-replay-evidence/v1`，包含稳定 `evidence_id`、时间窗、来源状态、汇总、阻断问题和建议重放动作，所有模式固定返回 `mutations_applied=false`；存在阻断差异时进程退出码为 1。证据来源以 `platform_sync_changes` 为必需业务日志，已部署对应结构时读取 `platform_outbox_events` 和 `platform_side_effect_receipts`。

`platformRequireMigrationReadiness(PDO $db, array $versions)` 是平台端点的轻量启动门禁。它只查询迁移历史和目标结构，成功返回已检查版本与已验证兼容声明；失败抛出 `503/schema_not_ready`，公开数据仅包含 `version`、`type` 和可选 `target`。会话刷新、小程序设备会话和平台同步分别声明自身目标版本，小程序端点仅在真实 401 会话错误中返回 `reauthentication_required`。

工号序列迁移创建 `staff_employee_number_sequences`。员工创建事务锁定对应前缀的序列行，跳过历史已占用工号，更新序列值后继续创建账号和员工档案。

审核历史迁移保留现有审核任务，并增加任务版本、前序任务和废止时间字段。重新提交通过新建版本保留旧任务及审核意见；当前积压查询使用 `superseded_at` 过滤历史版本。

审核重审迁移增加 `evidence_count_at_review` 字段，用于保存进入 `needs_resubmit` 时的凭证数量，并为新增凭证校验提供稳定基线。

项目关系迁移创建版本头和关系明细表，使用生效日期闭区间选择经营关系版本，并以版本、关系编码唯一键保存销售漏斗和教练计划完成关系。初始版本包含三段销售转化率及耗课、沟通两项计划完成率。

迁移完成后的关键查询索引：

- `staffs.employee_no` 唯一索引
- `staffs.user_id` 唯一索引
- `stores.store_code` 唯一索引
- `organization_positions.position_code` 唯一索引
- `staff_assignments` 按员工、门店、岗位和生效区间建立索引
- 导入批次键及批次行号唯一索引
- 日报义务按日期、门店、员工和岗位建立唯一索引
- 来源统计、员工来源、日报版本、指标聚合和审核积压查询索引
- 审核任务按日报项目和版本查询、前序版本追溯及当前积压过滤索引
- 预警事件按规则和统计范围建立幂等唯一索引
- 导出任务按任务键建立唯一索引，并按发起人、状态和过期时间查询

## 敏感数据

手机号、OpenID、企业微信成员标识、JWT、密码和外部服务凭据均属于敏感数据。接口日志、操作审计和错误响应需要执行字段脱敏，配置示例统一使用占位符。
