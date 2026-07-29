# API 模块

`real_sync/api/` 是企业内网的 HTTP 接口与后台任务入口，按业务域使用目录和独立 PHP 文件组织。

## 结构

```text
api/
├── admin/              # 总部后台与系统管理
├── auth/               # 当前用户与认证辅助接口
├── common/             # 员工上下文和权限公共层
├── workload/           # 工作量业务
├── wecom/              # 企业微信同步、绑定和消息
├── learning/           # 学习课程
├── knowledge/          # 知识库
├── exam/               # 考试
├── pass/               # 通关
├── drill/              # 销售演练旧接口与 v2 员工公共层
├── reminder/           # 提醒与订阅
├── statistics/         # 统计
├── config.php          # 数据库、JWT 和跨域配置入口
└── auth-jwt.php        # 主要认证入口
```

## 公共模式

- 数据库访问以 PDO 预处理语句为主。
- Bearer JWT 是移动端和 API 的主要认证方式。
- `common/context.php` 统一生成员工身份、角色和数据范围。
- `admin/common.php` 提供后台授权和操作审计公共能力。
- 标准 JSON 响应包含 `code`、`message` 和 `data`。
- 写接口使用事务保护跨表修改。

## 销售演练 v2 基础

`api/drill/v2/_common.php` 提供员工身份、允许方法、统一响应、请求 ID、输入读取和 `Idempotency-Key` 解析。`api/admin/drill/v2/_common.php` 复用该入口并追加具名权限校验。`api/drill/v2/services/DrillIdempotencyService.php` 在单一事务中保存请求哈希和首次响应，以 `(user_id, action, idempotency_key)` 唯一键处理重放与冲突。

`api/drill/v2/services/DrillContentVersionStateMachine.php` 定义内容版本的提交审核、退回修改、审核通过发布和归档转换，并将内容写入限制在草稿状态。规范化快照哈希保证字段顺序变化不会改变内容身份。`DrillContentVersionBinding.php` 生成场景版本、画像快照和评分版本的固定引用，供后续演练实例创建服务直接保存。

`api/drill/v2/audio-assets.php`、`api/drill/v2/audio-chunks.php`、`api/drill/v2/audio-access.php` 和 `api/drill/v2/audio-transcripts.php` 是员工端统一音频上传、受控读取与转写入口，PWA 与小程序共用相同 JSON 契约。`DrillMediaService` 校验演练实例和音频资源属于当前员工，限制音频格式、50MB 资源上限、5MB 分片上限、正序号、base64 内容长度和 SHA-256 摘要；重复资源摘要或重复分片相同内容按幂等重放返回。分片可携带临时转写文本写入 `partial` 转写，最终转写按 `chunk_no` 重排分片，发现缺片时返回待重传序号，完成后写入 `final` 转写并把音频资源置为 `uploaded`。真实录音复核要求授权状态、授权依据、用途、访问范围和期限完整有效，授权失效或留存到期会阻止转写和人工读取；受控读取按 `owner`、`reviewer`、`coach` 和 `admin` 范围授权。服务默认写入站点 `wp-content/uploads/drill-media/`，默认 180 天留存，到期处理清理音频文件并保留资源元数据和评分、复核、认证结果，同时支持在 v2 幂等事务内复用外层事务。

旧演练端点继续在 `api/drill/` 提供服务。`scripts/snapshot-drill-api.mjs --check` 将其源代码信号与 `scripts/drill-api-baseline.json` 对比，防止 v2 并行开发期间出现未记录的认证、事务、跨域和错误暴露变化。

## 员工新增

`api/admin/staff/create.php` 调用 `api/admin/services/StaffLifecycleService.php`。服务先使用统一密码策略校验初始密码，再校验员工字段、启用门店、启用岗位、适用角色和身份唯一性；同一事务中生成缺省工号，创建 WordPress 账号和员工档案，通过 `IdentityConsistencyService` 映射 WordPress 角色，并创建主任职与脱敏操作审计。密码校验失败返回 HTTP 400；工号、手机号或账号冲突返回 HTTP 409、冲突字段和脱敏档案摘要。授权角色为总部运营和系统管理员。

员工后台以三步抽屉收集创建参数，启用门店和岗位来自组织字典接口。前端执行逐步字段预检、脱敏确认和单次提交锁定，成功响应与后续目录刷新分开处理，并使用返回的员工 ID 打开详情。

`api/admin/staff/privileged-role-confirm.php` 调用 `PrivilegedRoleGuard::issueConfirmation()`，由另一名在职系统管理员为涉及 `admin` 的角色变化签发 5 分钟确认令牌。员工编辑和恢复事务重新校验请求人、审批人、目标员工、角色变化、会话版本和有效期；停用、离职或降权流程在行锁范围内保护最后一个在职系统管理员。审计保存权限前后快照、审批标识和确认 JTI。

## 员工目录

`api/admin/staff/list.php` 与 `api/admin/staff/detail.php` 调用 `api/admin/services/StaffDirectoryService.php`。旧入口 `api/admin/staff-list.php` 转接新列表端点。服务使用显式字段白名单，支持关键词、门店、主岗位、角色、生命周期、离职员工和分页筛选；详情聚合当前任职、历史任职、账号状态、业务摘要、可用操作、设备、登录审计和目标员工操作审计。操作审计投影仅包含动作、操作人和时间，不返回前后快照；其他敏感字段按总部角色权限输出原值或脱敏值。

员工详情抽屉将编辑、密码重置、离职、恢复和误建清理分别连接到员工生命周期写接口。离职要求日期、原因和显式确认；恢复重新确认启用门店、启用岗位、角色、账号状态和兼岗数组；误建清理先调用 `purge-check.php` 展示分类阻断计数，只有零关联结果才携带短时确认令牌调用 `purge.php`。前端提交锁覆盖风险操作，事务成功后的目录与详情刷新使用独立结果处理。

后台员工一览使用列表端点作为唯一员工数据源，并以 `keyword`、`store_id`、`position_id`、`role`、`lifecycle_status`、`page_size` 和 `page` 组合查询。生命周期为空时页面传递 `include_offboarded=1`；门店与岗位选项来自组织字典接口，状态摘要复用列表分页总数，异常摘要来自 `api/admin/staff/data-health.php`。

## 员工批量导入

`api/admin/staff-import.php` 与 `scripts/import_staff_cli.php` 调用 `api/admin/services/StaffImportService.php`。服务用 UUID 批次键锁定并记录一次导入，逐行调用 `StaffLifecycleService::create()`，保存成功员工 ID 或结构化失败原因。部分失败批次使用同一键重试时只处理失败行，完成批次重放直接返回原结果；批次处理中、请求人变化和行数变化返回冲突。导入表仅保存脱敏摘要，初始密码只存在于当次创建调用中。

员工后台导入工作区在客户端读取 CSV/JSON，生成 UTF-8 CSV 模板，根据中英文字段名建立可调整映射，并在提交前逐行检查必填字段与基本格式。请求以 JSON 记录数组进入同一导入端点，服务端返回的批次与逐行结果驱动桌面表格和窄屏卡片；存在失败行时，页面携带原 `retryable_batch_key` 与同一行数重试。

## 员工目录导出

`api/admin/staff/export.php` 调用 `StaffDirectoryService::export()`，复用员工列表筛选、排序、白名单和角色敏感字段策略。端点以 100 行分页迭代器流式写出带 UTF-8 BOM 的 CSV，结果超过 20,000 行时要求调用方增加筛选条件。固定列覆盖基础资料、当前组织、生命周期和账号状态，危险公式前缀按文本处理。

## 员工数据健康

`api/admin/staff/data-health.php` 调用 `StaffDataHealthService::inspect()`，使用 `staff.audit_view` 权限实时检查重复员工标识、在职员工的无效门店与岗位引用、员工角色与 WordPress 能力差异，以及员工档案和内部员工账号之间的孤立关系。响应提供总体健康状态、分类计数和问题明细，敏感手机号与账号沿用角色脱敏策略。检查只执行读取操作，修复数据后重新请求即会重新计算状态。

员工后台数据健康工作区按七类问题显示影响数量、问题对象和检查时间。问题入口复用员工详情或员工目录修复上下文，实际修复继续调用现有受审计管理流程；“重新检查”重新请求只读端点，并用最新结果确认问题是否关闭。

## 员工本人档案与更正

`api/staff/profile.php` 调用 `StaffProfileService::profile()` 返回当前登录员工的只读身份、组织、兼岗和账号投影。`api/staff/profile-corrections.php` 将员工 ID 固定为当前登录员工，支持查询本人申请和提交受限字段的更正快照。`api/admin/staff/profile-corrections.php` 使用 `staff.edit` 权限分页查询并处理待办；申请与处理均在事务中写入操作审计，相同待处理变更保持幂等，处理后的状态、意见和时间对员工可见。

## 员工编辑

`api/admin/staff/update.php` 调用 `StaffLifecycleService::update()`。服务锁定员工并校验基础字段、手机号唯一性、组织引用、角色、生效日期和编辑原因；组织变化在同一外层事务内调用 `OrganizationService` 创建带日期的主岗记录。状态变化同步员工生命周期和 WordPress 账号可用状态，员工与任职服务分别保存前后审计快照。离职员工保持只读，冲突使用 HTTP 409 返回结构化详情。

## 离职归档

`api/admin/staff/offboard.php` 调用 `StaffLifecycleService::offboard()`。接口要求离职日期、原因和明确确认；服务锁定员工及离职日有效任职，将离职日期作为最后在岗日，停用员工与 WordPress 账号，递增会话版本，撤销设备信任及可用消息订阅，并记录员工、任职和撤销数量的前后审计快照。全部写入位于同一 PDO 事务，失败路径统一回滚。

## 员工恢复

`api/admin/staff/restore.php` 调用 `StaffLifecycleService::restore()`。接口要求重新确认恢复日期、门店、主岗位、角色、账号启用状态、兼岗清单和原因。服务先锁定离职员工与账号，再恢复生命周期并递增会话版本，通过支持调用方事务的组织服务创建新主岗和全部兼岗。离职前历史任职保持不变，任职冲突返回结构化 HTTP 409 响应，任一步骤失败时整体回滚。

## 误建员工清理预检

`api/admin/staff/purge-check.php` 调用 `StaffAssociationService::inspectForPurge()`。服务只使用固定表名和关联字段白名单，按员工 ID 与 WordPress 用户 ID 统计登录、设备、工作量、学习、审核、通知、消息、积分、组织外部引用和操作人历史。首条主任职属于允许的身份基线，额外主任职或兼岗会阻断清理；表结构不兼容和查询错误同样阻止令牌签发。完整零关联结果签发 5 分钟 HMAC 确认令牌，绑定操作者、目标身份、会话版本和关联摘要；其他结果返回离职归档建议。

`api/admin/staff/purge.php` 调用 `StaffLifecycleService::purgeMiscreated()`。接口仅允许总部运营和系统管理员提交清理原因、明确确认和预检令牌。服务在事务内锁定员工、关联 WordPress 账号及全部任职，重新执行关联检查并验证令牌与最新状态完全匹配，再删除任职、员工档案、用户元数据和账号。删除数量异常、关联变化、令牌失效或审计失败会整体回滚；审计保留清理前完整身份快照、关联摘要、原因、删除数量和令牌唯一标识。

## 岗位字典

`api/admin/organization/positions.php` 调用 `api/admin/services/OrganizationService.php`。`GET` 支持指定岗位、状态和关键词查询；`POST` 支持创建、编辑和启停。服务规范化岗位编码与适用角色，按排序值稳定输出，在同一事务中执行编码唯一性检查、停用引用检查和操作审计。重复编码和当前员工或任职引用冲突返回 HTTP 409，历史任职记录保持不变。

## 门店字典

`api/admin/organization/stores.php` 复用 `OrganizationService`。`GET` 支持指定门店、状态和关键词查询；`POST` 支持创建、编辑和启停。服务规范化门店编码，校验在职负责人，同步 `manager_staff_id` 与兼容字段 `manager_name`，并在事务中执行唯一性、当前员工归属、有效任职和审计检查。员工创建与导入入口通过启用状态和门店行锁阻止停用门店重新获得在职员工。

## 任职服务

`OrganizationService` 提供主岗变更、兼岗创建与兼岗结束领域方法。服务按员工锁定全部任职记录，使用闭区间处理生效日期：旧任职结束于新任职生效日前一天，新主岗按下一条计划主岗确定结束边界。同日相同请求保持幂等，同日不同主岗和相同职责兼岗区间重叠返回冲突，已结束历史记录保持只读。

当前日期生效的主岗会同步员工当前门店、主岗位、角色和兼容岗位名称。所有任职写入校验在职员工、启用门店、启用岗位和适用角色，并保存变更原因、操作人员工 ID 与审计前后快照。员工编辑端点接入属于后续任务。

## 组织架构树

`api/admin/organization/tree.php` 调用 `OrganizationService::getOrganizationTree()`，按数据库当前日期读取启用门店、启用岗位、在职员工和有效任职。响应同时提供“总部 → 门店 → 岗位 → 员工”树、门店岗位员工平铺列表和汇总计数。空门店保留在树中，主岗和兼岗按任职关系分别展示，员工节点使用明确字段白名单排除联系方式与账号安全字段。

后台组织管理视图直接复用这三个组织端点。组织树响应驱动树形、列表和六项摘要；门店与岗位字典响应驱动桌面表格、窄屏卡片和设置抽屉。设置写请求使用单次提交锁，成功后重新读取字典和组织树。停用前展示当前员工、有效任职和历史任职引用，当前引用触发页面预检或服务端 HTTP 409，历史任职持续保留。

## 修改检查

1. 明确端点的角色与数据范围。
2. 复用统一上下文和响应函数。
3. 对请求字段执行白名单和类型校验。
4. 使用参数绑定访问数据库。
5. 添加成功、权限、校验和异常路径测试。
6. 核对 H5、小程序和后台调用方兼容性。
