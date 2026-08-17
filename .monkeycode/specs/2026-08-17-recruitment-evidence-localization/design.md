# Recruitment Evidence Localization

Feature Name: recruitment-evidence-localization
Updated: 2026-08-17

## Description

候选人详情将内部规则编号和状态码转换为中文展示内容。

## Components and Interfaces

- `ResumeReviewService` 为详情接口的匹配证据生成中文规则名称。
- `recruitment-resumes.html` 映射匹配状态中文名称。

## Correctness Properties

- 展示层转换保留原始分数、页码和评分逻辑。

## Test Strategy

- 工作台静态测试覆盖规则名称和状态映射。
