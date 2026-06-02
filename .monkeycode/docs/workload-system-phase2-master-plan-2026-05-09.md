# 工作量系统二期总方案与交接说明

## 1. 文档目的

本文档用于把当前工作量系统的一期现状、已修复问题、审计标准、二期升级目标、技术路线、实施顺序、验证门槛和交接要求完整传递给下一个 Agent。

本方案不是“另起炉灶重做”，而是在**现有已上线工作量系统基础上做融合式升级**，实现：

- 员工端对指定指标**全量上传凭证**
- 总部在后台管理中心**全量审核**
- 审核结果融入现有工作量汇总
- 保持 H5 / 小程序 / 后台 / API / 公共层的一致性

## 2. 总体原则

### 2.1 绝对原则

1. 不做两套系统。
2. 不做两条小程序业务线。
3. 不新增与现有工作量系统平行的第二套审核系统。
4. 新功能必须融合进现有工作量日报、现有 `/api/workload/*`、现有 `/admin/workload.html`。
5. 继续遵守“只修不拆、最小改动、逐项验证、真实账号验证”的执行方式。

### 2.2 工程原则

1. 以线上目录 `/www/wwwroot/122.51.223.46/` 为真实基线。
2. 先审查后开发，先补规则后补界面。
3. 写接口必须通过统一鉴权和统一响应层。
4. 不允许因为审核功能引入新的权限绕过风险。
5. 所有新增字段、表、接口都要写清楚用途和回滚影响。

## 3. 一期现状回顾

### 3.1 现有已上线能力

- `/api/workload/template.php`
- `/api/workload/my-report.php`
- `/api/workload/save-report.php`
- `/api/workload/store-summary.php`
- `/api/workload/hq-summary.php`
- `/mobile/workload.html`
- `mini-program/pages/workload/index.*`
- `/admin/workload.html`

### 3.2 现有工作量指标

#### 销售岗当前已落地

- 新增资源数
- 外呼数
- 微信触达数
- 计划邀约数
- 实际邀约数
- 实际到店数
- 成交人数
- 新签金额

#### 教练岗当前已落地

- 计划耗课节数
- 实际耗课节数
- 计划沟通人数
- 实际沟通人数
- 体测人数
- 暑期营推荐人数
- 续费单数

### 3.3 一期审计已修复问题

#### WL-001

- 普通销售/教练可看本店全员汇总
- 已修复：`store-summary.php` 收紧为店长及以上可看

#### WL-002

- 总部账号可伪造岗位/门店提交日报
- 已修复：`save-report.php` 强制 `role_code === context.role` 且 `store_id === context.store_id`

#### WL-003

- 允许提交未来日期日报
- 已修复：后端拒绝未来日期，H5/小程序日期控件限制到今天

### 3.4 审计脚本

线上已存在以下审计脚本，注意这些脚本现已改为**必须显式传手机号**，不得再默认使用真实员工账号：

- `scripts/audit_workload_static.php`
- `scripts/audit_workload_matrix.php`
- `scripts/audit_workload_p0.php`
- `scripts/audit_workload_h5_e2e.php`
- `scripts/audit_workload_mini.php`
- `scripts/audit_workload_xss.php`
- `scripts/audit_workload_frontend.php`
- `scripts/audit_login_audit_write.php`

## 4. 二期业务目标

### 4.1 已确认的“员工必传凭证”指标

#### 销售岗

- 电话邀约量
- 朋友圈
- 抖音好评
- 美团好评

#### 教练岗

- 体测
- 运动规划发送给家长
- 家长重点沟通
- 朋友圈
- 抖音好评
- 美团好评

### 4.2 业务模式定义

注意这里有两个维度，不能混淆：

#### 维度 A：员工是否必须上传凭证

- 要上传
- 不上传

#### 维度 B：后台如何审核已上传凭证

- 不审核
- 抽检审核
- 全量审核

当前用户已明确的二期目标是：

- 对上述指定指标：**员工端全量上传凭证**
- 总部后台：**全量审核**

## 5. 融合式总体设计

### 5.1 不能独立开系统

严禁把它设计成：

- 工作量系统 A
- 凭证上传系统 B
- 审核系统 C

必须设计成：

```text
工作量系统
  ├─ 日报录入
  ├─ 门店汇总
  ├─ 总部汇总
  └─ 凭证审核
```

### 5.2 员工端融合原则

- 继续使用现有工作量录入页
- H5：`/mobile/workload.html`
- 小程序：`mini-program/pages/workload/index.*`
- 不新增第二个“上传凭证页面”作为独立主流程
- 凭证上传要嵌入指标行内或指标详情中

### 5.3 后台端融合原则

- 继续使用现有 `/admin/workload.html`
- 新增“凭证审核”视图或模式
- 不建议再单独起一个完全独立的大后台作为主入口

### 5.4 API 融合原则

- 所有新接口继续放在 `/api/workload/*`
- 不新开 `/api/audit/*` 第二套业务域

## 6. 二期数据模型设计

### 6.1 保留现有主表

- `workload_daily_reports`
- `workload_daily_report_values`

### 6.2 新增表：凭证表

#### `workload_evidences`

建议字段：

- `id`
- `report_id`
- `staff_id`
- `store_id`
- `role_code`
- `metric_code`
- `file_url`
- `file_name`
- `file_size`
- `mime_type`
- `sort_order`
- `remark`
- `created_at`

说明：

- 一条日报的一个指标可有多张凭证图
- `metric_code` 必须与现有工作量指标统一

### 6.3 新增表：审核任务表

#### `workload_audit_tasks`

建议字段：

- `id`
- `report_id`
- `staff_id`
- `store_id`
- `role_code`
- `metric_code`
- `submitted_value`
- `audit_status`：`pending` / `approved` / `rejected` / `needs_resubmit`
- `auditor_staff_id`
- `audit_comment`
- `audited_at`
- `created_at`
- `updated_at`

说明：

- 每个“需要凭证审核”的指标生成一条审核任务

### 6.4 新增表：审核日志表

#### `workload_audit_logs`

建议字段：

- `id`
- `task_id`
- `operator_staff_id`
- `before_status`
- `after_status`
- `comment`
- `created_at`

### 6.5 新增表：指标规则表

#### `workload_metric_rules`

建议字段：

- `metric_code`
- `metric_name`
- `role_code`
- `need_evidence`
- `min_evidence_count`
- `max_evidence_count`
- `audit_mode`：`none` / `full`
- `enabled`

说明：

- 不要把“哪些指标需要传图”写死在前端或接口里
- 统一用规则表控制

## 7. 指标规则建议配置

### 7.1 销售岗

- 电话邀约量：必传，审核模式 `full`
- 朋友圈：必传，审核模式 `full`
- 抖音好评：必传，审核模式 `full`
- 美团好评：必传，审核模式 `full`

### 7.2 教练岗

- 体测：必传，审核模式 `full`
- 运动规划发送给家长：必传，审核模式 `full`
- 家长重点沟通：必传，审核模式 `full`
- 朋友圈：必传，审核模式 `full`
- 抖音好评：必传，审核模式 `full`
- 美团好评：必传，审核模式 `full`

### 7.3 默认建议

- `min_evidence_count = 1`
- `max_evidence_count = 3`

## 8. 员工端改造设计

### 8.1 H5 页面

目标文件：

- `/mobile/workload.html`

改造要求：

1. 模板接口返回规则字段：
   - `need_evidence`
   - `min_evidence_count`
   - `max_evidence_count`
2. 对需要凭证的指标显示上传区域
3. 支持：
   - 上传图片
   - 预览图片
   - 删除图片
   - 显示已上传张数
4. 提交前校验：
   - 若该指标值大于 0 且要求凭证，则必须达到最少上传张数

### 8.2 小程序页面

目标文件：

- `mini-program/pages/workload/index.js`
- `mini-program/pages/workload/index.wxml`
- `mini-program/pages/workload/index.wxss`

改造要求：

1. 同 H5 逻辑保持一致
2. 不允许做第二套小程序上传主线
3. 必须继续走现有 `app.request()` / `mini-program/utils/api.js`
4. 不允许重新散写 `wx.request`

## 9. 后台审核中心改造设计

目标文件：

- `/admin/workload.html`

改造要求：

### 9.1 新增审核模式

- `store`
- `hq`
- `audit`

### 9.2 审核列表字段

- 日期
- 门店
- 员工
- 岗位
- 指标名称
- 指标值
- 凭证张数
- 审核状态

### 9.3 审核详情

- 日报信息
- 指标值
- 备注
- 凭证图片列表
- 审核意见输入

### 9.4 审核动作

- 通过
- 驳回
- 退回补充

### 9.5 汇总口径

建议同时保留：

- 原始提交值
- 审核通过值

第一版可按简单规则落地：

- `approved` -> 记原值
- `rejected` -> 记 0
- `needs_resubmit` -> 暂不计入有效值

## 10. API 设计要求

### 10.1 新增接口建议

- `/api/workload/metric-rules.php`
- `/api/workload/evidence-upload.php`
- `/api/workload/evidence-delete.php`
- `/api/workload/audit-list.php`
- `/api/workload/audit-detail.php`
- `/api/workload/audit-action.php`

### 10.2 必须遵守的接口规则

1. 必须使用统一响应：`appJsonSuccess()` / `appJsonError()`
2. 必须调用统一鉴权：`appRequireStaffContext()` 或更严格权限入口
3. 写接口必须使用统一校验函数
4. SQL 只能用 prepare / 参数绑定
5. 不允许裸 `echo json_encode`
6. 不允许通过前端传 `staff_id` 决定操作对象

### 10.3 权限要求

#### 员工端

- 只能上传自己的凭证
- 只能删除自己的未审核凭证
- 只能查看自己的凭证状态

#### 后台端

- 店长是否允许审核：默认不放，除非用户明确要求
- 总部运营 / 财务 / CEO / admin：可审核
- 普通销售/教练：绝不能访问审核接口

## 11. 审核状态机

建议状态：

- `pending`
- `approved`
- `rejected`
- `needs_resubmit`

状态约束：

- `pending -> approved`
- `pending -> rejected`
- `pending -> needs_resubmit`
- `needs_resubmit -> pending`（员工补传后）

## 12. 审查与验证标准

### 12.1 新增接口审查

每个新增 `/api/workload/*` 接口都要检查：

- 统一响应
- 统一鉴权
- 统一入参校验
- 无 SQL 注入
- 无越权

### 12.2 权限矩阵

至少验证以下角色：

- 销售
- 教练
- 店长
- 总部运营
- 财务
- CEO / admin

必须验证：

- 员工上传自己的凭证
- 员工不能看别人的凭证
- 员工不能审别人
- 总部能看审核池
- 总部能执行审核动作

### 12.3 前端验证

#### 员工端

- 指标要求上传时，不上传不能提交
- 上传后刷新不丢
- 补传流程通
- 非法文件拒绝

#### 后台端

- 待审核列表可用
- 审核动作成功
- 状态更新正确
- 汇总口径随审核变化

### 12.4 数据一致性验证

- 凭证必须与 `report_id + metric_code` 正确绑定
- 删除/补传不影响其他指标
- 审核通过/驳回后汇总口径正确

## 13. 实施顺序（必须按顺序）

### 第 1 步：补规则层

- 新增 `workload_metric_rules`
- 明确必传指标配置
- 不先写页面

### 第 2 步：补数据层

- 新增 `workload_evidences`
- 新增 `workload_audit_tasks`
- 新增 `workload_audit_logs`

### 第 3 步：补员工端上传接口

- 上传
- 删除
- 查询已上传凭证

### 第 4 步：改现有模板接口

- `template.php` 返回规则字段

### 第 5 步：改现有日报保存接口

- `save-report.php` 校验必传凭证
- 提交成功后生成审核任务

### 第 6 步：改 H5 工作量页

- 嵌入凭证上传

### 第 7 步：改小程序工作量页

- 与 H5 同口径

### 第 8 步：改后台工作量中心

- 增加审核视图
- 审核动作
- 审核详情

### 第 9 步：补汇总口径

- 原始值 / 审核值

### 第 10 步：跑完整审查

- P0
- P1
- 页面端到端
- 小程序可执行性
- 越权测试

## 14. 交接给下一个 Agent 的硬性要求

1. 不得新增平行系统。
2. 不得把审核功能拆成第二套主入口。
3. 不得把小程序做成“旧工作量页 + 新审核上传页”两套主链路。
4. 每做一步必须更新审计/实施文档。
5. 所有 P0/P1 修复必须真实验证后再提交 Git。
6. 不再默认使用真实员工账号做审计脚本默认参数。
7. 所有新增脚本必须要求显式传账号。

## 15. 本阶段建议产出

下一个 Agent 至少应产出：

1. 二期数据库表结构 SQL
2. 二期 API 详细清单
3. H5 改造任务单
4. 小程序改造任务单
5. 后台审核中心页面结构
6. 二期 P0/P1 验收用例清单

## 16. 当前结论

工作量系统一期已完成并完成上线前主审计。

二期应以“融合式凭证上传 + 后台全量审核”为方向推进，严格禁止新开第二套业务系统。
