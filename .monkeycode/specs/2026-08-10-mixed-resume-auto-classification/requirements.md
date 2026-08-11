# 混合简历批量上传与自动岗位分类需求

## Introduction

招聘人员出差或集中收件时，可以一次上传来自多个岗位的简历。系统先保存原始简历，再根据文件名、简历正文、岗位规则和招聘需求自动判断岗位归属，降低上传前逐个选择岗位的操作成本。

## Glossary

- **混合简历批次**：一次上传中允许包含多个岗位候选人的简历批次。
- **候选岗位集合**：当前招聘人员授权范围内，具备可用岗位规则的招聘需求集合。
- **自动分类**：系统根据文件名、简历识别内容和岗位规则，为简历生成一个或多个岗位候选结果。
- **岗位明确**：文件名唯一包含最长的候选岗位名称，或文件名未形成唯一结果且简历中的当前或最近岗位名称唯一包含最长的候选岗位名称；专业方向名称包含通用岗位名称时，系统将专业方向归入该通用岗位；同长度的多个岗位名称属于无法唯一判断。
- **待确认简历**：系统无法形成唯一岗位判断，需要人工选择岗位的简历。

## Requirements

### Requirement 1: 混合简历批量接收

**User Story:** AS 招聘人员, I want to upload resumes from multiple positions in one batch, so that I can process concentrated resume collections while traveling.

#### Acceptance Criteria

1. WHEN 招聘人员打开简历上传页面, THE system SHALL allow creating a mixed resume batch without selecting one fixed recruitment requirement.
2. WHEN 招聘人员 uploads files, THE system SHALL accept resumes belonging to multiple recruitment positions in the same batch.
3. WHEN 招聘人员 submits a mixed batch, THE system SHALL preserve the original filename, upload order, file hash, upload operator, batch identifier and upload time for every file.
4. IF the mixed batch contains no readable or valid files, THE system SHALL return a clear file-level error summary.

### Requirement 2: 自动岗位分类

**User Story:** AS 招聘人员, I want the system to classify each resume by position automatically, so that I can review results instead of selecting a position before every upload.

#### Acceptance Criteria

1. WHEN a resume enters processing, THE system SHALL compare the filename and extracted current or latest role against the candidate position set.
2. WHEN the filename uniquely identifies one candidate position, THE system SHALL assign the resume to that position and record filename evidence.
3. WHEN the filename does not uniquely identify a position and the current or latest role uniquely identifies one candidate position, THE system SHALL assign the resume to that position and record profile-role evidence.
4. WHEN the direct position signals do not identify exactly one candidate position, THE system SHALL record ranked position candidates and mark the resume as requiring confirmation.
5. WHEN a position is uniquely identified, THE system SHALL continue the resume through matching and A/B/C grading for the assigned position.
6. WHEN a resume position name contains one candidate position name as a specialization suffix or prefix, THE system SHALL assign the resume to the uniquely longest matching candidate position name.

### Requirement 3: 候选岗位集合

**User Story:** AS 招聘管理人员, I want the system to use the recruitment needs already configured in the backend, so that uploading resumes does not require selecting and approving a position for each batch.

#### Acceptance Criteria

1. WHEN a mixed batch is created, THE system SHALL derive the candidate position set from the operator's authorized recruitment scope and currently usable position rules.
2. WHEN a position has no usable matching rule, THE system SHALL exclude the position from automatic classification and show the exclusion reason in the batch status.
3. WHEN the operator has no authorized candidate positions, THE system SHALL return an actionable message that identifies the missing recruitment configuration or permission.
4. WHEN a mixed batch is processed, THE system SHALL keep the original batch independent from later changes to the candidate position set.

### Requirement 4: 人工复核与重新分类

**User Story:** AS 招聘人员, I want to correct low-confidence classifications, so that every resume can enter the proper position queue.

#### Acceptance Criteria

1. WHEN a resume is marked as requiring confirmation, THE system SHALL display the ranked position candidates, evidence摘要, filename and current processing status.
2. WHEN an authorized operator selects a position, THE system SHALL record the operator, decision reason, selected position and decision time.
3. WHEN an operator requests reclassification, THE system SHALL create a new classification version and preserve the previous result.
4. WHEN a resume remains unclassified, THE system SHALL keep it available in a dedicated review queue.

### Requirement 5: 上传失败与状态反馈

**User Story:** AS 招聘人员, I want upload failures to explain the actual cause, so that I can recover without guessing.

#### Acceptance Criteria

1. WHEN batch creation fails, THE system SHALL return a stable error code and a human-readable message describing the failed validation stage.
2. WHEN a batch fails because the position selection is unnecessary for mixed intake, THE system SHALL offer the mixed batch path instead of requiring a single position.
3. WHEN an individual file fails, THE system SHALL show the original filename, failure stage, failure reason and retry action.
4. WHEN a request is retried with the same idempotency key, THE system SHALL return the first completed result or the original processing status.

### Requirement 6: 数据安全与审计

**User Story:** AS 招聘管理人员, I want mixed intake and classification decisions to remain auditable, so that resume handling remains traceable.

#### Acceptance Criteria

1. WHEN a mixed batch is created, THE system SHALL record the operator, authorized scope, candidate position set version and batch creation result.
2. WHEN automatic classification completes, THE system SHALL record the selected position, candidate rankings, confidence level, evidence references and classifier version.
3. WHEN an operator changes a classification, THE system SHALL record before and after results without storing full resume text in operation logs.
4. WHEN a user views mixed batch data, THE system SHALL enforce the user's recruitment data scope for every batch, resume and classification record.

## Open Decisions

1. 候选岗位集合使用当前账号可见的全部招聘岗位。
2. 文件名唯一命中优先于简历当前或最近岗位名称唯一命中；岗位明确后直接进入对应岗位处理队列和 A/B/C 分级，岗位无法唯一判断时进入待确认队列。
3. 岗位需求已录入但岗位规则尚未发布时，系统允许先上传并暂存，岗位规则发布后再执行分类。
4. 现有单岗位批次继续兼容，混合批次作为新的上传模式。
