# 技术设计：新签销冠 Skill 深度整合

Feature Name: champion-sales-coach-integration
Updated: 2026-08-23

## Description

本设计将新签 Skill 整合为自由演练的核心评分和对话标准。系统由 AI 家长和销冠教练两个角色组成：AI 家长根据画像与对话上下文制造真实决策阻力；销冠教练根据六维 100 分 Skill 标准评估销售行为，所有评分绑定对话证据。

## 评分口径

| 用户维度 | 权重 | 内部细分指标映射 |
|---|---:|---|
| 需求挖掘 | 20 | needs_discovery |
| FAB 转化 | 25 | fab_conversion、solution_planning |
| 案例植入 | 15 | case_insertion |
| 异议处理 | 15 | objection_handling |
| 试关闭 | 15 | trial_close、部分 pricing_negotiation |
| 紧迫感制造 | 10 | urgency_creation、部分 pricing_negotiation |

当前工作区存在两套正式数据来源：`skills/追光小牛新签复盘/SKILL.md` 定义六维评分；`api/drill/v2/services/DrillNewSignContentPackage.php` 定义八维评分包。八维内容包权重为需求挖掘 15、FAB 价值转化 20、案例植入 15、方案匹配与规划 10、报价与谈判策略 10、异议处理 15、试关闭 10、紧迫感制造 5，总和为 100。

正式实现采用 V2 八维内容包作为唯一用户评分口径，原始六维只保留历史兼容信息。八维流程阶段属于对话推进结构，评分维度属于能力评价结构，二者通过 `mappings()` 关联。

## 提示词架构

### AI 家长提示词

角色要求：扮演真实家长，具有固定孩子画像、家庭顾虑和决策状态。每次围绕一个主要问题自然表达，承接上一轮销售回答，逐步暴露信息和顾虑。客户可以犹豫、追问、暂缓决定，也可以在销售处理得好时给出部分积极反馈。

输入上下文：

- `customer_profile`
- `scenario_goal`
- `current_stage`
- `history`
- `uncovered_actions`
- `conversation_state`

输出字段：`response`、`intent`、`target_action`、`stage_code`、`customer_state`。

### 销冠教练提示词

角色要求：扮演追光小牛资深销冠教练，拥有首次到店体验和新客户报名转化经验。依据新签 Skill 评分，不夸泛泛的亲和力，优先判断销售是否推动成交。

评分顺序：

1. 识别客户购买动机、核心痛点、决策链和当前顾虑。
2. 检查销售是否承接上一轮问题并完成当前动作。
3. 按六个用户维度评分。
4. 检查风险和证据完整性。
5. 给出最影响成交的三个问题、替换话术和训练任务。

输出结构：

```json
{
  "scene_assessment": {},
  "total_score": 0,
  "grade": "unqualified",
  "dimension_scores": {
    "needs_discovery": {"score": 0, "max_score": 20, "evidence": [], "reason": ""},
    "fab_conversion": {"score": 0, "max_score": 25, "evidence": [], "reason": ""},
    "case_insertion": {"score": 0, "max_score": 15, "evidence": [], "reason": ""},
    "objection_handling": {"score": 0, "max_score": 15, "evidence": [], "reason": ""},
    "trial_close": {"score": 0, "max_score": 15, "evidence": [], "reason": ""},
    "urgency_creation": {"score": 0, "max_score": 10, "evidence": [], "reason": ""}
  },
  "critical_risks": [],
  "deal_risk": "",
  "strengths": [],
  "priority_improvements": [],
  "replacement_scripts": [],
  "training_tasks": []
}
```

提示词必须要求模型只引用输入对话中的销售原文，所有证据使用 `turn_id` 定位；无法定位的判断标记为 `insufficient_evidence`。

## 对话状态

每个演练实例维护：

- 客户当前认知状态：`exploring`、`comparing`、`concerned`、`ready`、`deferred`。
- 已披露信息：孩子情况、家庭安排、既往经历、预算、决策人。
- 已处理顾虑：价格、坚持、距离、效果、决策人、竞品。
- 已完成销售动作：六维动作集合。
- 试关闭计数。
- 当前阶段和下一验证目标。

AI 家长根据状态变化生成下一轮问题。销售连续回答中的重复套话不计为新的完成动作。

## 评分计算

AI 输出六维原始分数和证据，服务端执行以下校验：

- 每个维度分数在对应满分范围内。
- 六维分数之和等于总分。
- 每个已得分维度至少有一条有效销售原文证据。
- 试关闭根据可定位次数评分。
- 案例必须同时检查匹配度和案例完整性。
- 风险项可以扣减相关维度，并触发销冠标杆限制。
- AI 输出异常时使用“证据不足”结果，不使用文字长度推断能力分。

## 组件和接口

| 组件 | 职责 |
|---|---|
| `DrillCustomerPrompt` | 生成真实家长多轮问题的系统提示词和上下文 |
| `DrillChampionCoachPrompt` | 生成销冠教练评分提示词和结构化输出约束 |
| `DrillConversationState` | 保存客户状态、已披露信息和销售动作 |
| `DrillChampionScoreMapper` | 将内部八维指标归并到用户六维分数 |
| `DrillEvidenceValidator` | 校验证据、轮次、角色和评分维度绑定 |
| `DrillChampionReportService` | 输出六维评分、成交诊断、替换话术和训练任务 |
| `free_practice_real_dialogue_test.php` | 回放多轮真人风格对话并生成报告 |

## 正确性属性

1. 六维最大分之和始终为 100。
2. 任一维度的得分不超过该维度最大分。
3. 评分证据只能引用销售说话轮次。
4. 没有证据的维度不会因文字长度自动获得能力分。
5. 严重风险存在时，报告必须展示风险并限制销冠标杆资格。
6. 同一对话和同一评分规则版本的评分结果可重复复核。

## 错误处理

- AI 家长输出无法解析：按阶段和客户状态使用自然问题兜底。
- 销冠教练输出无法解析：生成证据不足报告，不生成正式能力分。
- 证据引用不存在：拒绝该评分结果并进入重试状态。
- 六维分数合计不一致：拒绝该评分结果并要求结构化修复。
- Skill 版本缺失：停止正式评分并提示训练负责人发布评分版本。

## 测试策略

- 每个评分维度至少准备高质量、中等质量、低质量三类真实多轮对话。
- 每个销售阶段至少回放三轮连续客户追问。
- 单独测试两次试关闭、无试关闭和虚假试关闭。
- 单独测试匹配案例、无案例和不匹配案例。
- 单独测试合理紧迫感与恐吓式紧迫感。
- 验证每个分数都能回溯到销售原文。
- 验证 AI 失败时返回证据不足状态，不返回伪造能力分。
