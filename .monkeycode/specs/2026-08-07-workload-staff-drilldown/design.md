# 工作量员工日报下钻技术设计

Feature Name: `workload-staff-drilldown`
Updated: 2026-08-07

## Description

在 `admin/workload.html` 增加员工姓名候选列表和门店详情员工姓名入口。新增受权限控制的员工查询接口，为个人日报接口提供内部员工 ID。个人日报和门店详情均明确草稿的可查看性及统计排除规则。

## Architecture

```mermaid
flowchart LR
    ADMIN["工作量管理后台"] --> SEARCH["员工姓名查询接口"]
    ADMIN --> STORE["门店排行明细"]
    SEARCH --> PROFILE["个人日报接口"]
    STORE --> PROFILE
    PROFILE --> DATA["日报、义务与项目数据"]
```

## Components and Interfaces

- `admin/workload.html`：维护已选择员工 ID；姓名输入后展示候选项；员工姓名点击后切换到个人视图。
- `api/workload/staff-search.php`：接收 `name` 和可选 `store_id`，返回当前权限范围内最多 20 名在职员工的 ID、姓名、门店和岗位。
- `api/workload/analytics/staff-profile.php`：保持现有内部 ID、日期范围和权限校验，返回个人日报记录。

## Correctness Properties

- 页面只将候选接口返回的内部 ID 传给个人日报接口。
- 门店权限用户只能查询当前授权门店；全量权限用户可查询全部门店。
- 草稿保持可见，完成率与排行继续使用既有已提交和管理更正规则。

## Error Handling

- 空姓名不发起查询。
- 无候选员工时显示明确提示。
- 请求失败时清空候选列表并显示查询失败提示。

## Test Strategy

- 静态测试验证姓名搜索控件、候选选择、门店详情员工入口和草稿提示。
- 静态测试验证员工查询接口包含认证、权限范围和姓名参数校验。
- 运行工作量后台 UI 与权限范围回归测试。

## References

[^1]: `admin/workload.html`
[^2]: `api/workload/analytics/staff-profile.php`
[^3]: `api/workload/services/WorkloadStaffProfileService.php`
