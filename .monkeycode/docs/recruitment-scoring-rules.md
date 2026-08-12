# 招聘简历评分规则配置说明

## 概述

系统通过三阶段评分流水线评估候选人：**提取(Extract)** → **匹配(Match)** → **评级(Grade)**。规则配置决定每个岗位的评分参数。

## 核心表

| 表名 | 作用 |
|------|------|
| `recruitment_rule_versions` | 岗位评分规则版本（每次发布产生新版本号） |
| `recruitment_match_evidence` | 每位候选人的逐条匹配证据 |
| `recruitment_grade_results` | 候选人最终评分和等级 |
| `recruitment_applications` | 候选人档案（含 `effective_grade`, `total_score`, `grade_snapshot_json`） |

## 评分规则字段 (`recruitment_rule_versions`)

| 字段 | 类型 | 说明 |
|------|------|------|
| `position_name_snapshot` | VARCHAR | 对应岗位名称（规则按此匹配招聘需求） |
| `hard_conditions_json` | JSON | 硬性条件列表，每条含 `keyword`（匹配文本）和 `score`（分值） |
| `experience_rules_json` | JSON | 经验要求，含 `a_min_related_years`（最低相关年限） |
| `keyword_rules_json` | JSON | 关键词列表，含 `required_for_a`（A 级关键词数组，共占 40 分） |
| `grade_rules_json` | JSON | 等级边界，含 `A.min`, `A.max`, `B.min`, `B.max`, `C.min`, `C.max`（默认 A≥35，B≥20，满分约100） |
| `prompt_version` | VARCHAR | 关联的 AI 提取 Prompt 版本号 |
| `job_description` | TEXT | 岗位描述，供 AI 上下文使用 |

## 评分计算逻辑

### 匹配阶段 (`ResumeMatchingService`)

四类维度单独评分后求和（满分约 100 分，实际由关键词数量和技能项数量决定）：

1. **关键词 (keyword)**：原始权重 40 分
   - 来源：`keyword_rules_json.required_for_a` 数组
   - 每条关键词分 = 40 / 关键词数量
   - 匹配方式：先在 AI 提取的结构化 Profile 中搜索，再在原始页面文本中搜索
   - 状态：`matched`（命中） / `unknown`（未命中） / `manual_check`（降级命中）

2. **相关经验 (experience)**：原始权重 40 分
   - 来源：`experience_rules_json.a_min_related_years`（最低相关年限）
   - 得分 = min(40, (候选人年限/要求最低年限) × 40)
   - 状态：`matched` / `unknown` / `manual_check`

3. **可迁移技能 (transferable_skill)**：原始权重 20 分
   - 来源：AI 从简历中提取的 `skills.items` 数组
   - 每条技能分 = min(5, 20 / 技能总数)
   - 自动标记为 `matched`

4. **硬性条件 (hard_condition)**：权重 0 分（否决项）
   - 来源：`hard_conditions_json` 数组
   - 不计入总分，但 `unmatched` 会触发降级
   - 状态：`matched` / `unmatched` / `manual_check`

### 评级阶段 (`ResumeGradeService`)

- 总分 = 关键词得分 + 经验得分 + 技能得分（满分约 100 分，由岗位关键词和技能数量动态决定）
- 若总分 ≥ `a_min_score`（默认 35）：候选 A 级
- 若总分 ≥ `b_min_score`（默认 20）：候选 B 级
- 其余：C 级
- 否决降级：任一 `hard_condition` 为 `unmatched` 时，B 级降至 C 级
- A 级额外要求：经验项和关键词项必须全部非 `unknown`/非 `manual_check`

## 证据存储 (`recruitment_match_evidence`)

每条证据记录：

| 字段 | 说明 |
|------|------|
| `dimension_type` | `hard_condition` / `keyword` / `experience` / `transferable_skill` |
| `rule_key` | 规则标识（如 `hard_0`, `required_2`, `a_min_related_years`, `skill_3`） |
| `match_status` | `matched` / `unmatched` / `unknown` / `manual_check`（释义见降级匹配规则） |
| `score` | 本条贡献分值（`manual_check` 时为原始分值 × 0.5） |
| `source_text` | 简历原文证据（Profile 命中但缺证据时为空） |
| `page_no` | 所在页码（Profile 命中但缺证据时为空） |

## 导出列的规则依赖

Excel 导出中以下列直接来源于评分规则：

| 导出列 | 数据来源 |
|--------|----------|
| 匹配分 | `recruitment_applications.total_score` |
| 等级 | `recruitment_applications.effective_grade` |
| 命中关键词 | `recruitment_match_evidence` 中 `dimension_type=keyword` 且 `match_status` 为 `matched` 或 `manual_check` 的 `rule_key` |
| 硬性条件状态 | `recruitment_match_evidence` 中 `dimension_type=hard_condition` 的 `rule_key:match_status` |
| 匹配分析说明 | `recruitment_applications.grade_snapshot_json.evidence`，自动生成为 `硬条件X/Y满足；命中N项关键词(关键词名)；经验符合/不足` |

## 规则调整步骤

1. 在招聘管理后台进入"岗位规则"页面
2. 找到目标岗位的已发布规则版本
3. 点击"编辑"创建新版本（不会覆盖已发布版本）
4. 调整 `keyword_rules_json` 的关键词列表或 `grade_rules_json` 的分数线
5. 保存草稿后点击"发布"使新规则生效
6. 已处理的简历可点击"重新分类"按新规则重算

## 降级匹配规则

当匹配在 AI 提取的 Profile 中找到目标关键词/经验值，但对应的 `evidence` 子字段（原文证据）为空时，系统自动执行降级容错：

### 场景一：Profile 命中但缺证据

AI 模型正确提取了结构化字段（如姓名、工作年限、技能列表），但该字段自身的 `evidence[0].text` 为空。此时：

- `match_status` 从 `matched` 降为 `manual_check`
- 得分减半（`score × 0.5`）
- `source_text` 和 `page_no` 为空

这样既避免了"AI 提取了但没标出处"时一棍子打死，也保留了人工复核标记。

### 场景二：原始页面命中（非 Profile 命中）

当关键词在 AI 提取的 Profile 中未命中，但在 OCR 原始页面文本中匹配到时：

- `match_status` 设为 `manual_check`
- 得分减半（`score × 0.5`）
- `source_text` 为匹配到的关键词原文
- `page_no` 为匹配所在页码
- `confidence` 设为 0.5

该路径适用于 AI 漏提取但文档中确实存在相关内容的情况。

### 降级后计分

两条降级路径均保留 50% 权重。`manual_check` 状态在导出中同时算作"命中关键词"和"人工核验项"，不影响总分计算，仅在 `unknown_count` 中反映（用于 A 级经验+关键词双门槛判断）。

## 故障排查

### 匹配分析显示"暂无评分数据"

`grade_snapshot_json` 为空或格式异常。检查 `recruitment_grade_results.grade_snapshot_json` 是否成功写入。

### 所有候选人得分偏低（均为 C 级）

检查 `grade_rules_json` 的 `a_min_score` 和 `b_min_score` 是否设置合理。当前默认 A 线 35、B 线 20，满分约 100 分（关键词 40 + 经验 40 + 技能 20）。

### 关键词未命中

检查 `keyword_rules_json.required_for_a` 中的关键词是否过于精确。建议使用简短词组而非完整句子，并覆盖常见同义表述。
