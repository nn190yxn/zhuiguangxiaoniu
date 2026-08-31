# 招聘简历按日期分表导出设计

Feature Name: recruitment-date-sheets
Updated: 2026-08-31

## Description

本功能扩展现有招聘 XLSX 导出服务。系统继续使用现有导出筛选、权限和联系方式字段，以简历文档 `created_at` 的业务日期作为分组键，为每个有记录的日期生成一个工作表。日期工作表使用统一列结构承载已归类、待确认和处理失败简历；总览工作表继续提供全量核对入口，岗位顺序使用 `organization_positions.sort_order`。

## Architecture

```mermaid
flowchart LR
    Page["招聘简历工作台"] --> API["导出接口"]
    API --> Service["RecruitmentExportService"]
    Service --> Query["按文档日期查询全量简历"]
    Query --> Group["按 YYYY-MM-DD 分组"]
    Group --> Sort["按岗位配置顺序排序"]
    Sort --> Workbook["总览与日期工作表"]
    Workbook --> Storage["短期导出存储"]
```

## Components and Interfaces

### `RecruitmentExportService`

- `create()` 继续负责规范化查询、权限范围、导出任务记录和文件存储。
- 查询层需要返回源简历文档 ID、简历收到时间、岗位 ID、岗位名称、岗位配置顺序、候选人字段和处理状态。
- 已归类候选人、待确认简历和处理失败简历需要统一转换为日期工作表行；缺失字段使用空值。
- 日期分组使用业务时区格式化后的 `created_at` 日期。
- 日期工作表行排序键为：已归类标记、岗位 `sort_order`、岗位 ID、`created_at` 升序、文档 ID 升序。
- 未归类记录使用末位排序，并保留“待确认”或“处理失败”状态。
- `writeWorkbook()` 接收按日期分组后的结构，写入总览、日期工作表和兼容性岗位工作表。

### `api/admin/recruitment/export.php`

- 沿用现有 POST 参数：`scope_mode`、`requirement_id`、`batch_id`、`grade`、`date_from`、`date_to`。
- 幂等请求摘要继续保存日期范围和行数。
- 下载响应继续使用 XLSX MIME 类型、短期缓存禁用和安全文件名。

### `admin/recruitment-resumes.html`

- 保留现有日期起止筛选和导出范围选择。
- 导出按钮文案应说明“按简历收到日期分表，包含当天全部简历”。
- 页面继续使用当前批次、全部批次和权限范围逻辑。

## Workbook Structure

工作表顺序固定为：

1. `总览`：所有日期记录，沿用统一候选人导出列。
2. 日期工作表：按日期升序，例如 `2026-08-30`、`2026-08-31`。
3. 兼容性岗位工作表：按岗位配置顺序生成，供现有按岗位查看习惯使用。
4. `未归类确认`：集中保留未归类记录的兼容性视图。

日期工作表采用统一列结构。现有 27 列继续保留，并增加 `处理状态` 列用于区分已归类、待确认和处理失败记录。已归类记录填写完整候选人字段；未归类记录将可获得的姓名、手机号、来源文件、AI 建议岗位、亮点和简历收到日期映射到对应字段，其余字段写入空值；处理状态写入 `处理状态` 列。

## Data Models

### Internal export row

```text
{
  document_id: integer,
  received_date: string,
  received_at: string,
  requirement_id: integer|null,
  requirement_name: string,
  position_sort_order: integer|null,
  classification_status: string,
  values: array<string>
}
```

### Grouped workbook input

```text
{
  overview: ExportRow[],
  dates: {
    "YYYY-MM-DD": ExportRow[]
  },
  requirements: {
    "岗位名称": ExportRow[]
  },
  unclassified: ExportRow[]
}
```

### Database sources

- `recruitment_resume_documents.created_at`：日期分组和同岗位时间排序。
- `recruitment_resume_documents.classification_status`：识别待确认和处理失败记录。
- `recruitment_applications`：已归类候选人和评分字段。
- `recruitment_requirements.position_id`：关联岗位配置。
- `organization_positions.sort_order`：岗位配置顺序。
- `recruitment_export_jobs`：导出任务条件、字段版本、排序版本和行数。

## Correctness Properties

- 每个日期工作表的日期等于该行源文档 `created_at` 的业务日期。
- 日期工作表记录集合覆盖查询范围内的全部源简历文档，每个文档最多生成一行。
- 对任意两个已归类岗位，`sort_order` 较小的岗位行出现在另一岗位之前。
- `sort_order` 相同时，岗位 ID 较小的岗位行出现在另一岗位之前。
- 已归类岗位行全部出现在未归类行之前。
- 每个工作表的列数等于其表头列数，且每行字段数量一致。
- `workbook.xml`、关系文件、内容类型文件和 worksheet 文件的数量保持一致。
- 导出任务的 `row_count` 等于所有日期工作表数据行数量与兼容性视图数据行数量之间的业务定义值；建议按唯一源简历文档计数，避免兼容性工作表重复计算。
- 联系信息权限、公式安全处理和导出文件路径校验继续满足现有安全约束。

## Error Handling

- 日期格式不符合 `YYYY-MM-DD` 时返回现有可读参数错误。
- 日期起止顺序无效时返回现有可读参数错误。
- 岗位配置不存在时使用岗位 ID 和名称稳定排序，并记录降级状态，保持导出可完成。
- 查询中的处理失败文档缺少解析字段时保留来源文件和收到日期，缺失字段写入空值。
- XLSX 工作表名称冲突时使用现有安全命名与后缀策略。
- ZIP 扩展不可用、文件创建失败或写入失败时，导出任务标记为 `failed` 并保留错误摘要。
- 生成空结果时输出至少一个带表头的 `总览` 工作表。

## Test Strategy

### Static contract tests

- 验证日期分组使用文档创建时间的日期部分。
- 验证查询包含处理失败状态和未归类文档。
- 验证岗位排序使用 `organization_positions.sort_order` 与岗位 ID。
- 验证日期工作表命名、总览工作表和兼容性工作表结构。
- 验证导出页面文案、范围参数、权限检查和日期参数保持兼容。

### XLSX archive tests

- 构造两个日期、多个岗位、未归类和失败记录，验证工作表数量、名称和顺序。
- 验证每个日期工作表包含当天全部源文档，且同一文档不会重复出现。
- 验证岗位配置顺序优先于简历收到时间。
- 验证未归类记录排列在已归类岗位之后。
- 验证统一表头、处理状态列、公式安全和 `AA` 之后新增列的单元格结构。
- 验证空数据导出生成可读取工作簿。

### Regression tests

- 运行现有 `scripts/recruitment_resume_export.test.mjs`。
- 在 PHP 运行环境执行 `php -l` 检查导出接口和服务文件。
- 使用标准 ZIP/XML 读取方式核验 workbook、rels、content types 和 worksheet 相互匹配。

## Rollback

- 代码变更限定在导出服务、导出接口、后台导出文案和导出测试。
- 发布前备份所有变更文件。
- 发生回归时恢复导出相关文件并重新执行 PHP 语法检查和 XLSX 结构测试。
- 不修改数据库表结构；`recruitment_export_jobs` 继续复用现有字段。

## References

- `real_sync/api/admin/recruitment/services/RecruitmentExportService.php:30`：现有导出任务创建入口。
- `real_sync/api/admin/recruitment/services/RecruitmentExportService.php:105`：现有候选人查询和录入日期过滤。
- `real_sync/api/admin/recruitment/services/RecruitmentExportService.php:205`：现有未归类简历查询。
- `real_sync/api/admin/recruitment/services/RecruitmentExportService.php:395`：现有 XLSX 工作簿生成逻辑。
- `real_sync/api/admin/recruitment/export.php:14`：现有导出 API 参数入口。
- `real_sync/admin/recruitment-resumes.html:70`：现有日期筛选和导出控件。
- `real_sync/scripts/recruitment_resume_export.test.mjs:50`：现有多工作表 XLSX 回归测试。
- `real_sync/database/migrations/202607240001_staff_organization.sql:162`：岗位配置顺序字段。
