# 追光小牛后台页面与接口规格

## 范围

本规格用于把当前已确认的安全修复、权限边界和现有 API 事实，落成可直接开发的后台页面原型与接口映射。

当前约束：

- 以线上目录 `/www/wwwroot/122.51.223.46/` 为真实业务基线。
- 本地工作区当前没有完整 `/admin/` 后台实现，只有 `internal.html` 中的后台入口和部分 `statistics` API。
- `设备登录预警`、`设备安全告警`、`设备使用审计` 仅属于超级管理员后台。

## 一期建设顺序

1. 总部运营后台首页
2. 长期业绩看板
3. 工作量中心
4. 学习与执行汇总
5. 超级管理员后台首页
6. 员工与权限管理
7. 登录审计与设备安全
8. 系统异常与操作日志

## 页面原型

### 1. 总部运营后台首页

- 路径：`/admin/dashboard.html`
- 权限：`admin`、`ops`、`finance`、`ceo`
- 页面结构：
  - 顶部 `SummaryCards`
  - 中部 `TrendChart`
  - 左侧 `StoreRankList`
  - 右侧 `StaffRankList`
  - 底部 `DataTable`
- 顶部指标：
  - `total_stores`
  - `total_staff`
  - `today_workload_submit_count`
  - `today_learning_complete_count`
  - `today_revenue`
- 表格建议：
  - 门店
  - 店长
  - 今日录入进度
  - 本周学习完成率
  - 本月业绩
  - 风险状态

### 2. 长期业绩看板

- 路径：`/admin/performance.html`
- 权限：`admin`、`ops`、`finance`、`ceo`、`manager`
- 页面结构：
  - 顶部 `FilterBar`
  - 中部双折线 `TrendChart`
  - 下方 `DataTable`
- 筛选项：
  - `date_from`
  - `date_to`
  - `store_id`
  - `role`
- 图表指标：
  - `new_sign_count`
  - `renew_count`
  - `revenue`
- 表格列：
  - 日期
  - 门店
  - 新签
  - 续费
  - 收入
  - 责任人

### 3. 工作量中心

- 路径：`/admin/workload.html`
- 权限：`admin`、`ops`、`ceo`、`manager`
- 页面结构：
  - 顶部 `SummaryCards`
  - 次级 `ByRoleTabs`
  - 主表 `DataTable`
  - 详情侧栏 `WorkloadDrawer`
- 顶部指标：
  - `submitted_count`
  - `expected_count`
  - `completion_rate`
  - `abnormal_count`
- 表格列：
  - 日期
  - 门店
  - 员工
  - 岗位
  - 工作项摘要
  - 总分
  - 状态
  - 异常标记

### 4. 学习与执行汇总

- 路径：`/admin/learning.html`
- 权限：`admin`、`ops`、`ceo`、`manager`
- 页面结构：
  - 顶部 `FilterBar`
  - 统计卡片
  - 学习完成趋势图
  - 员工明细表
- 表格列：
  - 员工
  - 门店
  - 岗位
  - 课程完成数
  - 知识卡完成数
  - 演练完成数
  - 考试均分
  - 通关率
  - 签到天数

### 5. 超级管理员后台首页

- 路径：`/admin/system-dashboard.html`
- 权限：仅 `admin`
- 页面结构：
  - 顶部系统安全卡片
  - 中部登录审计趋势
  - 下方风险模块导航
- 顶部指标：
  - `today_login_success`
  - `today_login_failure`
  - `locked_account_count`
  - `new_device_count`
  - `system_error_count`

### 6. 员工与权限管理

- 路径：`/admin/staffs.html`
- 权限：仅 `admin`
- 页面结构：
  - 顶部 `FilterBar`
  - 主表 `DataTable`
  - 右侧 `StaffDrawer`
  - 二次弹窗：重置密码、解绑微信、解锁账号
- 表格列：
  - 员工编号
  - 姓名
  - 手机号
  - 门店
  - 角色
  - 状态
  - 首登改密状态
  - 微信绑定状态
  - 最近登录时间
  - 最近登录 IP

### 7. 登录审计

- 路径：`/admin/security-login-audit.html`
- 权限：仅 `admin`
- 页面结构：
  - 顶部 `FilterBar`
  - 主表 `DataTable`
  - 右侧 `AuditDrawer`
- 筛选项：
  - `login_type`
  - `login_status`
  - `source`
  - `staff_id`
  - `store_id`
  - `date_from`
  - `date_to`
- 表格列：
  - 时间
  - 员工
  - 门店
  - 登录类型
  - 状态
  - 来源
  - IP
  - 结果说明

### 8. 设备安全中心

- 路径：`/admin/security-devices.html`
- 权限：仅 `admin`
- 页面结构：
  - 顶部风险卡片
  - `AlertsTable`
  - `UsageTable`
- 风险卡片：
  - 多设备账号数
  - 新设备数
  - 高风险设备数
- `AlertsTable` 列：
  - 员工
  - 门店
  - 设备数
  - 最近登录时间
  - 风险等级
- `UsageTable` 列：
  - 员工
  - 设备名
  - 型号
  - 系统版本
  - 首次登录 IP
  - 最近登录 IP
  - 最近登录时间
  - 信任状态

### 9. 系统异常中心

- 路径：`/admin/system-errors.html`
- 权限：仅 `admin`
- 页面结构：
  - 顶部 `FilterBar`
  - 错误统计卡片
  - 主表 `DataTable`
  - `ErrorDrawer`
- 表格列：
  - 时间
  - 级别
  - 模块
  - 错误码
  - 消息
  - 请求路径
  - IP
  - 关联员工

### 10. 管理操作日志

- 路径：`/admin/operation-logs.html`
- 权限：仅 `admin`
- 页面结构：
  - 顶部 `FilterBar`
  - 主表 `DataTable`
  - `DiffDrawer`
- 表格列：
  - 时间
  - 操作人
  - 模块
  - 动作
  - 目标类型
  - 目标 ID
  - IP

## 接口映射

### 已有接口可直接复用

#### `GET /api/statistics/store.php`

- 当前用途：门店维度统计列表
- 当前返回：`list`、`total`、`page`、`page_size`、`year`、`month`
- 可支撑页面：
  - 总部运营后台首页的门店列表区
  - 长期业绩看板的门店筛选基础数据
- 后续建议：
  - 补 `pagination` 对象，替代平铺的分页字段
  - 补 `filters` 回显

#### `GET /api/statistics/staff.php`

- 当前用途：员工维度学习/执行统计
- 当前返回：员工列表及学习、知识、演练、考试、签到、积分数据
- 可支撑页面：
  - 学习与执行汇总
  - 工作量中心的员工辅助筛选
  - 超级管理员员工管理页中的最近表现摘要
- 后续建议：
  - 新增 `status`、`phone`、`last_login_at`、`last_login_ip` 字段
  - 新增标准 `pagination`

#### `POST /api/statistics/device.php`

- 当前用途：上报设备登录信息
- 当前能力：
  - 写入 `device_logins`
  - 写入 `login_audit_logs`
  - 自动识别 `new_device` / `device_refresh`
- 可支撑页面：
  - 设备安全中心的数据底座

#### `GET /api/statistics/device.php?view=list`

- 当前用途：查看单个员工设备列表
- 当前权限：本人可看自己，超级管理员可看任意 `staff_id`
- 可支撑页面：
  - 员工详情侧栏中的设备信息

#### `GET /api/statistics/device.php?view=alerts`

- 当前用途：设备风险预警列表
- 当前权限：仅超级管理员
- 可支撑页面：
  - 设备安全中心 `AlertsTable`

#### `GET /api/statistics/device.php?view=usage`

- 当前用途：设备使用统计视图
- 当前权限：仅超级管理员
- 可支撑页面：
  - 设备安全中心 `UsageTable`

### 需要新增的接口

#### `GET /api/admin/dashboard/overview.php`

- 页面：总部运营后台首页
- 读表：`stores`、`staffs`、`staff_daily_workloads`、`monthly_statistics`
- 返回：
  - `summary`
  - `store_ranking`
  - `staff_ranking`
  - `trend_7d`

#### `GET /api/admin/performance/trend.php`

- 页面：长期业绩看板
- 读表：周年庆或业绩相关业务表
- 返回：
  - `trend`
  - `store_breakdown`
  - `role_breakdown`

#### `GET /api/admin/workload/summary.php`

- 页面：工作量中心
- 读表：`staff_daily_workloads`
- 返回：
  - `summary`
  - `by_role`
  - `by_staff`

#### `GET /api/admin/workload/list.php`

- 页面：工作量中心
- 读表：`staff_daily_workloads`
- 返回：
  - `list`
  - `pagination`
  - `filters`

#### `GET /api/admin/security/login-audit.php`

- 页面：登录审计
- 读表：`login_audit_logs`
- 联表：`staffs`、`stores`
- 返回：
  - `list`
  - `pagination`
  - `filters`

#### `GET /api/admin/staff/detail.php`

- 页面：员工与权限管理
- 读表：`staffs`
- 联表：`stores`、`users`、`device_logins`
- 返回：
  - `item`
  - `devices`
  - `recent_login_audits`

#### `POST /api/admin/staff/update.php`

- 页面：员工与权限管理
- 写表：`staffs`
- 旁路写入：`admin_operation_logs`

#### `POST /api/admin/staff/reset-password.php`

- 页面：员工与权限管理
- 写表：`users` 或认证表
- 旁路写入：
  - `admin_operation_logs`
  - `login_audit_logs` 的 `password_reset`

#### `POST /api/admin/staff/unlock-account.php`

- 页面：员工与权限管理
- 写表：`staffs.account_locked_until`、`staffs.failed_login_count`
- 旁路写入：`admin_operation_logs`

#### `POST /api/admin/staff/unbind-wechat.php`

- 页面：员工与权限管理
- 写表：`staffs.openid`、`staffs.openid_bound_at`
- 旁路写入：`admin_operation_logs`

#### `GET /api/admin/system/errors.php`

- 页面：系统异常中心
- 读表：`system_error_logs`

#### `GET /api/admin/system/operation-logs.php`

- 页面：管理操作日志
- 读表：`admin_operation_logs`

## 页面与接口对应关系

| 页面 | 首屏接口 | 次级接口 |
| --- | --- | --- |
| `/admin/dashboard.html` | `/api/admin/dashboard/overview.php` | `/api/statistics/store.php`, `/api/statistics/staff.php` |
| `/admin/performance.html` | `/api/admin/performance/trend.php` | `/api/statistics/store.php` |
| `/admin/workload.html` | `/api/admin/workload/summary.php` | `/api/admin/workload/list.php` |
| `/admin/learning.html` | `/api/statistics/staff.php` | `/api/statistics/store.php` |
| `/admin/system-dashboard.html` | `/api/admin/security/login-audit.php` | `/api/statistics/device.php?view=alerts`, `/api/admin/system/errors.php` |
| `/admin/staffs.html` | `/api/statistics/staff.php` | `/api/admin/staff/detail.php` |
| `/admin/security-login-audit.html` | `/api/admin/security/login-audit.php` | `/api/admin/staff/detail.php` |
| `/admin/security-devices.html` | `/api/statistics/device.php?view=alerts` | `/api/statistics/device.php?view=usage` |
| `/admin/system-errors.html` | `/api/admin/system/errors.php` | 无 |
| `/admin/operation-logs.html` | `/api/admin/system/operation-logs.php` | 无 |

## 写操作日志要求

以下操作必须同步写入 `admin_operation_logs`：

- 创建员工
- 修改员工资料
- 调整门店归属
- 调整角色权限
- 重置密码
- 解锁账号
- 解绑微信
- 修改工作量配置
- 修改系统安全策略

建议字段：

- `operator_user_id`
- `operator_staff_id`
- `module`
- `action`
- `target_type`
- `target_id`
- `before_json`
- `after_json`
- `ip_address`
- `user_agent`

## 开发优先级建议

### 第一批

- `/admin/dashboard.html`
- `/admin/learning.html`
- `/api/admin/dashboard/overview.php`
- `/api/admin/security/login-audit.php`
- `/api/admin/staff/detail.php`

### 第二批

- `/admin/staffs.html`
- `/admin/security-login-audit.html`
- `/admin/security-devices.html`
- `/api/admin/staff/update.php`
- `/api/admin/staff/reset-password.php`
- `/api/admin/staff/unlock-account.php`

### 第三批

- `/admin/workload.html`
- `/admin/system-errors.html`
- `/admin/operation-logs.html`
- `/api/admin/workload/*`
- `/api/admin/system/*`
