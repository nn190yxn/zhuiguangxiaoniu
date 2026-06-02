# 追光小牛页面入口说明（收口版）

## 核心入口

- 员工工作台：`/internal.html`
- 我的页面：`/mobile/mine.html`
- 总部运营后台首页：`/admin/dashboard.html`

## 员工端主链路

1. 登录页：`/mobile/login.html`
2. 工作台：`/internal.html`
3. 我的：`/mobile/mine.html`
4. 学习：`/mobile/learning.html`
5. 制度：`/mobile/policy.html`
6. 通知：`/mobile/notifications.html`

## 管理端主链路

1. 总部运营后台：首页 ` /admin/dashboard.html`
2. 学习汇总：`/admin/learning.html`
3. 长期业绩：`/admin/performance.html`
4. 工作量中心：`/admin/workload.html`
5. 系统后台：`/admin/system-dashboard.html`
6. 员工与权限：`/admin/staffs.html`
7. 登录审计：`/admin/security-login-audit.html`
8. 设备安全：`/admin/security-devices.html`
9. 系统异常：`/admin/system-errors.html`
10. 操作日志：`/admin/operation-logs.html`

## 当前收口规则

- `internal.html` 顶部固定有“我的”入口，跳转 ` /mobile/mine.html`
- `mobile/mine.html` 的“管理中心”固定跳转 ` /admin/dashboard.html`
- `mobile/admin.html` 返回按钮固定跳转 ` /mobile/mine.html`，不再使用 `history.back()`
- 管理入口显示规则统一为：`role=admin` 或 `role=manager` 或 `is_manager=true` 时显示。

## 稳定入口建议

- 员工日常统一从：`/internal.html` 进入。
- 个人设置统一从：`/mobile/mine.html` 进入。
- 管理后台统一从：`/admin/dashboard.html` 进入。
- 不再建议通过旧页面中的“返回”历史栈或站点首页绕行进入后台。

## 说明

- 如发现页面有旧入口跳转到不存在页面，优先从上述“核心入口”重新进入。
- 管理权限入口受登录角色控制，普通员工看不到管理菜单。
