# 全面上线修复实施计划

- [x] 1. 建立统一发布契约基线
  - [x] 1.1 重构 `scripts/unified_release_gate.mjs` 的检查项模型，分别输出页面契约、数据库集成、浏览器集成、知识包状态和最终放行状态（Requirements 1.1、10.1-10.3）
  - [x] 1.2 定义机器可读 release evidence 结构，包含代码摘要、migration 集合、角色流、知识数量、静态资源发布号和有效时间（Requirements 10.4、10.5）
  - [x] 1.3 增加仓库隔离包与集成数据库状态分离报告，避免使用单一知识包状态推断生产状态（Requirement 6.6）
  - [x] 1.4 为门禁检查名称、失败原因、证据失效和 `ready_for_release` 聚合规则编写契约测试（Requirements 1.2、1.3、10.1-10.5）

- [x] 2. 修复认证身份和权限导航一致性
  - [x] 2.1 在 `internal-auth.js` 建立共享身份适配器，统一处理姓名、角色、门店和能力字段（Requirements 2.1、7.4）
  - [x] 2.2 调整 `requirePageAuth()` 成功路径，使自动认证和手动认证页面均使用本次认证用户刷新页面壳（Requirements 2.1、2.4）
  - [x] 2.3 统一管理入口与六中心导航的权限生成逻辑，保留 API 服务端权限校验（Requirements 2.2、2.3）
  - [x] 2.4 统一登录失效提示、返回路由和会话恢复流程（Requirement 2.5）
  - [x] 2.5 为普通员工、店长、教学主管和总部管理员编写身份回填与导航集合契约测试（Requirements 2.1-2.5）
  - [x] 2.6 为手动认证页面、无本地存储和会话过期路径编写回归测试（Requirements 2.4、2.5）

- [x] 3. 检查点：认证与发布契约
  - [x] 3.1 确保门禁聚合、认证、权限导航、JavaScript 语法和现有页面壳测试全部通过，如有疑问请询问用户

- [x] 4. 统一 API 成功响应和幂等基础能力
  - [x] 4.1 将 `api/admin/knowledge/index.php` 的成功分支迁移到统一成功响应，保持 HTTP 2xx 与业务码 `0` 一致（Requirements 3.1、3.3）
  - [x] 4.2 为旧式 `jsonResponse()` 和平台 `platformApiResponse()` 增加响应契约覆盖，规范验证错误、冲突和异常响应（Requirements 3.1、3.2、3.4）
  - [x] 4.3 新增平台幂等记录 migration 和执行器，保存 actor、operation、business scope、key hash、request fingerprint、状态与响应快照（Requirements 4.1-4.3）
  - [x] 4.4 在幂等执行器中实现唯一键竞争、处理中响应、首次结果重放、指纹冲突和过期清理边界（Requirements 4.2、4.3、4.5）
  - [x] 4.5 为幂等执行器编写重复序列、并发竞争和指纹冲突属性测试（Requirements 4.2、4.3、4.5）

- [x] 5. 接入关键副作用接口幂等
  - [x] 5.1 将积分兑换接入统一幂等执行器，并把库存扣减、积分扣减、积分流水和兑换记录保持在一个事务内（Requirements 4.1-4.3、4.5）
  - [x] 5.2 为每日签到增加用户与业务日期唯一约束，并保留命名锁作为并发等待控制（Requirements 4.4、4.5）
  - [x] 5.3 将考试提交接入统一幂等执行器，固定来源试卷、答卷摘要和首次评分响应（Requirements 4.1-4.3、4.5）
  - [x] 5.4 将教案创建接入统一幂等执行器，重复请求返回同一 submission 和初始版本（Requirements 4.1-4.3、4.5）
  - [x] 5.5 将教案导出接入统一幂等执行器，相同教案版本与格式重放同一完成结果（Requirements 4.1-4.3、4.5）
  - [x] 5.6 为兑换、签到、考试、教案创建和导出编写重复点击、超时重试及并发数据库集成测试（Requirements 4.2-4.5）

- [x] 6. 检查点：API 响应与副作用一致性
  - [x] 6.1 确保知识管理响应、幂等属性测试、五类副作用接口集成测试、PHP 语法和现有 API 契约测试全部通过，如有疑问请询问用户

- [x] 7. 修复 migration dry-run 与兼容性校验
  - [x] 7.1 重构 `MigrationRunner::apply(true)`，通过只读方式检查历史表并生成前后快照（Requirements 5.1、5.2）
  - [x] 7.2 扩展 `ExpandMigrateContractValidator`，识别 `MODIFY COLUMN`、`UPDATE`、`INSERT`、状态回填和大表重写（Requirements 5.3、5.4）
  - [x] 7.3 扩展 migration catalog 契约，要求兼容窗口、写适配器、预计影响行数、锁风险和 rollback 或 forward-fix 声明（Requirements 1.4、5.3、5.4）
  - [x] 7.4 为 `202609040002_lesson_version_relations.sql` 补齐数据回填与字段修改兼容声明，保持现有外键目标（Requirements 5.3、5.4）
  - [x] 7.5 建立临时 MySQL migration harness，支持 baseline、dry-run 快照比较、apply、verify、二次 apply 和关键数据断言（Requirements 5.1、5.5）
  - [x] 7.6 为 SQL 风险分类和 dry-run 零结构、零数据变化编写单元测试与属性测试（Requirements 5.1-5.4）

- [x] 8. 检查点：数据库变更安全
  - [x] 8.1 确保 migration 兼容性、readiness、dry-run、apply、verify、replay 和快照相等测试全部通过，如有疑问请询问用户

- [x] 9. 统一知识可见性和版本约束
  - [x] 9.1 提取统一知识可见性查询组件，封装启用、发布、current version 归属和 active 状态条件（Requirements 6.1、6.2）
  - [x] 9.2 将知识列表、详情、相关内容、全局搜索和教案知识匹配迁移到统一可见性组件（Requirements 6.1、6.2）
  - [x] 9.3 修复知识详情相关内容查询，返回当前版本 ID 并过滤版本归属异常记录（Requirements 6.1、6.2）
  - [x] 9.4 为列表、详情、搜索、相关内容和教案建议编写版本一致性属性测试（Requirements 6.1、6.2）

- [x] 10. 统一知识分类与 1417 张知识卡发布口径
  - [x] 10.1 建立版本化 domain-to-taxonomy mapping 数据源，覆盖导入包八个 domain code 和员工端两条主线（Requirement 6.3）
  - [x] 10.2 调整导入检查、`KnowledgeTaxonomy`、筛选 API 和发布门禁，使四处共同读取同一映射版本（Requirements 6.3、6.5）
  - [x] 10.3 生成 1417 张知识卡分类差异与待审核报告，明确过渡分类、映射缺口和人工确认项（Requirements 6.4、6.6）
  - [x] 10.4 扩展知识卡 release gate，分别验证记录数、过渡分类数、映射完整性、当前版本、审核记录和目标环境可见数量（Requirements 6.4-6.6）
  - [x] 10.5 为分类映射确定性、分类覆盖和仓库与数据库状态分离编写属性测试与契约测试（Requirements 6.3-6.6）

- [x] 11. 检查点：知识分类与发布边界
  - [x] 11.1 确保知识版本一致性、taxonomy、1417 张知识卡门禁、搜索和员工可见性测试全部通过，如有疑问请询问用户

- [x] 12. 打通教案批准版本和正式教案库
  - [x] 12.1 扩展教学主管批准事务，固定 `approved_version_id` 并写入正式教案库可见状态（Requirements 7.1、7.2）
  - [x] 12.2 实现正式教案列表与详情服务，只读取批准主记录及对应不可变版本（Requirements 7.2、7.5）
  - [x] 12.3 将 `lesson-library.html` 和学习中心教案入口切换到规范正式教案库路由（Requirement 7.5）
  - [x] 12.4 实现教案归档服务，使归档教案退出活跃发现并保留审核、版本、导出和审计引用（Requirement 7.3）
  - [x] 12.5 调整教案提交页作者显示逻辑复用共享身份适配器，并保持服务端 staff ownership（Requirement 7.4）
  - [x] 12.6 更新 `PlatformBusinessDomainRegistry` 的教案域消费者与能力声明，纳入正式教案库接口（Requirements 7.1、7.2、7.5）
  - [x] 12.7 为创建、上传、解析、编辑、提交、店长审核、主管批准、正式库展示和归档编写自动化端到端测试（Requirements 7.1-7.5）
  - [x] 12.8 为批准任务版本、`approved_version_id` 和正式库版本相等编写数据库属性测试（Requirements 7.1、7.2）

- [x] 13. 检查点：教案全链路
  - [x] 13.1 确保教案权限、版本、幂等、两级审核、正式库、归档和 Office 导出测试全部通过，如有疑问请询问用户

- [ ] 14. 同步小程序客户端、业务矩阵和云代理
  - [x] 14.1 依据 PHP 会话端点确定 refresh 的规范 HTTP 方法，同步 `mini-program/utils/api.js` 与两份业务矩阵（Requirement 8.1）
  - [x] 14.2 删除两份业务矩阵中重复的 `/todos/my.php` 登记，保留单一 method-path 记录（Requirement 8.3）
  - [x] 14.3 实现 endpoint normalization 与集合比较工具，覆盖 method、path 和 action query（Requirements 8.2、8.3）
  - [x] 14.4 扩展代理白名单同步检查，输出客户端调用、源矩阵、部署矩阵和代理配置的差集（Requirements 8.2、8.4）
  - [x] 14.5 为会话刷新、endpoint 唯一性、矩阵集合相等和缺失路由定位编写契约测试（Requirements 8.1-8.4）

- [ ] 15. 建立受保护页面清单并统一资源版本
  - [x] 15.1 创建内部页面清单，逐项标记 active、archived 或 public，并记录所属中心与规范入口（Requirements 9.1、9.2）
  - [x] 15.2 分类 `training-cards/workspace/` 的 11 个页面、`lessons/` 的 55 个页面和 `mobile/workload-v2.html`（Requirements 9.1、9.2）
  - [x] 15.3 为 active 内部页面接入统一认证壳，为 archived 页面建立所属中心的受控认证入口（Requirements 9.1、9.2）
  - [x] 15.4 生成统一静态资源发布号并更新 active 页面中的 `internal-auth.js` 与 `internal-ops.css` 引用（Requirements 9.3、9.4）
  - [x] 15.5 为页面分类完整性、认证壳引用、唯一页面壳、规范返回入口和资源发布号编写契约测试（Requirements 9.1-9.5）
  - [x] 15.6 为桌面和移动页面壳编写 DOM 保持、事件保持和移动导航保持测试（Requirement 9.5）

- [ ] 16. 检查点：小程序与页面覆盖
  - [x] 16.1 确保小程序矩阵、代理契约、受保护页面清单、资源版本、HTML/JavaScript/PHP 语法和页面壳测试全部通过，如有疑问请询问用户

- [ ] 17. 完成自动化集成放行能力
  - [ ] 17.1 为四类角色实现浏览器自动化场景，覆盖登录恢复、身份回填、权限入口、搜索、知识详情、教案提交和审核（Requirements 2.1-2.5、10.3）
  - [ ] 17.2 为桌面与移动视口实现核心页面响应式、错误态、弱网重试和缓存更新自动化检查（Requirements 9.3-9.5、10.3）
   - [x] 17.3 将临时 MySQL 验证、API 集成测试、浏览器结果和静态资源发布号写入 release evidence（Requirements 10.2-10.5）
   - [x] 17.4 扩展统一门禁校验 evidence 摘要，使相关代码或 migration 变化后旧证据自动失效（Requirement 10.5）
   - [x] 17.5 生成机器可读 release artifact，包含测试总数、migration 结果、知识数量、角色覆盖和每项检查明细（Requirement 10.4）

- [ ] 18. 最终检查点：统一发布门禁
  - [ ] 18.1 运行全部 Node 测试、PHP 测试、PHP/JavaScript 语法检查和 `git diff --check`（Requirements 1.2、10.1）
  - [ ] 18.2 运行临时 MySQL migration 全流程与五类副作用并发集成测试（Requirements 4.5、5.1-5.5）
  - [ ] 18.3 运行知识与教案端到端测试、小程序矩阵测试和四角色浏览器自动化测试（Requirements 6.1-6.6、7.1-7.5、8.1-8.4、10.3）
  - [ ] 18.4 使用当前 release evidence 运行 `node scripts/unified_release_gate.mjs --integration-verified`，确保 16 项及新增检查全部通过并输出 `ready_for_release=true`（Requirements 10.1-10.5）
