# 架构稳定性审计与收口方案（2026-06-12）

## 目标

本次审计面向追光小牛企业内网的稳定性与可延展性，重点覆盖认证、API 响应协议、H5 请求封装、小程序请求封装、权限上下文、缓存版本、部署回滚和新功能接入边界。

## 当前结论

系统主体仍可运行，但公共基础层存在局部协议分叉。近期制度中心重新登录问题暴露出三个基础风险：

- H5 页面存在多套认证入口：`internal-auth.js`、`js/app-auth.js`、页面内自写 `/api/auth/me.php` 校验。
- 全局 `authHeaders()` 曾存在两种返回协议：部分页面当作 `fetch options`，部分页面当作 `headers` 对象。
- 生产 API 公共响应层缺少 `429 -> HTTP 429` 的明确映射，容易让客户端把限流和登录失效混在一起处理。

## 已完成收口

### H5 认证协议

- `js/app-auth.js` 的 `AppAuth.authHeaders()` 和全局 `authHeaders()` 统一为返回 headers 对象。
- 新增 `AppAuth.authFetch()` 和全局 `authFetch()`，用于后续新页面直接发起带认证请求。
- `js/auth.js` 同步统一为相同协议，降低旧页面加载顺序带来的不确定性。
- 已清理旧式 `fetch(url, authHeaders())` 调用，统一为 `fetch(url, { headers: authHeaders() })` 或后续 `authFetch(url, options)`。
- `app-auth.js` 引用统一提升到 `v=3`，降低浏览器缓存旧协议的风险。

### H5 登录态处理

- `internal-auth.js` 遇到 `/api/auth/me.php` 返回 `429` 时保留 token，不清理本地登录态。
- 页面自动认证和页面级认证通过 `window.__SKIP_AUTO_INTERNAL_AUTH__` 避免重复触发。
- `internal-auth.js` 引用已提升到 `v=8`。

## 需要继续收口的风险

### P0：API 响应语义

- `401`：未登录或 token 无效，客户端可跳登录。
- `403`：已登录但无权限，客户端展示无权限。
- `404`：资源不存在。
- `409`：业务状态冲突，例如需要绑定微信。
- `429`：请求过频，客户端保留登录态并提示稍后重试。
- `5xx`：系统异常，客户端保留登录态并提示稍后重试。

生产 `/api/config.php` 当前 `jsonResponse()` 只显式映射 `401/403/404`，本轮应补 `429`，后续补 `409/500` 统一约定。

### P1：认证入口治理

- 新页面统一加载 `internal-auth.js` 作为页面守卫。
- 移动 H5 页面如已显式调用 `requirePageAuth({ onAuthed })`，必须设置 `window.__SKIP_AUTO_INTERNAL_AUTH__ = true`。
- 业务请求统一使用 `authFetch()` 或 `fetch(url, { headers: authHeaders() })`。
- 新页面禁止新增自写 token 读取、清理和跳登录逻辑。

### P1：权限上下文治理

- 后端新接口优先使用 `appRequireStaffContext()` 获取用户、员工、角色、门店和权限。
- 权限判断优先使用 `appCanViewStore()`、`appCanEditStore()`、`appCanOperateStaff()` 等公共函数。
- 新模块不得自行映射角色别名，应复用 `appRoleCode()`。

### P2：客户端错误处理治理

- H5 和小程序请求层都应明确区分 `401/403/429/5xx`。
- 小程序 `utils/api.js` 后续应补充 `429` 分支：保留 token，提示请求过频。
- H5 `js/api-client.js` 后续应补充 `429` 分支：保留 token，向调用方抛出可识别错误。

## 新功能接入规则

- 后端接口入口：统一 `require_once api/common/context.php`，需要登录的接口调用 `appRequireStaffContext()`。
- 后端响应：成功使用 `appJsonSuccess()` 或 `jsonSuccess()`，失败使用明确业务码。
- 前端认证：页面守卫使用 `requirePageAuth()`，业务请求使用 `authFetch()`。
- 缓存版本：公共 JS 变更必须提升 query version。
- 部署：生产文件修改前先备份到带日期的目录。
- 验证：至少覆盖未登录、有效 token、无权限、限流/异常语义和关键业务接口。

## 回滚方案

- 本轮生产备份目录：`/www/wwwroot/122.51.223.46/backup-auth-429-20260612/`。
- 如新认证封装导致页面异常，优先回滚对应 HTML 和 `js/app-auth.js`。
- 如 `internal-auth.js` 行为异常，回滚 `internal-auth.js` 并临时提升脚本版本确保浏览器拿到回滚版本。

## 后续分批建议

1. 第一批：完成 H5 认证请求协议和 API `429` 状态码映射。
2. 第二批：审计所有页面内自写 `/api/auth/me.php` 校验，迁移到 `requirePageAuth()`。
3. 第三批：审计所有 API 入口，按公共上下文和权限函数归一。
4. 第四批：补小程序 `429/403/5xx` 错误语义和全链路验收。
5. 第五批：把新功能模板固化为 API 模板、H5 页面模板和小程序页面模板。
