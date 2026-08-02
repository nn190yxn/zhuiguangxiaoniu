# 全站功能与升级治理矩阵

Updated: 2026-07-31
Status: Ready for Approval

## 1. 治理状态

每个矩阵行使用稳定组级功能 ID。生命周期统一为 `planned`、`in_development`、`implemented`、`deployed`、`verified` 或 `deprecated`；治理分类使用下表。当前矩阵是已知功能组基线，波次 0 将每个组展开为页面、API、数据表、Worker、Cron、文件和生产路径子项，并关联需求 ID、设计组件、依赖、所有者、风险、测试、发布批次和验证证据。

| 状态 | 定义 |
| --- | --- |
| 保留 | 现有边界和实现具备持续使用条件，补充基线与回归保护 |
| 完善 | 主链可用，补齐稳定性、客户端体验、治理或缺失能力 |
| 重构 | 保持外部契约，通过领域服务和公共层替换内部实现 |
| 补建 | 功能已规划或存在业务断点，需要新增完整闭环 |
| 合并 | 多个重复实现迁移到单一稳定实现 |
| 兼容承接 | 历史入口继续接收调用并转交稳定实现 |

## 2. 客户端与内容入口

| 功能组 | 当前入口 | 主要后端 | 当前状态 | 目标治理 |
| --- | --- | --- | --- | --- |
| WEB-001 品牌官网首页 | `/index.html`、WordPress 首页模板 | WordPress、静态内容 | `deployed`：生产运行 | 保留；增加健康探测、资源基线和发布回归 |
| WEB-002 门店展示 | `/stores/` | 静态内容 | `deployed`：5 家门店页面 | 完善；统一导航、元数据和链接完整性 |
| WEB-003 课程展示 | `/courses/` | 静态内容 | `deployed`：4 个课程页面 | 保留；统一内容入口和移动适配 |
| WEB-004 新闻内容 | `/news/`、WordPress | WordPress | `deployed`：公开内容运行 | 保留；明确 WordPress 与静态页面所有权 |
| WEB-005 培训内容 | `/training/`、`/training-center/`、`/training-cards/`、`/lessons/` | 静态内容、学习 API | `deployed`：多套入口并存 | 合并；建立稳定内容目录和历史兼容入口 |
| WEB-006 制度与体系文档 | `/制度标准/`、`/体系文件_最终版/`、`/docs/` | 文档读取接口 | `deployed`：公开与私有内容混合 | 重构；按公开、员工和受控文档分层 |
| WEB-007 员工工作台 | `/internal.html` | 认证、待办、搜索和业务入口 | `deployed`：核心入口运行 | 完善；作为浏览器兼容启动页并跳转至 `/mobile/` PWA |
| WEB-008 全局搜索 | `/search.html` | `/api/search/` | `deployed`：已有跨域搜索 | 完善；统一权限范围、索引状态和结果契约 |
| WEB-009 总部 Dashboard | `/admin/dashboard.html` | `/api/admin/dashboard/` | `deployed`：核心入口运行 | 完善；接入统一健康、待办和模块状态 |
| WEB-010 Admin 系统概览 | `/admin/system-dashboard.html` | 安全、统计和系统接口 | `deployed`：已有系统摘要 | 完善；接入平台可观测指标与发布状态 |
| WEB-011 历史兼容页 | `profile.html`、`weekly-drills.html`、旧工作量和制度入口 | 稳定目标入口 | `deployed`：多个跳转或重复页面 | 兼容承接；记录访问量后分批收口 |
| WEB-012 专题展示 | 周年庆、Showcase、内测清单 | 活动 API 或静态数据 | `deployed`：专题与预览混合 | 保留；逐项评审后将结束生命周期的子项标记为 `deprecated` |

## 3. 身份、组织与管理

| 功能组 | 客户端 | 主要后端 | 当前状态 | 目标治理 |
| --- | --- | --- | --- | --- |
| IAM-001 账号密码登录 | Web、PWA、小程序 | `api/auth-jwt.php`、`api/auth/` | `deployed`：JWT 主链运行 | 重构；统一认证控制器、错误和会话版本 |
| IAM-002 微信登录与绑定 | PWA、小程序 | 认证和员工映射 | `deployed`：已有兼容链 | 完善；统一绑定状态、冲突恢复和审计 |
| IAM-003 企业微信登录与绑定 | Web、小程序 | `api/wecom/`、认证 | `deployed`：已有同步与绑定 | 完善；统一身份候选、状态和失败恢复 |
| IAM-004 当前员工上下文 | 全客户端 | `api/common/context.php` | `implemented`：公共层已建立 | 保留；增强为全站权限权威入口 |
| IAM-005 后台具名权限 | Admin | `api/admin/common.php` | `implemented`：员工、组织、演练、招聘已使用 | 完善；覆盖全部管理业务域 |
| IAM-006 员工目录与详情 | Admin、PWA 本人页 | `api/admin/staff/`、`api/staff/` | `deployed`：服务化程度较高 | 保留；统一本人、门店和总部投影 |
| IAM-007 员工创建、导入、编辑 | Admin、CLI | 员工生命周期服务 | `deployed`：事务、幂等和审计已建立 | 保留；接入统一 API Kernel 和发布门禁 |
| IAM-008 离职、恢复和误建清理 | Admin | 员工生命周期和关联服务 | `deployed`：高风险保护已建立 | 保留；接入统一高风险操作审计 |
| IAM-009 门店、岗位和任职 | Admin | 组织领域服务 | `deployed`：版本化任职已建立 | 保留；消除历史角色和门店映射副本 |
| IAM-010 数据健康检查 | Admin | 员工数据健康服务 | `deployed`：7 类问题检查 | 完善；接入平台健康和自动关闭证据 |

## 4. 核心业务域

| 业务域 | 客户端 | 主要后端 | 当前状态 | 目标治理 |
| --- | --- | --- | --- | --- |
| BIZ-001 工作量填报 | PWA、小程序 | `api/workload/` | `deployed`：v2 主链运行 | 保留；统一多端状态版本和草稿恢复 |
| BIZ-002 工作量凭证 | PWA、小程序、Admin | 工作量文件与审核服务 | `deployed`：已有上传和审核 | 完善；迁入受控文件服务 |
| BIZ-003 工作量审核 | Admin | 工作量审核任务服务 | `deployed`：版本链和重审已建立 | 保留；统一通知和待办投影 |
| BIZ-004 工作量分析与导出 | Admin | 统计、缓存和导出 Worker | `deployed`：治理程度较高 | 保留；接入统一任务、指标和下载审计 |
| BIZ-005 工作量标准 | Admin | 规则版本和导入服务 | `deployed`：CSV/XLSX 与发布流程已建立 | 保留；迁移运行时 DDL 和缓存契约 |
| BIZ-006 销售演练 v2 | PWA、小程序、Admin | `api/drill/v2/` | `deployed`：内容、执行、评分和成长链已建立 | 完善；补齐 Worker 恢复和多端体验 |
| BIZ-007 销售演练 v1 | Web、历史移动端 | `api/drill/` | `deployed`：13 个旧端点并行 | 兼容承接；按契约基线迁移到 v2 |
| BIZ-008 演练音频 | PWA、小程序、Admin | 音频资源、分片和转写服务 | `deployed`：授权和留存已建立 | 完善；接入统一文件和语音能力 |
| BIZ-009 技能复盘 | Web、小程序 | `api/skill/`、技能 Worker | `deployed`：ASR 与分析 Worker 运行 | 重构；统一任务、AI 和访问控制 |
| BIZ-010 招聘需求与规则 | Admin | `api/admin/recruitment/` | `implemented`：代表入口已登记并接入 Kernel、权限和审计 | 保留；生产 HTTP 与迁移联调后发布 |
| BIZ-011 简历批量初筛 | Admin、Worker | 招聘服务和简历 Worker | `implemented`：百度 OCR、DeepSeek 文本、私有文件和平台任务已接入 | 保留；真实供应商审批与 Worker 联调后发布 |
| BIZ-012 候选人复核与联系 | Admin | 招聘候选人服务 | `implemented`：状态版本、二次鉴权和提醒 outbox 已接入 | 保留；生产通知消费者联调后发布 |
| BIZ-013 录用转员工 | Admin | 招聘域与员工生命周期服务 | `implemented`：具名审批、事务幂等转换和关联审计已闭环 | 保留；真实 MySQL 与生产审批联调后发布 |
| BIZ-014 学习中心 | PWA、小程序、Web | `api/learning/` | `deployed`：新旧页面和端点并存 | 重构；建立统一课程、课时和进度契约 |
| BIZ-015 知识库 | PWA、小程序、Web | `api/knowledge/` | `deployed`：列表、详情和搜索运行 | 重构；统一权限、目录和内容版本 |
| BIZ-016 考试 | 小程序、历史移动端 | `api/exam/` | `deployed`：开始、保存、恢复和提交已存在 | 完善；统一状态机、恢复和结果契约 |
| BIZ-017 通关与认证 | PWA、小程序 | `api/pass/` | `deployed`：地图、阶段、证书和语音评测 | 重构；语音能力接入网关并合并重复接口 |
| BIZ-018 制度中心 | PWA、小程序 | `api/policy/` | `deployed`：搜索、详情、订阅和通知 | 完善；统一已读、订阅和提醒状态 |
| BIZ-019 问卷 | Web、小程序 | `api/survey/` | `deployed`：CRUD、提交、统计和导出 | 重构；统一认证、响应和权限 |
| BIZ-020 活动业务 | Web | `api/campaign/` | `deployed`：周年庆日报与汇总 | 兼容承接；冻结长期扩展并保留专题生命周期 |
| BIZ-021 暑期营评估 | Web | `api/summer-camp/` | `deployed`：专题评估运行 | 完善；接入统一身份、AI 和报告存储 |
| BIZ-022 体测评估 | Web、PWA 入口 | `api/ai-services.php` | `deployed`：OCR 与报告生成运行 | 重构；迁移统一视觉、OCR 和文本能力 |
| BIZ-023 积分 | PWA、小程序 | `api/points/` | `deployed`：签到、记录和兑换 | 完善；统一事务、审计和状态同步 |
| BIZ-024 统计 | Web、Admin | `api/statistics/` | `deployed`：员工、门店和设备统计 | 合并；按领域事实和权限生成投影 |

## 5. 协作与消息能力

| 功能组 | 客户端 | 主要后端 | 当前状态 | 目标治理 |
| --- | --- | --- | --- | --- |
| MSG-001 企业微信成员同步 | Admin、Worker | `api/wecom/` | `deployed`：同步 Worker 与审计已存在 | 完善；统一任务租约、重试和健康指标 |
| MSG-002 企业微信消息 | Worker | 企微消息日志和发送服务 | `deployed`：已有日志与重试 | 完善；统一通知状态和失败恢复 |
| MSG-003 提醒规则 | Admin、PWA | `api/reminder/` | `deployed`：规则、订阅和 Worker 已存在 | 重构；统一任务、渠道和幂等事件 |
| MSG-004 通知中心 | PWA、小程序 | 通知页面与接口 | `implemented`：页面和路由存在差异 | 补建；建立统一通知列表、详情和已读状态 |
| MSG-005 待办聚合 | Internal、PWA、小程序 | `api/todos/` | `deployed`：已有聚合入口 | 完善；按业务事件生成权威待办投影 |
| MSG-006 小程序订阅消息 | 小程序 | 微信订阅能力 | `implemented`：申请和场景配置已存在 | 完善；与提醒和待办共享状态 |

## 6. PWA 与小程序

| 功能组 | 当前状态 | 目标治理 |
| --- | --- | --- |
| CLIENT-001 PWA Manifest | `implemented`：手机安装基础已建立 | 完善手机与桌面安装、快捷入口和版本标识 |
| CLIENT-002 Service Worker | `implemented`：缓存核心 H5 壳，API 网络直连 | 完善等待更新、回滚版本和缓存清单验证 |
| CLIENT-003 PWA 手机布局 | `implemented`：工作量、演练、学习和个人中心已覆盖 | 完善统一导航、错误、草稿和弱网恢复 |
| CLIENT-004 PWA 桌面布局 | `planned`：宽屏体验尚未形成统一标准 | 补建宽屏导航、分栏、表格和键盘体验 |
| CLIENT-005 PWA 本地草稿 | `implemented`：各业务自行处理 | 重构为用户、业务域、对象、版本和 24 小时有效期隔离的草稿契约 |
| CLIENT-006 小程序页面树 | `implemented`：31 个注册页面 | 保持现网；波次 3 修复阻断导航，波次 6 完善页面 |
| CLIENT-007 历史移动端页面树 | `implemented`：H5 与小程序式文件混合 | 分离发布边界并建立清晰工程所有权 |
| CLIENT-008 小程序请求层 | `implemented`：Bearer Token 与 API 拼接已存在 | 波次 3 完善请求 ID、幂等、错误和能力版本 |
| CLIENT-009 小程序工作量 | `implemented`：已接入主要工作量接口 | 保持现网并在波次 6 与 PWA 做同状态回归 |
| CLIENT-010 小程序演练 | `implemented`：v1/v2 页面并存 | 波次 6 重构为 v2 业务契约 |
| CLIENT-011 小程序学习与知识 | `implemented`：页面已存在 | 波次 6 完善统一 API、离线提示和进度同步 |
| CLIENT-012 小程序发布流程 | `implemented`：已有检查脚本与配置 | 波次 6 完善隐私、合法域名、真机和灰度清单 |

## 7. 平台公共能力

| 能力 | 当前状态 | 目标治理 |
| --- | --- | --- |
| PLATFORM-001 API 公共入口 | `implemented`：多代公共入口并存 | 合并为统一 API Kernel，历史入口兼容承接 |
| PLATFORM-002 JSON 响应 | `implemented`：新模块较统一，历史端点分散 | 统一状态、业务码、消息、数据和请求 ID |
| PLATFORM-003 幂等 | `implemented`：Drill、工作量和招聘已有领域实现 | 提炼公共契约，保留领域最终唯一约束 |
| PLATFORM-004 数据库迁移 | `implemented`：版本化 Runner 已建立，部分运行时 DDL 保留 | 完善 expand-migrate-contract 和双版本读写门禁 |
| PLATFORM-005 配置加载 | `implemented`：数据库、环境和多个运行时入口并存 | 重构；统一配置来源、校验和状态查询 |
| PLATFORM-006 AI 文本 | `deployed`：共享 Runtime 与多套直连并存 | 合并到 `text.generate` 和 `assessment.score` |
| PLATFORM-007 AI 视觉 | `deployed`：豆包、智谱和业务封装并存 | 合并到 `vision.extract` |
| PLATFORM-008 OCR | `deployed`：百度与 Tesseract 已有调用 | 合并到 `ocr.extract`，统一路由和审批 |
| PLATFORM-009 语音转写 | `deployed`：智谱、豆包和业务 Worker 直连 | 合并到 `speech.transcribe` |
| PLATFORM-010 图片生成 | `planned`：当前缺少正式消费者 | 仅保留 `image.generate` 架构扩展点，本轮排除生产交付 |
| PLATFORM-011 文件存储 | `deployed`：Web 公开目录和私有目录并存 | 按敏感级别迁入统一受控文件服务 |
| PLATFORM-012 Worker | `deployed`：多种入口保护、重试和日志模式 | 合并为带 fencing token、心跳、outbox 和副作用去重的任务契约 |
| PLATFORM-013 Cron | `deployed`：多个固定生产路径 | 重构为项目配置路径和统一健康检查 |
| PLATFORM-014 日志 | `deployed`：文件事件、领域日志和数据库审计并存 | 合并请求关联、脱敏、保留和查询入口 |
| PLATFORM-015 监控告警 | `implemented`：业务内局部状态和日志 | 补建健康、指标、阈值、告警和恢复闭环 |
| PLATFORM-016 备份恢复 | `implemented`：发布前定向备份已有实践 | 完善每日可读验证、RPO/RTO 和季度恢复演练 |
| PLATFORM-017 发布治理 | `implemented`：定向同步和人工验证 | 补建发布批次、量化观察窗口、停止阈值和回滚证据 |

## 8. 独立 FastAPI 服务

| 功能组 | 当前入口 | 当前状态 | 目标治理 |
| --- | --- | --- | --- |
| FASTAPI-001 用户认证 | `/api/v1` 认证路由 | `deployed`：独立用户模型 | 保留独立边界并接入统一健康指标 |
| FASTAPI-002 儿童档案 | `/api/v1` children 路由 | `deployed`：独立 SQLite 数据 | 保留；建立备份和数据所有权说明 |
| FASTAPI-003 AI 对话 | `/api/v1` chat 路由 | `deployed`：知识库与 AI 双轨 | 保留；增加供应商指标和错误分类 |
| FASTAPI-004 测评 | `/api/v1` assessments 路由 | `deployed`：独立领域 | 保留；纳入契约和健康回归 |
| FASTAPI-005 营养、育儿、教育 | 对应 `/api/v1` 路由 | `deployed`：独立领域 | 保留；按独立发布生命周期治理 |
| FASTAPI-006 知识库 | `/api/v1` kb 路由和文件知识库 | `deployed`：独立内容与索引 | 完善备份、索引健康和版本记录 |
| FASTAPI-007 支付与分享 | `/api/v1` payment、share 路由 | `deployed`：独立服务能力 | 完善审计、回调和故障告警 |
| FASTAPI-008 上传 | `/uploads` 静态挂载 | `deployed`：独立上传目录 | 重构为受控访问和保留策略 |

## 9. 已确认的结构风险

| 风险 | 影响 | 蓝图处置波次 |
| --- | --- | --- |
| 生产主站存在大量历史差异 | 全量覆盖可能影响现网功能 | 波次 0 建立哈希和定向同步清单 |
| 官网与内网共享站点根目录 | 共享文件变更影响公开与受保护入口 | 波次 1 建立消费者回归和路由边界 |
| AI Runtime 存在双副本漂移 | 模型和提示词行为不一致 | 波次 4 建立网关并兼容承接 |
| 多个业务直接调用 AI 供应商 | 超时、错误、审计和切换策略分散 | 波次 4 逐消费者迁移 |
| 部分模块运行时建表 | 请求延迟、锁和结构漂移 | 波次 2 迁入版本化迁移 |
| 历史文件位于 Web 可访问目录 | 敏感资源访问和留存风险 | 波次 4 迁入受控文件服务 |
| Worker 入口和重试规则不一致 | 重复执行和故障恢复能力分散 | 波次 2 与波次 4 统一任务契约 |
| PWA 当前以手机壳为主 | 桌面安装和宽屏体验不完整 | 波次 3 完善响应式员工应用 |
| H5 导航包含缺失历史路由 | 用户入口出现断点 | 波次 3 清单化修复或兼容跳转 |
| 小程序 Tab 导航方式存在错配 | 微信运行时可能出现跳转失败 | 波次 3 修正导航基线 |
| 招聘录用与员工建档发布联调待完成 | 本地已闭环跨域状态、幂等和审计 | 波次 5 完成真实 MySQL 与生产审批联调 |

## 10. 评审要求

本矩阵以 89 个稳定组级功能 ID 构成全站已知范围基线。波次 0 将通过静态扫描、生产只读盘点和业务入口核对生成可执行子项，明细粒度达到页面、API 端点、数据表、Worker、Cron、文件、权限和生产路径。每个子项必须关联组级功能 ID、需求 ID、设计组件、生命周期、治理分类、所有者、依赖、风险、验收证据和发布波次；未登记功能完成登记和影响分析后方可进入变更批次。
