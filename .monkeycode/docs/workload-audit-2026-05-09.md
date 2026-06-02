# 工作量系统上线前审计记录

## 审计范围

- `/api/workload/*`
- `/mobile/workload.html`
- `/admin/workload.html`
- `mini-program/pages/workload/*`
- 公共层：`api/common/context.php`、`api/common/helpers.php`、`js/app-auth.js`、`js/api-client.js`
- 新旧边界：员工表、门店表、JWT 登录、旧工作台入口

## 补充审计项

1. 缺陷记录必须落盘到本文档，不能只在对话中口头说明。
2. 每次 P0/P1 修复必须保留验证命令输出摘要。
3. 权限矩阵必须记录真实账号、角色、门店和预期结果。
4. 写接口除校验当前用户外，还需检查是否可伪造 `staff_id`、`store_id`、`role_code`。
5. 页面按钮不等于权限控制；导出、复制、下钻等前端能力必须依赖后端权限返回的数据。
6. 空数据、无模板、无门店、无岗位时必须有稳定响应，不能 500。
7. 小程序端除源码审查外，至少要验证接口路径和请求层是否统一。
8. 生产数据修复前必须记录备份或说明未涉及数据修改。

## 收尾计划

1. H5 页面端到端测试并修复问题。
2. 小程序源码与可执行性测试并修复问题。
3. XSS 与非法输入专项验证并修复问题。
4. 登录审计写入链路补齐并验证系统后台数据。
5. 空角色/无模板体验优化。
6. 输出上线前审计报告。

## 缺陷清单

### WL-001

【严重程度】P0
【所属模块】API / 权限
【问题描述】普通销售/教练可直接请求 `/api/workload/store-summary.php` 查看本店全部员工日报和缺交名单。
【复现步骤】
1. 使用教练账号 `18385534850` 登录。
2. 请求 `/api/workload/store-summary.php?date=当前日期&store_id=3`。
3. 实际结果：接口返回 `code=0` 和门店汇总。
4. 预期结果：只有店长及总部可查看门店汇总，普通员工应返回 `403`。
【涉及文件】`/api/workload/store-summary.php`
【修复状态】已验证
【修复记录】将 `appRequireViewStore()` 收紧为 `appRequireEditStore()`。
【验证记录】`WL001_COACH_STORE_SUMMARY_CODE=403`；权限矩阵中 `SALES_STORE_SUMMARY_3=403`、`COACH_STORE_SUMMARY_3=403`、`MANAGER_STORE_SUMMARY_3=0`、`OPERATION_STORE_SUMMARY_3=0`。

### WL-002

【严重程度】P0
【所属模块】API / 权限
【问题描述】总部账号因 `can_edit_all` 可传任意 `role_code` 和 `store_id` 给 `/api/workload/save-report.php` 写入日报，不符合“只有本人可录入”的业务预期。
【复现步骤】
1. 使用总部账号登录。
2. POST `/api/workload/save-report.php`，传入非本人岗位或非本人门店。
3. 实际结果：可能通过 `workloadAllowedRoleForContext()` 和门店权限校验。
4. 预期结果：日报录入只能提交本人岗位、本人门店。
【涉及文件】`/api/workload/save-report.php`
【修复状态】已验证
【修复记录】写接口增加 `role_code === context.role` 和 `store_id === context.store_id` 强校验。
【验证记录】`WL002_COACH_SAVE_OWN_CODE=0`；`WL002_HQ_FAKE_SAVE_CODE=403`。

### WL-003

【严重程度】P1
【所属模块】录入 / API / H5 / 小程序
【问题描述】日报写接口允许提交未来日期，可能污染未来提交率和缺交统计。
【复现步骤】
1. 登录任意销售/教练账号。
2. POST `/api/workload/save-report.php`，`report_date` 传明天日期。
3. 实际结果：修复前可进入保存逻辑。
4. 预期结果：未来日期应被拒绝。
【涉及文件】`/api/workload/save-report.php`、`/mobile/workload.html`、`mini-program/pages/workload/index.*`
【修复状态】已验证
【修复记录】后端增加 `report_date > today` 拦截；H5 日期输入设置 `max=today`；小程序 date picker 设置 `end=maxDate`。
【验证记录】`WL003_FUTURE_SAVE_CODE=400`；`mobile_workload=200`；`H5_MAX_DATE=YES`；`MINI_PICKER_END=YES`；`MINI_MAX_DATE_DATA=YES`。

## 验证记录

### P0 接口协议静态审查

```text
_common.php LIB
hq-summary.php success=YES error=YES auth=YES raw_echo=NO manual_header=NO direct_query=YES prepare=YES
my-report.php success=YES error=YES auth=YES raw_echo=NO manual_header=NO direct_query=NO prepare=YES
save-report.php success=YES error=YES auth=YES raw_echo=NO manual_header=NO direct_query=NO prepare=YES
store-summary.php success=YES error=YES auth=YES raw_echo=NO manual_header=NO direct_query=NO prepare=YES
template.php success=YES error=YES auth=YES raw_echo=NO manual_header=NO direct_query=NO prepare=NO
```

说明：`hq-summary.php` 的 `direct_query=YES` 为固定 SQL 查询应交员工数，不拼接用户输入。

### P0 权限矩阵验证

```text
SALES_LOGIN=OK
SALES_TEMPLATE=0
SALES_MY_REPORT=0
SALES_STORE_SUMMARY_3=403
SALES_HQ_SUMMARY=403
COACH_LOGIN=OK
COACH_TEMPLATE=0
COACH_MY_REPORT=0
COACH_STORE_SUMMARY_3=403
COACH_HQ_SUMMARY=403
MANAGER_LOGIN=OK
MANAGER_TEMPLATE=null
MANAGER_MY_REPORT=0
MANAGER_STORE_SUMMARY_3=0
MANAGER_HQ_SUMMARY=403
OPERATION_LOGIN=OK
OPERATION_TEMPLATE=null
OPERATION_MY_REPORT=0
OPERATION_STORE_SUMMARY_3=0
OPERATION_HQ_SUMMARY=0
FINANCE_LOGIN=OK
FINANCE_TEMPLATE=null
FINANCE_MY_REPORT=0
FINANCE_STORE_SUMMARY_3=0
FINANCE_HQ_SUMMARY=0
CEO_LOGIN=OK
CEO_TEMPLATE=null
CEO_MY_REPORT=0
CEO_STORE_SUMMARY_3=0
CEO_HQ_SUMMARY=0
```

### H5 端到端验证

```text
H5_LOGIN=OK
H5_PAGE_CODE=200
H5_TEMPLATE_CODE=0
H5_TEMPLATE_ITEMS=7
H5_SAVE_OK_CODE=0
H5_MISSING_REQUIRED_CODE=400
H5_INVALID_NUMBER_CODE=400
H5_NEGATIVE_CODE=400
H5_OTHER_STORE_CODE=403
H5_FUTURE_CODE=400
H5_READ_BACK_CODE=0
H5_READ_BACK_VALUES=3
```

### 小程序源码与接口验证

```text
MINI_FILE_APP_JSON=YES
MINI_FILE_APP_JS=YES
MINI_FILE_UTILS_API_JS=YES
MINI_FILE_UTILS_AUTH_JS=YES
MINI_FILE_PAGES_WORKLOAD_INDEX_JS=YES
MINI_FILE_PAGES_WORKLOAD_INDEX_WXML=YES
MINI_FILE_PAGES_WORKLOAD_INDEX_WXSS=YES
MINI_FILE_PAGES_WORKLOAD_INDEX_JSON=YES
MINI_PAGE_REGISTERED=YES
MINI_APP_REQUEST_USES_API=YES
MINI_API_AUTH_HEADER=YES
MINI_API_401_REDIRECT=YES
MINI_PAGE_USES_APP_REQUEST=YES
MINI_PAGE_NO_WX_REQUEST=YES
MINI_PICKER_END=YES
MINI_LOGIN=OK
MINI_TEMPLATE_CODE=0
MINI_MY_REPORT_CODE=0
```

### XSS 与非法输入专项验证

```text
XSS_LOGIN=OK
XSS_SAVE_CODE=0
XSS_READ_CODE=0
XSS_REMARKS_STORED_RAW=YES
XSS_BAD_METRIC_CODE=400
XSS_H5_ESCAPE_FUNC=YES
XSS_ADMIN_ESCAPE_FUNC=YES
```

说明：备注可原样存储，页面展示路径使用转义函数；非法指标返回 400。

### 登录审计写入验证

```text
LOGIN_AUDIT_BEFORE=0
LOGIN_AUDIT_AFTER=2
LOGIN_AUDIT_DELTA=2
LOGIN_SUCCESS_CODE=0
LOGIN_AUDIT_LAST_STATUS=success
LOGIN_AUDIT_LAST_TYPE=password
LOGIN_AUDIT_LAST_SOURCE=jwt_login
LOGIN_AUDIT_CODE=0
LOGIN_AUDIT_LIST=3
```

### 空角色/无模板体验验证

```text
mobile_workload=200
H5_NON_INPUT_ROLE_EMPTY=YES
MINI_NON_INPUT_ROLE_EMPTY=YES
```

## 上线前结论

- P0 接口协议、鉴权、权限矩阵、越权测试已通过。
- P1 数据完整性已修复未来日期提交问题。
- H5 端到端关键链路已通过。
- 小程序源码、页面注册、统一请求层和接口链路已通过；仍建议在微信开发者工具或真机做最终 UI 验收。
- XSS 与非法输入专项验证已通过当前展示路径。
- 登录审计已写入真实数据，系统后台不再空表。
- 空角色/无模板体验已优化。

## 剩余风险

- 未在微信开发者工具或真机中完成完整 UI 点击验收。
- 未做 100+ 大数据量浏览器性能压测。
- 未对生产慢查询做数据库层专项分析。
