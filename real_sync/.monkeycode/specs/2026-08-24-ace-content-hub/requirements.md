# Requirements Document

## Introduction

本功能为追光小牛官网建立 ACE 教学体系内容专题，向家长、教练和搜索服务清晰解释 ACE 的正式定义、课堂应用、评估方法与使用边界。

## Glossary

- **ACE**：由 Athletic（运动能力）、Cognitive（认知能力）、Engagement（参与动能）组成的三维课堂观察与教学评估框架。
- **课堂观察**：教练在真实课堂中记录学员动作表现、理解过程和参与状态的过程。
- **阶段评估**：结合一段时间内的课堂观察与相关测评，判断训练变化和下一阶段重点的过程。
- **资讯索引**：集中展示官网公开文章并提供稳定内部链接的页面。
- **结构化数据**：使用 Schema.org JSON-LD 描述文章、常见问题、面包屑和文章列表的数据。

## Requirements

### Requirement 1

**User Story:** AS 家长, I want 准确理解 ACE 三个维度, so that 我能理解课程关注的成长信息。

#### Acceptance Criteria

1. WHEN 访问 ACE 总览文章时，官网 SHALL 展示 Athletic、Cognitive、Engagement 的英文全称、中文含义与观察重点。
2. WHEN 解释 ACE 用途时，官网 SHALL 将 ACE 描述为课堂观察、教学设计和阶段评估框架。
3. WHEN 展示 ACE 结论边界时，官网 SHALL 明确课堂观察和体测不能替代医疗、康复、心理或教育诊断。
4. WHEN 描述单次观察结果时，官网 SHALL 说明单次结果反映当下状态并需要结合持续观察理解。

### Requirement 2

**User Story:** AS 家长或教练, I want 了解 ACE 在课堂中的使用方式, so that 我能理解教练如何根据观察调整教学。

#### Acceptance Criteria

1. WHEN 访问课堂观察文章时，官网 SHALL 分别列出 A、C、E 三个维度的可观察课堂信号。
2. WHEN 描述课堂调整时，官网 SHALL 说明动作难度、规则表达、情绪和参与节奏之间的对应关系。
3. WHEN 描述课后反馈时，官网 SHALL 给出当前表现、核心问题、训练建议和后续观察点四部分结构。

### Requirement 3

**User Story:** AS 家长, I want 区分体测、课堂观察和阶段评估, so that 我能正确理解每类结果的用途。

#### Acceptance Criteria

1. WHEN 访问评估指南文章时，官网 SHALL 分别说明基础体测、课堂观察和阶段复评的信息范围。
2. WHEN 解释完整评估时，官网 SHALL 包含优势、短板、优先训练方向、家长可理解说明和后续建议。
3. WHEN 解释评估结果时，官网 SHALL 使用可观察事实和变化趋势，避免绝对化能力判断。

### Requirement 4

**User Story:** AS 访客, I want 从统一入口浏览公开资讯, so that 我能发现 ACE 专题及其他官网文章。

#### Acceptance Criteria

1. WHEN 访问资讯索引时，官网 SHALL 展示 ACE 专题入口和全部现有公开文章链接。
2. WHEN 访问首页资讯区域时，官网 SHALL 展示三篇 ACE 文章并提供资讯索引入口。
3. WHEN 访问任一 ACE 文章时，官网 SHALL 提供返回资讯索引及访问另外两篇 ACE 文章的内部链接。
4. WHILE 使用窄屏设备浏览时，官网 SHALL 使用单列或自适应布局保持正文、卡片和链接可读。

### Requirement 5

**User Story:** AS 搜索服务, I want 获取清晰的页面元数据和站点关系, so that 我能正确索引并理解 ACE 专题。

#### Acceptance Criteria

1. WHEN 抓取 ACE 文章时，官网 SHALL 提供唯一 title、description、canonical、Open Graph 和发布日期。
2. WHEN 解析 ACE 文章时，官网 SHALL 提供 Article、FAQPage 和 BreadcrumbList JSON-LD。
3. WHEN 抓取资讯索引时，官网 SHALL 提供 CollectionPage 和 ItemList JSON-LD。
4. WHEN 抓取 sitemap.xml 时，官网 SHALL 找到资讯索引和三篇 ACE 文章的规范 URL 与最近修改日期。
5. IF 页面包含站内链接，官网 SHALL 使用能够映射到工作区公开文件的绝对路径形式。
