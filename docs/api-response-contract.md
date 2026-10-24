# API 响应契约（2026-10-24 第二轮起生效）

全部 `/api/*.php` 端点统一遵守以下响应契约。本文档是第二轮错误码治理（任务卡 A0）的产出，后续新增/修改端点必须对照本表。

## 响应体

```json
{
  "code": 0,
  "message": "success",
  "data": {}
}
```

新内核（`platformApiContext` 系列）端点的响应额外携带 `request_id` 与 `meta` 字段，属同一契约的超集：

```json
{
  "code": 0,
  "message": "success",
  "data": {},
  "request_id": "20261024120000-ab12cd34",
  "meta": { "api_kernel_version": "...", "endpoint_version": "..." }
}
```

## 状态码对照表

| 场景 | code | HTTP 状态 | data | 说明 |
|---|---|---|---|---|
| 成功 | 0 | 200 | 业务数据 | |
| 业务/校验拒绝 | 1 | 400 | null | 用户输入错、状态不允许等客户端可修复的问题 |
| 未登录 | 401 | 401 | null | |
| 无权限 | 403 | 403 | null | |
| 资源不存在 | 404 | 404 | null | |
| 冲突 | 409 | 409 | null | 乐观锁/幂等键冲突/重复提交 |
| 限流 | 429 | 429 | null | 如登录防暴力 |
| 服务器错误 | 500 | 500 | null | catch 兜底；消息不得含堆栈/SQL/内部路径 |

## 补充规则

1. **服务器错误消息**统一为"XX失败，请稍后重试"或既有的等价文案；禁止把异常消息（`$e->getMessage()`）直接回显给客户端——异常细节走 `error_log`。
2. 新内核端点抛异常代替直接输出：业务拒绝抛 `new PlatformApiException(400, '语义码', '消息')`，由 `PlatformExceptionMapper` 统一映射。
3. **迁移期兼容**：`jsonSuccess()` / `jsonError()` / `appJsonSuccess()` / `appJsonError()` 是 `jsonResponse()` 的别名，继续可用；旧别名 `json_response()` 已于第二轮移除，调用点全部收敛为 `jsonResponse()`。
4. 同一文件内保持一种响应风格（要么全部 `jsonResponse` 系，要么全部异常式），不混用。
5. 本轮治理不改变任何端点的 URL、请求参数与 `data` 内部结构；前端零改动是硬约束。

## 范本

- 新内核式端点：`real_sync/api/knowledge/list.php`
- 旧式端点（合法，风格统一即可）：`real_sync/api/workload/save-report.php`
