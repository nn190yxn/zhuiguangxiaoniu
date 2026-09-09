# 教案轻量优化建议设计

Feature Name: lightweight-lesson-optimization
Updated: 2026-09-08

## Description

现有知识匹配能力继续负责候选筛选与内部追溯。用户界面将匹配结果转换为游戏优化、动作优化和遗漏提醒三类具体建议，并通过单击采纳写入当前草稿。

## Components and Interfaces

- `LessonKnowledgeMatcher`：每个环节最多选择两个动作和两个游戏；身体安全计划为空时生成一条遗漏提醒。
- `LessonDraftService`：将内部建议类型转换为稳定的中文展示标题。
- `lesson-submission.js`：保持现有一键采纳接口，停止渲染知识学习侧栏。
- `lesson-submission.html`：展示精简建议区域，隐藏来源、定位、匹配理由和内部状态。

## Correctness Properties

- 安全提醒每个版本最多一条。
- 已填写身体安全计划的版本不生成安全遗漏提醒。
- 建议采纳继续受版本号、作者权限和字段白名单保护。
- 知识条目与知识版本编号继续保存在建议记录中，供审计追溯。

## Error Handling

- 当前教案有未保存内容时，刷新建议提示先保存。
- 建议版本过期时，界面重新加载当前版本。
- 建议生成失败时，已解析教案保持可编辑状态。

## Test Strategy

- 匹配测试覆盖两类替代建议、安全遗漏条件和知识来源隐藏。
- 服务测试覆盖建议去重、采纳、忽略、版本生成和审核约束。
- 页面契约测试覆盖知识侧栏移除和轻量提示文案。
