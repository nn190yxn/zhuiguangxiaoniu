# Requirements Document

## Introduction

优化员工端智能运动规划报告，使报告稳定使用完整体测数据、教练信息和训练目标生成逐项分析，并在 AI 服务异常时明确提示当前报告状态。

## Glossary

- **体测项目**：当前年龄段配置中的单项测试及其数值、单位和评级。
- **AI 报告**：由统一 AI Runtime 生成的运动规划内容。
- **本地兜底报告**：AI 调用失败后由浏览器生成的可用报告。
- **逐项分析**：针对每个已录入体测项目说明当前表现、训练含义和建议方向。

## Requirements

### Requirement 1: 完整体测分析

**User Story:** AS 教练, I want the report to explain each measured item, so that I can show parents the data-based reason for every recommendation.

#### Acceptance Criteria

1. WHEN a report contains one or more valid measured items, THE report generator SHALL provide each valid item's name, value, unit and rating to the report analysis.
2. WHEN a measured item is rated below the configured standard, THE report analysis SHALL explain the item's training implication and include a corresponding improvement direction.
3. WHEN a measured item is rated excellent or good, THE report analysis SHALL identify the item's strength and provide a maintenance or progression direction.
4. WHEN a measured item has no valid value, THE report generator SHALL exclude that item from numeric analysis and label the data as incomplete where relevant.

### Requirement 2: 个性化规划约束

**User Story:** AS 教练, I want coach-entered context and goals to influence the report, so that the plan reflects the child's actual situation.

#### Acceptance Criteria

1. WHEN coach notes, current-cycle focus, next-cycle focus or custom goals contain content, THE report generator SHALL preserve those inputs in the planning context.
2. WHEN coach notes define a course restriction, THE report generator SHALL apply the restriction to course recommendations.
3. WHEN the report is generated, THE report SHALL include measurable stage goals, training frequency and a review point derived from the available inputs.

### Requirement 3: AI 故障可见性与兜底质量

**User Story:** AS 教练, I want to know whether the report came from AI or a fallback, so that I can judge whether it is ready to send to a parent.

#### Acceptance Criteria

1. IF the AI report request fails, THE page SHALL display a clear fallback status near the report and SHALL keep the report usable.
2. WHEN the fallback report is rendered, THE fallback report SHALL include measured item details, strengths, weaknesses, targeted training directions and coach-entered goals.
3. WHEN the AI report request succeeds with empty content, THE page SHALL treat the result as a failure and SHALL render the fallback status.

### Requirement 4: 生成长度与可观测性

**User Story:** AS a product operator, I want report generation failures and output size to be diagnosable, so that quality regressions can be distinguished from provider failures.

#### Acceptance Criteria

1. WHEN the report endpoint returns a successful response, THE endpoint SHALL return whether content is present and the generated content length in a non-sensitive metadata field.
2. WHEN the report endpoint fails, THE endpoint SHALL preserve the existing stable error response and request identifier.
3. WHEN the report page renders a fallback, THE page SHALL retain the failure reason in console diagnostics without exposing credentials or provider response bodies.
