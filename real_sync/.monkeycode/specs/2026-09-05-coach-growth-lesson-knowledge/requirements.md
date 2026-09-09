# 教练教案精进与知识中心一体化需求

## Introduction

本需求把教案上传、专业反馈、知识卡学习、教案审核和知识中心展示合并为一条教练成长链路。教练通过后台上传真实教案，系统结合课程科目、年龄段、班级阶段和教案内容，匹配动作卡、游戏卡、感统知识卡、安全知识卡和教学知识卡，帮助教练持续提高教学设计能力。

第一阶段保留各科目的差异化格式。系统生成独立的《教案修改建议单》，教练根据建议修改原教案并重新上传。系统保留原始教案、建议单、修改后教案和审核记录。

现有知识卡视为已审核内容。系统完成分类、字段补全和展示后直接进入员工知识中心、全局搜索和教案匹配范围，不新增“是否上架”的审核环节。

## Glossary

- **教案原稿**：教练上传的原始 Word 或 Excel 文件。
- **修改建议单**：系统针对教案内容生成的反馈文档，包含问题、原因、建议、知识卡来源和教练处理结果。
- **知识卡**：简短、可阅读、可应用的专业内容，包含概念、应用、注意点和适配年龄。
- **教案上下文**：科目、年龄段、班级阶段、课程目标、活动内容和安全条件。
- **教练成长链路**：上传教案、阅读反馈、学习知识卡、修改教案、提交审核和复盘沉淀的连续过程。

## Requirements

### Requirement 1: 教案上传上下文

**User Story:** AS 教练, I want to enter the key course context before uploading a lesson plan, so that the system can give relevant professional feedback.

#### Acceptance Criteria

1. WHEN a coach starts a lesson submission, THE system SHALL collect subject, age range, class stage, lesson date, lesson title, store and author.
2. WHEN a coach selects an age range such as 3-4 years, THE system SHALL retain the age range as a searchable and matching attribute.
3. WHEN a coach selects a class stage such as beginner, intermediate or advanced, THE system SHALL retain the stage as a searchable and matching attribute.
4. WHEN a coach uploads a Word or Excel lesson plan, THE system SHALL preserve the original file and create an attributable lesson record.
5. IF parsing fails, THE system SHALL preserve the original file and provide a manual lesson description and feedback request path.

### Requirement 2: 第一阶段修改反馈

**User Story:** AS 教练, I want to receive a separate, understandable feedback document, so that I can improve my existing subject-specific lesson format.

#### Acceptance Criteria

1. WHEN lesson parsing or manual entry completes, THE system SHALL generate a modification suggestion document linked to the lesson record and current source version.
2. WHEN the lesson includes a game activity, THE system SHALL identify suitable game-card recommendations and explain the matching reason.
3. WHEN the lesson includes an action or movement activity, THE system SHALL identify suitable action-card or fitness recommendations and explain the matching reason.
4. WHEN the lesson concerns sensory integration, THE system SHALL provide concise sensory-integration knowledge cards related to the selected age and class stage.
5. WHEN the lesson has a safety, progression, equipment or time-allocation issue, THE system SHALL display the issue, suggested adjustment and applicable knowledge-card source.
6. WHEN a coach processes a suggestion, THE system SHALL support accepted, ignored and pending states and record the coach's decision.
7. WHEN a coach downloads the suggestion document, THE system SHALL include the original excerpt, issue, reason, recommendation, reference card and space for the coach's revised content.

### Requirement 3: 教练修改与审核闭环

**User Story:** AS 教练, I want to revise my original lesson using the feedback and resubmit it, so that my improved lesson can be reviewed and retained.

#### Acceptance Criteria

1. WHEN a coach submits a revised lesson, THE system SHALL associate it with the original lesson and suggestion document.
2. WHEN a coach confirms the revised lesson, THE system SHALL run required-field and teaching-safety checks before submission.
3. WHEN a coach submits a confirmed lesson, THE system SHALL create a store-manager review task.
4. WHEN a store manager approves a lesson, THE system SHALL create a teaching-supervisor review task.
5. WHEN a teaching supervisor approves a lesson, THE system SHALL preserve the approved version and place it in the approved lesson library.
6. WHEN a reviewer returns a lesson, THE system SHALL require a reason and allow the coach to upload a new revision.
7. WHILE a submitted version is under review, THE system SHALL keep that version unchanged and provide a separate revision version after return.

### Requirement 4: 知识卡分类与展示

**User Story:** AS a coach, I want to see concise professional knowledge cards in the knowledge center, so that I can learn while preparing lessons.

#### Acceptance Criteria

1. WHEN existing knowledge cards are imported into the new catalog, THE system SHALL classify every card into a professional or sales primary line and an accessible subcategory.
2. WHEN an existing knowledge card passes the import field check, THE system SHALL make the card available to employee lists, detail pages, global search and eligible lesson recommendations.
3. WHEN a coach opens professional knowledge, THE system SHALL display every professional knowledge section and every published knowledge card available to the coach's role.
4. WHEN a coach opens a knowledge card, THE system SHALL display concept, application, points of attention and applicable age range.
5. WHEN age suitability cannot be determined from the source, THE system SHALL display “全年龄段，需教练现场评估”.
6. WHEN a knowledge card represents an action, game, sensory-integration topic, assessment, safety item or lesson reference, THE system SHALL retain the content type and show the card under the corresponding professional section.

### Requirement 5: 组合搜索

**User Story:** AS a coach, I want to search with natural combinations such as “3-4岁游戏”, so that I can immediately find usable teaching material.

#### Acceptance Criteria

1. WHEN a user searches an age expression such as “3-4岁”, THE system SHALL identify the age range and prioritize content cards matching that range.
2. WHEN a user searches a content type such as “游戏”, THE system SHALL prioritize game cards and include related action, safety and teaching cards.
3. WHEN a user searches a combined query such as “3-4岁游戏” or “感统体操动作”, THE system SHALL apply all recognized conditions together and return matching cards before broad keyword results.
4. WHEN a search query includes a synonym, alias or common expression, THE system SHALL expand the query using configured business vocabulary.
5. WHEN search results are returned, THE system SHALL display age range, professional category, content type, matching reason and canonical detail route.
6. WHEN no exact combination match exists, THE system SHALL display the closest same-age or same-content-type results and explain the relaxed matching condition.
7. WHEN a user opens a search result, THE system SHALL provide a link to related knowledge cards, relevant lesson references and the lesson upload workspace when the result supports those relations.

### Requirement 6: 后台核心功能验收

**User Story:** AS an operations administrator, I want to verify the key management functions through one acceptance checklist, so that the coach growth chain remains usable in production.

#### Acceptance Criteria

1. WHEN an authorized user opens fitness assessment and exercise planning, THE system SHALL load the form, save valid data, show validation feedback and display the saved assessment or plan.
2. WHEN an authorized user opens recruitment, THE system SHALL load candidate records, support the existing permitted status operations and display operation results.
3. WHEN a coach uploads a lesson, THE system SHALL show upload progress, source metadata, parsing state, feedback state and the next available action.
4. WHEN a reviewer opens a lesson review task, THE system SHALL display the submitted version, feedback handling state, source file and review controls.
5. WHEN any one of the three functions fails, THE system SHALL show an actionable error and preserve the user's recoverable data.

### Requirement 7: 可追溯性与学习沉淀

**User Story:** AS a teaching supervisor, I want to understand how feedback changes lesson quality, so that the organization can improve teaching standards over time.

#### Acceptance Criteria

1. THE system SHALL associate each suggestion with the lesson source version, matched knowledge card and coach decision.
2. THE system SHALL preserve original, revised, returned and approved lesson versions.
3. WHEN a coach opens a recommendation, THE system SHALL record the related knowledge-card view for learning analysis.
4. WHEN an approved lesson is published to the lesson library, THE system SHALL preserve its approved version and related knowledge references.
5. WHEN administrators inspect lesson improvement data, THE system SHALL display suggestion counts, accepted suggestions, returned reasons and commonly viewed knowledge categories.

### Requirement 8: 知识卡与建议单交付格式

**User Story:** AS a coach, I want knowledge cards and lesson feedback documents to use a consistent readable format, so that I can understand and apply every recommendation without interpreting inconsistent layouts.

#### Acceptance Criteria

1. WHEN a knowledge card is displayed, THE system SHALL present title, category, content type, applicable age, concept, application, points of attention and related links in a fixed order.
2. WHEN a knowledge card is displayed in a lesson feedback context, THE system SHALL show a concise summary first and provide the full card detail through an explicit expand or detail action.
3. WHEN a knowledge card has missing age information, THE system SHALL display the agreed fallback text “全年龄段，需教练现场评估” in the same position as a normal age range.
4. WHEN a modification suggestion is displayed, THE system SHALL present lesson location, current content, issue, reason, recommendation, knowledge-card basis, processing state and revised-content field in a fixed order.
5. WHEN a modification suggestion document is exported, THE system SHALL include a cover section, lesson metadata, numbered suggestion records, coach processing fields and a final checklist.
6. WHEN an Excel suggestion document is exported, THE system SHALL keep the original lesson sheets unchanged and place suggestions in a separate sheet named “修改建议”.
7. WHEN a Word suggestion document is exported, THE system SHALL use headings, tables and page breaks to keep each suggestion record readable and prevent fields from being split ambiguously across pages.
8. WHEN a card or suggestion contains long text, THE system SHALL wrap text, preserve section labels and keep the action buttons or processing fields visible.
9. WHEN a card or suggestion is unavailable, THE system SHALL show a clear unavailable state and retain the source title, identifier and recovery route.

## Scope Decisions

- Phase 1 uses a separate modification suggestion document and preserves subject-specific original formats.
- Phase 1 supports Word and Excel source files and allows re-upload of the coach's revised file.
- Phase 2 introduces common fields and subject-specific templates after enough real lesson data has accumulated.
- Existing knowledge cards are treated as approved for this release; classification and field checks lead directly to employee visibility.
- Knowledge-card content is concise and practical: concept, application, points of attention and applicable age.
- Search supports combined age, content type, domain, stage and keyword conditions.
- Knowledge cards and modification suggestion documents follow the fixed presentation and export format defined in Requirement 8.
