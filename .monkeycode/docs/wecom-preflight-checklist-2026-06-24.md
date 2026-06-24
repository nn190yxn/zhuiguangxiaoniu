# 企业微信上线前只读预检清单

日期：2026-06-24

## 1. 目的

- 在执行任何数据库变更前，先通过只读查询确认现网基线。
- 在执行数据库变更后，再次执行同一批查询，确认旧链路核心数据没有异常漂移。

## 2. 使用文件

- `./wecom-readonly-precheck-queries-2026-06-24.sql`
- `./wecom-preflight-baseline-snapshot-2026-06-24.md`

## 3. 执行时机

1. 执行数据库变更前。
2. 执行数据库变更后、补配置前。
3. 挂 worker 前。
4. 如现网站或 H5 回归失败，立即重跑一次用于对比。

## 4. 重点核对项

### 4.1 结构基线

- `staffs` 是否存在。
- `wecom_%` 表在变更前应为空或不存在。
- `staffs` 变更前不应提前出现重复的企业微信字段。

### 4.2 主数据基线

- `staffs_total`
- `active_staff_total`
- `active_staff_without_user_id`
- 按 `status + role` 的人数分布

### 4.3 旧链路数据基线

- `mini_reminder_rules` 数量
- `mini_reminder_jobs` 数量
- `mini_user_notifications` 数量
- `policy_notifications` 数量

### 4.4 抽样核对

- `staffs` 前 20 条样本
- 最近 20 条提醒任务
- 最近 20 条站内通知
- 最近 20 条制度通知

## 5. 通过标准

1. 变更前后的旧链路核心表记录数保持合理。
2. 未出现明显异常清空、异常暴涨或主数据错乱。
3. `staffs` 在变更后只新增企业微信字段，原字段与原数据保持稳定。
4. 如出现异常差异，先停止继续企业微信联调，转入止损和复核。

## 6. 记录要求

每次执行预检至少记录：

1. 执行时间
2. 执行人
3. 变更前或变更后阶段
4. 关键计数结果
5. 是否允许继续下一步

建议回填：

- `wecom-live-execution-record-template-2026-06-24.md`
- `wecom-gap-tracking-2026-06-24.md`
