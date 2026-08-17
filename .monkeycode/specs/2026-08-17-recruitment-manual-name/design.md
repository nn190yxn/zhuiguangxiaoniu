# Recruitment Manual Name

Feature Name: recruitment-manual-name
Updated: 2026-08-17

## Description

在候选人详情的人工补充信息卡中提供姓名输入和保存操作。

## Components and Interfaces

- `admin/recruitment-resumes.html` 渲染姓名输入框并调用候选人联系接口。
- `candidate-contact.php` 接收 `update_name` 操作并记录审计。
- `ResumeReviewService::updateName` 在事务中更新候选人姓名和当前申请的提取档案。

## Error Handling

- 空白姓名返回可读错误信息。
- 权限范围沿用候选人详情访问范围。

## Test Strategy

- 检查页面保存动作、接口动作和服务方法。
