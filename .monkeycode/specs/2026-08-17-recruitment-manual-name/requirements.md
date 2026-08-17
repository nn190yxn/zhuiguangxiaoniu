# Requirements Document

## Introduction

招聘初筛人员需要在候选人详情中人工补充候选人姓名。

## Requirements

### Requirement 1

**User Story:** AS 招聘初筛人员, I want 手动补充候选人姓名, so that 候选人信息可以完整展示和检索。

#### Acceptance Criteria

1. WHEN 初筛人员在候选人详情输入姓名并保存，系统 SHALL 更新候选人姓名并刷新详情展示。
2. IF 姓名为空，系统 SHALL 提示初筛人员输入姓名。
3. WHEN 系统保存姓名，系统 SHALL 记录操作审计。
