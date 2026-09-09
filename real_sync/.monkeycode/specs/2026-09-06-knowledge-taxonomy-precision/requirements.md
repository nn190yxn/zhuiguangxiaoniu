# 知识中心精准分类与适龄检索需求

## Introduction

本需求解决知识中心中主题分类、内容类型和适配年龄之间的边界混淆问题。员工进入“游戏”后，应能继续按年龄段筛选并获得可直接用于课堂的游戏卡；动作卡、游戏卡、成长知识卡和儿童发展知识卡应按真实内容分别归类。

## Glossary

- **内容类型**：知识卡、动作、游戏、话术、案例、教案参考等内容形态。
- **主题分类**：儿童发展、运动与体能、感统、动作、游戏、教学法、体测与评估、安全、教练成长和教案参考等业务主题。
- **适配年龄段**：一张卡适用的一个或多个标准年龄段，支持 `3-4岁`、`4-5岁`、`5-6岁` 等独立值。
- **精准匹配**：筛选结果同时满足主分类、内容类型和年龄段条件。

## Requirements

### Requirement 1: 内容类型与主题分类分离

**User Story:** AS 教练, I want to enter a specific content type such as 游戏 or 动作, so that I can find material matching the classroom task.

#### Acceptance Criteria

1. WHEN a user selects 游戏, THE system SHALL return cards whose content type is `game`.
2. WHEN a user selects 动作, THE system SHALL return cards whose content type is `action`.
3. WHEN a card contains child-development explanations without a playable game procedure, THE system SHALL classify the card under the corresponding knowledge topic and retain its non-game content type.
4. WHEN a card contains a playable game procedure, THE system SHALL classify the card as content type `game` and expose its precise professional topic.

### Requirement 2: 精准主题分类

**User Story:** AS 内容管理员, I want each card to have one accurate primary topic, so that employees can understand why the card appears in a category.

#### Acceptance Criteria

1. WHEN a published card is displayed, THE system SHALL provide exactly one primary topic and one content type.
2. WHEN a card is related to multiple topics, THE system SHALL assign one primary topic according to the card's main teaching purpose and preserve secondary relations separately.
3. WHEN a card is classified as 游戏, THE system SHALL keep the game type visible while presenting the card under its configured primary topic.
4. WHEN a card has insufficient evidence for a precise topic, THE system SHALL place the card in a review queue with the source title, identifier and classification evidence.

### Requirement 3: 年龄段标准化与筛选

**User Story:** AS 教练, I want to filter games by a specific age range, so that I can select an activity usable by the current class.

#### Acceptance Criteria

1. WHEN a card supports multiple age ranges, THE system SHALL store and expose each supported standard age range as an independent value.
2. WHEN a user selects `3-4岁`, THE system SHALL return every published game card whose supported age ranges include `3-4岁`.
3. WHEN a user selects `4-5岁`, THE system SHALL return every published game card whose supported age ranges include `4-5岁`.
4. WHEN a card has no verified age range, THE system SHALL display `全年龄段，需教练现场评估` and keep the card eligible only for the unfiltered or explicit all-age view.
5. WHEN a user combines 游戏 and an age range, THE system SHALL apply both conditions before pagination and display the matched age range on each result.

### Requirement 4: 分类治理与数据复核

**User Story:** AS 内容管理员, I want to review classification evidence in batches, so that taxonomy changes remain auditable.

#### Acceptance Criteria

1. WHEN a classification mapping is changed, THE system SHALL record the mapping version, operator, time, previous value, new value and reason.
2. WHEN the classification audit runs, THE system SHALL report counts by primary topic, content type, age range and review state.
3. WHEN a card's title, content and declared type conflict, THE system SHALL mark the card for manual review and preserve the conflicting evidence.
4. WHEN a classification release is prepared, THE system SHALL verify that list filters, detail labels, search results and lesson recommendations use the same published classification.

### Requirement 5: 员工端筛选体验

**User Story:** AS 教练, I want to choose topic, content type and age range in a clear sequence, so that I can move from discovery to classroom use.

#### Acceptance Criteria

1. WHEN a user enters professional knowledge, THE system SHALL expose topic, content type and age range as distinguishable filters.
2. WHEN a user changes one filter, THE system SHALL preserve the other selected filters.
3. WHEN a filter combination returns no results, THE system SHALL display the active conditions and a clear way to broaden the search.
4. WHEN a result is displayed, THE system SHALL show primary topic, content type, supported age ranges and a detail route.

## Scope Boundary

本阶段聚焦知识卡分类数据、年龄字段、员工端筛选和分类审核报告。页面视觉重做、内容正文重写和销售知识分类调整暂不纳入本阶段。

## Open Decisions

本阶段决策已确定：

1. 游戏继续属于专业知识中心，并作为独立内容类型和独立筛选项；专业主题保留“动作”和“游戏”两个独立子分类。
2. 年龄标准采用 `0-3岁`、`3-4岁`、`4-5岁`、`5-6岁`、`6-9岁`、`9-12岁`、`12岁以上` 和 `全年龄段，需教练现场评估`。
3. 每张卡保留一个主专业主题；其他适用主题通过关联关系进入，不复制卡片主记录。
