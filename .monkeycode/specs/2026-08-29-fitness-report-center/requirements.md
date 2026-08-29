# Requirements Document

## Introduction

建设体测与运动规划报告中心，支持历史报告查询、完整报告详情和总部后台统计。系统从本次功能上线后保存完整报告数据，已有历史记录继续以摘要形式展示。

## Glossary

- **报告记录**：一次生成运动规划报告形成的业务记录，按生成次数计数。
- **完整记录**：包含体测数据、评级、教练填写信息、训练目标和报告正文的报告记录。
- **摘要记录**：升级前仅包含教练、门店、学员和日期的历史记录。
- **去重学员数**：按门店权限范围内的学员标识去重后的数量。

## Requirements

### Requirement 1: 历史报告查询

**User Story:** AS 教练, I want to query previous fitness reports, so that I can review a child's earlier assessment and planning history.

#### Acceptance Criteria

1. WHEN an authenticated user opens the report history, THE system SHALL return records within the user's authorized scope.
2. WHEN the user supplies date, store, coach or student filters, THE system SHALL return records matching all supplied filters.
3. WHEN a history record is selected, THE system SHALL display its complete report when complete data exists.
4. WHEN a history record predates this feature, THE system SHALL display the available summary fields and identify the record as a summary record.

### Requirement 2: 权限范围

**User Story:** AS a system owner, I want report access to follow employee scope, so that student assessment data remains limited to authorized staff.

#### Acceptance Criteria

1. WHILE the current user is a headquarters administrator, THE system SHALL allow access to all report records.
2. WHILE the current user is a store manager, THE system SHALL allow access to records created by staff in the manager's store.
3. WHILE the current user is a coach, THE system SHALL allow access to records created by the coach.
4. WHEN a request attempts to access a record outside the current user's scope, THE system SHALL return a stable authorization error.

### Requirement 3: 后台统计

**User Story:** AS a headquarters operator, I want report counts by store and coach, so that I can understand assessment activity across the organization.

#### Acceptance Criteria

1. WHEN the statistics page loads, THE system SHALL show total report count, distinct student count, today's count and current month's count within the authorized scope.
2. WHEN the statistics page loads, THE system SHALL show report counts grouped by store and coach.
3. WHEN the operator filters a date range or store, THE system SHALL recalculate all summary and grouped counts using the same filter scope.
4. WHEN a report is generated, THE system SHALL count one report generation and update the distinct student count independently.

### Requirement 4: 数据留存与兼容

**User Story:** AS a coach, I want new reports to remain reviewable, so that the report history supports follow-up communication with parents.

#### Acceptance Criteria

1. WHEN a new report is generated, THE system SHALL persist the available test values, ratings, coach inputs, goals, report content, generation mode and timestamps.
2. WHEN a report is saved, THE system SHALL associate the report with the authenticated creator and the selected store.
3. WHEN historical summary data lacks full report content, THE system SHALL preserve the summary record and expose its data completeness state.

### Requirement 5: 统计范围

**User Story:** AS a product owner, I want operational counts to have a clear definition, so that store and coach comparisons are consistent.

#### Acceptance Criteria

1. WHEN the system calculates activity, THE system SHALL count each generated report as one report occurrence.
2. WHEN the system calculates student coverage, THE system SHALL count each distinct student separately from report occurrences.
3. THE system SHALL provide report count, distinct student count and fallback report count as separate metrics.

## Confirmed Scope

- 员工端支持历史报告列表和报告详情。
- 总部后台支持报告统计、门店排名、教练排名和 AI/本地兜底数量。
- 总部管理员查看全部，店长查看本店，教练查看本人记录。
- 学员指标变化趋势不纳入本期范围。
- 新功能上线前的旧记录保留摘要状态，不进行历史完整内容补录。
