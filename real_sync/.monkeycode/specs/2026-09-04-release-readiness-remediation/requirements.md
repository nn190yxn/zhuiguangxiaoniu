# 全面上线修复需求

## Introduction

本规格把 2026-09-04 统一上线复核中的 P0、P1 问题收敛为可测试的修复要求。范围覆盖员工 Web、管理后台、PHP API、MySQL migration、知识库、教案、小程序云代理和统一发布门禁。目标是让每条关键链路具有单一契约、数据库一致性保护、自动化验证和独立回滚能力。

## Glossary

- **统一页面壳**：由 `internal-auth.js` 和 `assets/internal-ops.css` 提供的认证、身份、权限导航和视觉外壳。
- **副作用请求**：会创建记录、修改状态、扣减积分、发放积分或生成文件的请求。
- **幂等键**：用于识别同一业务请求重试的稳定标识。
- **当前知识版本**：`knowledge_items.current_version_id` 指向且状态为 `active` 的同卡版本。
- **批准教案版本**：教学主管终审通过后固定的结构化教案版本。
- **只读 dry-run**：仅计算预期变化且数据库结构和数据保持原值的 migration 预检。
- **业务矩阵**：登记小程序页面、HTTP 方法、API 路径、认证和副作用属性的机器可读清单。
- **统一发布门禁**：汇总静态契约、数据库验证、页面预览和浏览器验收凭据的放行程序。

## Requirements

### Requirement 1: 发布治理与批次边界

**User Story:** AS 发布负责人, I want 每批修复具有明确边界和回滚点, so that 上线风险可以逐批控制。

#### Acceptance Criteria

1. WHEN 修复批次开始, THE system SHALL record 涉及文件、数据库对象、接口契约、验证命令和回滚方法。
2. WHEN 当前批次的必需检查全部通过, THE system SHALL mark 当前批次 eligible for the next checkpoint.
3. IF 当前批次任一必需检查失败, THE system SHALL keep 后续批次 in blocked state and report the failed check.
4. WHEN 数据库结构变更进入执行阶段, THE system SHALL require a backup identifier and a verified rollback or forward-fix procedure.

### Requirement 2: 认证身份与权限导航一致性

**User Story:** AS 内网员工, I want 页面壳始终展示当前身份和授权入口, so that 我可以识别账号状态并访问对应功能。

#### Acceptance Criteria

1. WHEN `requirePageAuth()` 返回认证用户, THE unified shell SHALL render the authenticated display name, role and store.
2. WHEN 当前用户具有管理权限, THE unified shell SHALL display the management entry.
3. WHEN 当前用户具有员工权限, THE unified shell SHALL display only entries allowed by the shared permission contract.
4. WHEN 页面选择手动启动认证, THE unified shell SHALL still consume the authenticated user after successful authentication.
5. IF authentication expires, THE unified shell SHALL present the standard reauthentication path and preserve the intended return route.

### Requirement 3: API 成功与错误响应契约

**User Story:** AS API 消费方, I want HTTP 状态和业务状态表达同一结果, so that 页面、代理和监控可以稳定处理响应。

#### Acceptance Criteria

1. WHEN an API operation succeeds, THE API SHALL return an HTTP 2xx status and business code `0`.
2. WHEN request validation fails, THE API SHALL return the documented HTTP 4xx status and a stable business error code.
3. WHEN a knowledge management read or write succeeds, THE knowledge management API SHALL use the shared success response helper.
4. IF an unexpected exception occurs, THE API SHALL return a request identifier and a sanitized error response.

### Requirement 4: 关键副作用幂等

**User Story:** AS 员工, I want 请求重试产生一次业务结果, so that 网络波动和重复点击保持数据正确。

#### Acceptance Criteria

1. WHEN a client starts points exchange, exam submission, lesson creation or lesson export, THE API SHALL require an idempotency key.
2. WHEN the same actor, operation, business object and idempotency key are received again, THE API SHALL replay the first completed response.
3. IF the same idempotency key carries a different request fingerprint, THE API SHALL return a conflict response.
4. WHEN daily check-in succeeds, THE database SHALL preserve one earning record per user and business date.
5. WHEN concurrent duplicate requests arrive, THE database SHALL preserve one committed business result.

### Requirement 5: Migration 只读预检与兼容性

**User Story:** AS 数据库维护者, I want migration 预检准确识别风险并保持数据库原值, so that 结构变更可以安全审批。

#### Acceptance Criteria

1. WHEN migration runs with dry-run enabled, THE migration runner SHALL preserve all database structures and row values.
2. WHEN the migration history table is absent during dry-run, THE migration runner SHALL report the table state through read-only inspection.
3. WHEN migration SQL contains `MODIFY COLUMN`, `UPDATE`, `INSERT` or state backfill, THE compatibility validator SHALL evaluate the declared compatibility contract.
4. WHEN a migration can lock or rewrite an existing table, THE compatibility validator SHALL require an execution strategy and rollback or forward-fix declaration.
5. WHEN migrations are replayed on a compatible baseline, THE migration runner SHALL produce an idempotent final schema and verification result.

### Requirement 6: 知识分类、版本与员工可见性

**User Story:** AS 员工, I want 列表、详情、搜索和关联内容展示同一已发布版本, so that 知识内容保持一致。

#### Acceptance Criteria

1. WHEN a knowledge item appears in an employee discovery surface, THE knowledge service SHALL require enabled, published and current active version states.
2. WHEN related knowledge is returned, THE knowledge service SHALL validate current version ownership and active status.
3. WHEN an import domain code enters the knowledge platform, THE taxonomy mapping SHALL convert it through one versioned mapping source.
4. WHEN a knowledge card remains in transitional classification, THE management center SHALL keep the card in the review queue.
5. WHEN a knowledge card completes classification and review, THE publication workflow SHALL preserve its source, category, current version and audit record.
6. WHEN the 1417-card package is evaluated for release, THE release gate SHALL report repository package state separately from production database state.

### Requirement 7: 教案批准版本进入正式教案库

**User Story:** AS 教练, I want 终审通过的教案出现在正式教案库, so that 团队可以使用经过审核的固定版本。

#### Acceptance Criteria

1. WHEN a teaching supervisor approves a lesson, THE lesson workflow SHALL bind the approved version and create a publishable library record in one transaction.
2. WHILE a lesson is visible in the formal library, THE lesson API SHALL return the approved immutable version.
3. WHEN an approved lesson is archived, THE lesson workflow SHALL remove the lesson from active discovery and preserve historical references.
4. WHEN a coach creates a lesson, THE submission page SHALL derive display name from the shared identity contract and the API SHALL bind ownership from authenticated staff identity.
5. WHEN a lesson library entry is opened, THE canonical lesson route SHALL resolve to the approved lesson detail.

### Requirement 8: 小程序客户端与云代理契约一致性

**User Story:** AS 小程序用户, I want 直连和云代理使用同一 API 契约, so that 登录续期和业务请求具有一致结果。

#### Acceptance Criteria

1. WHEN the client refreshes a mini-program session, THE client, both business matrices, proxy allowlist and PHP endpoint SHALL use the same HTTP method and path.
2. WHEN an endpoint is registered in one business matrix, THE synchronization check SHALL require the same normalized endpoint in the deployed proxy matrix.
3. WHEN a business domain contains endpoints, THE business matrix SHALL contain one record for each normalized method and path pair.
4. IF a client calls an unregistered route, THE contract test SHALL report the calling file and missing matrix entry.

### Requirement 9: 受保护页面与静态资源版本统一

**User Story:** AS 平台维护者, I want 内网页面使用一致认证壳和资源版本, so that 发布后页面行为同步生效。

#### Acceptance Criteria

1. WHEN an HTML page is classified as an active internal entry, THE page SHALL load the unified authentication shell.
2. WHEN an HTML page is classified as archived reference content, THE owning center SHALL provide a controlled authenticated route to the page.
3. WHEN internal pages are released together, THE pages SHALL reference one release identifier for `internal-auth.js` and `internal-ops.css`.
4. WHEN the unified shell asset version changes, THE release contract SHALL detect stale active-page references.
5. WHEN desktop and mobile layouts load, THE unified shell SHALL preserve existing business DOM, event handlers and mobile navigation.

### Requirement 10: 统一放行证据

**User Story:** AS 发布负责人, I want 一个可复现的放行结果, so that 上线决策具有完整证据。

#### Acceptance Criteria

1. WHEN all static, API, database and browser checks pass, THE unified release gate SHALL output `ready_for_release=true`.
2. WHEN database integration evidence is absent, THE unified release gate SHALL output a failed database integration check.
3. WHEN browser role-flow evidence is absent, THE unified release gate SHALL output a failed browser integration check.
4. WHEN the release gate reports success, THE release artifact SHALL include test totals, migration verification, knowledge counts, role coverage and asset release identifier.
5. IF any required evidence expires after relevant code or migration changes, THE unified release gate SHALL require regenerated evidence.

## Scope Boundary

本计划覆盖审计台账第 263 至 277 项及其直接依赖。生产数据库 apply、生产部署、真实账号业务操作和生产内容发布属于实施完成后的授权执行阶段。公开官网内容改版、招聘功能扩展和新业务功能进入独立规格。

## Release Decision

所有 P0 批次和统一门禁属于正式放行前置条件。P1 项与同一认证、分类或代理契约相关时随对应 P0 批次一并收口。每批修复保留独立变更清单、验证结果和回滚入口。
