# Requirements Document

## Introduction

本规格定义内网员工知识体系升级。目标是让员工清楚理解各中心职责，并通过专业知识与销售知识两条主线访问知识卡、动作、游戏、话术、案例和教案参考。管理中心负责统一内容分类、审核、发布、关联和搜索治理。

## Glossary

- **制度中心**：提供公司制度、流程、岗位标准、通知和常用表单。
- **知识中心**：提供可阅读、可搜索、可收藏的专业知识和销售知识内容。
- **演练中心**：提供岗位场景练习、提交、评分和反馈。
- **学习中心**：提供岗位学习路径、课程、培训资料和进度。
- **业务工具**：提供体测、工作量、教案和问卷等具体工作工具。
- **已发布内容**：经过管理审核并允许员工访问的内容版本。

## Requirements

### Requirement 1: 员工端信息架构

**User Story:** AS 普通员工, I want to see clearly separated internal centers, so that I can enter the correct function without interpreting overlapping labels.

#### Acceptance Criteria

1. WHEN 员工打开内网首页, THE system SHALL display 制度中心、知识中心、演练中心、学习中心、业务工具和我的六个一级入口。
2. WHEN 员工查看一级入口, THE system SHALL display each center's name, responsibility and included functions.
3. WHILE 员工浏览内网首页, THE system SHALL keep navigation labels consistent across internal pages.
4. WHEN 员工进入 any center, THE system SHALL provide a visible link back to the internal home and the current center.

### Requirement 2: 知识中心双主线

**User Story:** AS 员工, I want to browse professional and sales knowledge separately, so that I can find content by work domain.

#### Acceptance Criteria

1. WHEN 员工进入知识中心, THE system SHALL display 专业知识 and 销售知识 as the primary categories.
2. WHEN 员工进入专业知识, THE system SHALL provide child categories for child development, fitness, sensory integration, action and game, teaching, assessment, safety, coach growth and lesson references.
3. WHEN 员工进入销售知识, THE system SHALL provide child categories for reception, needs analysis, fitness explanation, trial class, parent communication, objection handling, conversion, renewal and sales scripts.
4. WHEN a content item belongs to action, game, script, case or lesson reference, THE system SHALL display the item under its business category and retain its content type.
5. WHEN an employee enters professional knowledge, THE system SHALL include action library, game library, fitness guidance and lesson references within the professional knowledge view.

### Requirement 3: 内容统一融合

**User Story:** AS 员工, I want related cards and tools to connect with each other, so that I can move from understanding to practice and application.

#### Acceptance Criteria

1. WHEN an action, game or safety card is published, THE system SHALL make the card available to the knowledge list, global search and eligible lesson recommendations.
2. WHEN a knowledge card has related drills, courses, policies or lessons, THE system SHALL display those related entries on the detail page.
3. WHEN an employee opens a static legacy content entry, THE system SHALL provide a canonical route into the corresponding unified content view.
4. WHEN a lesson is approved, THE system SHALL expose the lesson through the lesson center and preserve its approved version.

### Requirement 4: 全局搜索

**User Story:** AS 员工, I want to search across internal content with business keywords, so that I can reach the required information from one search box.

#### Acceptance Criteria

1. WHEN 员工 submits a keyword, THE system SHALL search published policies, knowledge, actions, games, scripts, drills, training, lessons, courses and fitness guidance.
2. WHEN a keyword has a configured synonym or alias, THE system SHALL include the mapped terms in the search query.
3. WHEN search results are returned, THE system SHALL display center, category, content type and canonical route for every result.
4. WHEN no result is returned, THE system SHALL record the normalized keyword for management review and display related search suggestions.

### Requirement 5: 管理中心内容治理

**User Story:** AS 内容管理员, I want to manage all internal content from one management center, so that employee-facing content remains classified and current.

#### Acceptance Criteria

1. WHEN an administrator creates or imports content, THE system SHALL require a center, primary category, content type, title, summary and publication state.
2. WHEN an administrator publishes content, THE system SHALL require review completion and preserve the published version identifier.
3. WHEN an administrator edits published content, THE system SHALL create a new version and preserve the previous version history.
4. WHEN an administrator configures a keyword, THE system SHALL support aliases, synonyms, category mapping and search priority.
5. WHEN management views content statistics, THE system SHALL display publication counts, search no-result terms, views, favorites and learning progress.

### Requirement 6: 发布边界与员工可见性

**User Story:** AS 内容管理员, I want imported content to pass classification and review before employee access, so that employees receive reliable material.

#### Acceptance Criteria

1. WHEN imported content has isolated state, THE system SHALL show the content in management review queues.
2. WHILE content has isolated or draft state, THE system SHALL keep the content out of employee lists, global search and lesson recommendations.
3. WHEN content reaches published state, THE system SHALL include the current version in all eligible employee views.
4. WHEN content is archived, THE system SHALL preserve historical references and remove the content from active employee discovery.
5. WHEN the unified release gate is opened, THE system SHALL publish the approved knowledge-center upgrade as one coordinated release containing routes, categories, content, search and management capabilities.
6. WHEN any required release gate fails, THE system SHALL keep the new content in pre-release state and display the failed gate to administrators.

### Requirement 7: 入口和术语一致性

**User Story:** AS 员工, I want the same function to use one stable name and route, so that I can form a reliable usage habit.

#### Acceptance Criteria

1. WHEN the same business function appears in multiple pages, THE system SHALL use the same display name and canonical route.
2. WHEN the employee opens knowledge, learning, training, action or lesson content, THE system SHALL provide an explicit center label.
3. WHEN a legacy route is requested, THE system SHALL redirect to the corresponding canonical route while preserving query parameters.
4. WHEN an entry is unavailable, THE system SHALL display a useful recovery link to the owning center.

## Scope Boundary

本阶段聚焦内网员工端、管理中心、知识卡融合和全局搜索。公开官网、小程序业务流程、招聘模块和权限体系保持现有边界；真实数据库发布由管理审核流程完成。

## Release Decision

本次升级采用统一上线策略。1417 张隔离知识卡、统一入口、双主线分类、关联内容、全局搜索和管理中心能力完成整体验收后，统一切换到员工端可见状态。上线前允许管理员在预发布环境完整检查，员工端持续使用当前正式内容。
