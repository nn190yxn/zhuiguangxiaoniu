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

## 认证

主要认证方式为 HTTP Bearer JWT：

```http
Authorization: Bearer <JWT_TOKEN>
```

`real_sync/api/config.php` 负责读取 JWT 和数据库配置。Token 解析会关联 WordPress 用户与员工状态。部分网站入口保留 PHP Session 兼容路径。

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

员工与组织后台使用以下具名权限点：`staff.view_all`、`staff.create`、`staff.edit`、`staff.offboard`、`staff.restore`、`staff.reset_password`、`staff.purge`、`organization.manage`、`role.manage_privileged`、`staff.audit_view` 和 `system.settings`。总部运营与系统管理员共享前十项员工管理权限，系统设置权限仅属于系统管理员。

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

项目规则字段包括编码、名称、单位、值类型、必填、允许零值、数值范围、目标值、凭证数量、审核方式、统计方向和排序。发布与停用响应返回受影响版本和 `cache_invalidation_scope`；重复幂等请求返回首次成功结果。

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

`GET /api/workload/audit-list.php` 要求总部全量管理角色或店长门店范围。接口支持 `store_id`、`status`、`include_history`、`page` 和 `page_size`；默认只返回 `superseded_at IS NULL` 的当前任务，传入 `include_history=1` 时包含历史版本。历史版本通过最后一条 `after_status=superseded` 的审核日志恢复废止前状态并返回 `trace_status`；`status=pending|approved|rejected|needs_resubmit` 按追溯状态筛选，`status=superseded` 按存储状态筛选。每条记录返回 `task_version`、`previous_task_id`、`superseded_at`、`trace_status`、`raw_value`、`pending_value`、`effective_value` 和 `rejected_value`。

`POST /api/workload/audit-action.php` 接受 `task_id`、`action` 和可选 `comment`，其中 `action` 支持 `approved`、`rejected`、`needs_resubmit`。接口使用当前认证员工身份和门店权限，事务中锁定审核任务并写入审核日志；已 `superseded` 的历史任务返回冲突错误。

`POST /api/workload/evidence-upload.php` 对草稿日报沿用原凭证上传规则。已提交日报仅允许日报所有者为当前 `needs_resubmit` 审核任务补充对应项目凭证；接口在同一事务中完成所有权检查、日报与审核任务锁定、凭证上限复核和记录写入。

`POST /api/workload/audit-resubmit.php` 接受 `task_id`，并从认证上下文读取员工身份。接口只处理该员工本人当前 `needs_resubmit` 任务，要求凭证数量高于审核时基线且满足日报绑定岗位规则；成功后将旧任务标记为 `superseded`，创建 `task_version + 1` 的 `pending` 后继任务并写入审核日志。重复请求返回同一个当前后继任务，响应通过 `idempotent` 标识幂等结果。

## 小程序请求层

`real_sync/mini-program/utils/api.js` 统一附加 Bearer Token，并处理 Token 过期、HTTP 401、登录跳转和上传请求。`real_sync/mini-program/utils/auth.js` 兼容 `token` 与 `jwt_token` 两个历史本地存储键。

## Worker 接口

企业微信、提醒、技能分析和工作量义务模块包含 PHP CLI Worker。Worker 与 HTTP API 共用数据库配置和业务表。部署时需要在受控环境中配置 Cron，并记录实际命令、频率和日志位置。

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
- `scripts/check_miniprogram_release.mjs`：校验普通微信小程序域名能力、隐私声明和构建配置；微信后台域名与真机行为继续人工验收。
- `scripts/verify-workload-governance.mjs`：串联 PHP 语法、迁移、Node 契约、属性、权限、前端和小程序门禁；最终验收使用 PHP 8.2 检查 83 个 PHP 文件并完成全部 Node 测试。

当前版本化迁移为：

```text
real_sync/database/migrations/202607240001_staff_organization.sql
real_sync/database/migrations/202607240002_workload_governance.sql
real_sync/database/migrations/202607240003_admin_operation_audit.sql
real_sync/database/migrations/202607240004_staff_employee_number_sequence.sql
real_sync/database/migrations/202607240005_workload_audit_task_history.sql
real_sync/database/migrations/202607240006_workload_audit_resubmission.sql
real_sync/database/migrations/202607240007_workload_metric_relations.sql
```

员工组织迁移要求既有 `staffs`、`stores` 表，并新增岗位、任职、导入和资料更正结构。执行前应检查员工工号、`user_id` 和门店编码重复数据，因为迁移会为这些字段建立唯一索引。

工作量治理迁移要求既有工作量日报、指标、模板和审核表。它新增日报义务、来源策略、口径版本、岗位规则版本、预警、导出和管理更正结构，并为现有日报、指标值和审核任务补充统计索引。迁移内的初始历史义务范围仅包含已存在日报；运行时回填服务再按明确的历史任职区间补齐可确认缺交。

操作审计迁移创建 `admin_operation_logs`，员工新增服务依赖该表记录脱敏审计。迁移 CLI `real_sync/scripts/migrate.php` 支持 `apply`、`status`、`verify`、`rollback-plan` 和 `--dry-run`。

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
