# 招聘简历按日期分表发布清单

## 本次发布范围

- `real_sync/api/admin/recruitment/services/RecruitmentExportService.php`
- `real_sync/admin/recruitment-resumes.html`
- `real_sync/scripts/recruitment_resume_export.test.mjs`

上一轮招聘队列修复文件和工作区中的 PDF/ZIP 保留文件属于其他变更范围，不纳入本次发布。

## 发布前门禁

- `php -l api/admin/recruitment/services/RecruitmentExportService.php`
- `php -l api/admin/recruitment/export.php`
- `node --test scripts/recruitment_resume_export.test.mjs`
- `git diff --check`
- 生成测试工作簿并核验 `总览`、日期工作表、岗位工作表、未归类工作表及 XLSX 关系文件。

## 生产发布步骤

1. 记录生产代码版本和当前导出服务文件摘要。
2. 备份本次发布范围内的生产文件到带时间戳目录。
3. 分批上传服务文件、后台页面和测试文件，保持生产文件权限。
4. 在生产环境执行 PHP 语法检查。
5. 使用一个日期范围执行小范围导出，核验日期工作表名称、记录数量、处理状态和岗位顺序。
6. 核验导出下载响应和操作审计记录。
7. 保留观察窗口和备份路径，完成发布记录。

## 回滚

1. 停止继续使用新导出入口。
2. 从本次发布备份恢复三个发布文件。
3. 重新执行 PHP 语法检查和招聘导出回归测试。
4. 使用同一日期范围重新导出，确认旧工作簿结构恢复。

## 本次发布记录

- 生产备份目录：`/root/zx-recruitment-date-sheets-backups/20260831-112938/`
- 本地与生产三份发布文件 SHA-256 摘要一致。
- 生产 PHP 语法检查通过。
- 生产招聘导出契约测试 `7/7` 通过。
- 未登录访问导出接口返回 HTTP 401，认证边界正常。
- 真实管理员登录态下的小范围导出验收待后续业务账号操作完成。
