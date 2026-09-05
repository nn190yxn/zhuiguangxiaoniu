# 教案上传、优化与审核需求

## Introduction

本功能为教练提供 Excel 和 Word 教案的上传、内容录入、ACE 优化建议、在线修改、导出和审核闭环。系统保留原始文件，并将可识别内容映射到统一教案结构，供教练、店长和教学主管协作处理。

PDF 不属于本期支持格式。

## Glossary

- **原始教案**：教练上传的 Excel 或 Word 文件，系统以附件形式保留。
- **结构化教案**：系统将原始内容映射后的可编辑教案数据。
- **优化建议**：系统基于 ACE 规范、教学标准和已发布知识卡生成的修改提示与推荐内容。
- **最终版本**：教练确认修改后提交审核的结构化教案版本。
- **店长初审**：门店负责人对教案完整性、可执行性和门店条件进行审核。
- **教学主管终审**：教学主管对 ACE 对齐、专业性、安全性和课程标准进行最终审核。

## Requirements

### Requirement 1: 上传与录入

**User Story:** AS 教练, I want to upload a Word or Excel lesson plan and enter store and author information, so that the system can create an attributable lesson record.

#### Acceptance Criteria

1. WHEN a coach opens the lesson submission page, THE system SHALL require store name, author name, course line, class or level, lesson date, and lesson title before submission.
2. WHEN a coach uploads a `.xlsx`, `.xls`, `.docx`, or `.doc` file, THE system SHALL save the original filename, file type, file size, uploader, store, author, and upload timestamp.
3. WHEN a coach uploads a file with an unsupported extension, THE system SHALL display the supported formats and keep the lesson record in an editable draft state.
4. WHEN a coach submits an Excel workbook, THE system SHALL identify workbook sheets and map supported sheet content into the structured lesson fields.
5. WHEN a coach submits a Word document, THE system SHALL identify paragraphs, headings, lists, and tables and map supported content into the structured lesson fields.

### Requirement 2: 内容解析与优化建议

**User Story:** AS 教练, I want the system to identify missing or weak lesson-plan content and recommend improvements, so that I can produce a stronger ACE lesson plan.

#### Acceptance Criteria

1. WHEN file parsing succeeds, THE system SHALL display the parsed structured lesson beside the original file metadata.
2. WHEN the structured lesson is missing a required field, THE system SHALL identify the field and provide a completion prompt.
3. WHEN the structured lesson contains lesson activities, THE system SHALL recommend relevant published actions, games, equipment, safety items, and progression options from the knowledge base.
4. WHEN the structured lesson is evaluated, THE system SHALL check the A, C, and E objectives, lesson phases, time allocation, safety plan, equipment list, and post-lesson reflection.
5. WHEN optimization completes, THE system SHALL show every recommendation with its reason, source type, and referenced knowledge-card identifier when available.

### Requirement 3: 修改与确认

**User Story:** AS 教练, I want to edit the parsed lesson directly in the backend and review the suggestions before submission, so that I can finish revisions efficiently.

#### Acceptance Criteria

1. WHEN a structured lesson is available, THE system SHALL provide editable fields for basic information, ACE objectives, learner focus, safety plan, equipment, lesson phases, assistant-coach responsibilities, and post-lesson reflection.
2. WHEN a coach accepts or rejects an optimization suggestion, THE system SHALL record the coach decision and update the structured lesson draft.
3. WHEN a coach saves changes, THE system SHALL create a new draft version with editor, timestamp, and changed-field summary.
4. WHEN a coach confirms the lesson, THE system SHALL validate required fields and show unresolved optimization findings before enabling submission.
5. WHEN a coach exports a confirmed lesson, THE system SHALL generate a standard Excel or Word document containing the current structured lesson content.

### Requirement 4: 审核流程

**User Story:** AS 门店负责人或教学主管, I want to review a confirmed lesson plan and return or approve it with comments, so that only reviewed lessons enter the executable lesson library.

#### Acceptance Criteria

1. WHEN a coach submits a confirmed lesson, THE system SHALL create a review task for the corresponding store manager and set the lesson status to `store_review`.
2. WHEN a store manager approves a lesson, THE system SHALL create or activate the teaching-supervisor review task and set the lesson status to `supervisor_review`.
3. WHEN a reviewer returns a lesson, THE system SHALL require a return reason, set the status to `returned`, and make the lesson editable by the coach.
4. WHEN a teaching supervisor approves a lesson, THE system SHALL set the status to `approved`, preserve the approved version, and make the lesson available in the coach lesson library.
5. WHEN a reviewer handles a task, THE system SHALL record reviewer identity, role, decision, comments, timestamp, reviewed version, and status transition.
6. WHILE a lesson is under review, THE system SHALL prevent edits to the submitted version and provide a separate revision path after return.

### Requirement 5: 版本、导出与留痕

**User Story:** AS 运营管理人员, I want to inspect the original file, all structured versions, suggestions, exports, and review records, so that the lesson process remains traceable.

#### Acceptance Criteria

1. THE system SHALL retain the original uploaded file and every submitted structured version.
2. THE system SHALL associate each optimization suggestion with the version on which the suggestion was generated.
3. THE system SHALL associate each exported document with the structured version used to produce the document.
4. WHEN a user views the lesson history, THE system SHALL display version number, status, editor, reviewer, timestamps, and decision comments.
5. WHEN a parsing or export operation fails, THE system SHALL retain the source record, show an actionable error, and allow manual structured entry.

## Initial Scope Decisions

- Primary input: Excel and Word.
- Excel formats: `.xlsx` and `.xls`; CSV may remain an import utility format for knowledge-card data and does not define the lesson submission experience.
- Word formats: `.docx` and `.doc`.
- PDF: deferred from the first release.
- Editing mode: structured backend editor as the primary workflow; original-file download and standard-format export as supporting operations.
- Approval order: coach submission -> store manager review -> teaching supervisor review -> approved lesson library.
