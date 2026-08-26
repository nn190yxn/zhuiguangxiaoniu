# 知识卡二期任务10隔离摄取验收记录

> 作者：Monkeycode

## 状态

- 日期：2026-08-26
- 阶段：任务10「隔离摄取验收」已完成。
- 边界：已获用户明确授权后执行生产二期 schema 迁移；未执行 1417 张知识卡导入 `--apply`、未发布内容、未回滚、未改 Web 根目录应用代码。

## 本地门禁

命令：

```cmd
cd /d E:\程序开发\追光小牛\git\zhuiguangxiaoniu
node --test ^
  real_sync\scripts\knowledge_card_contract.test.mjs ^
  real_sync\scripts\knowledge_card_package.test.mjs ^
  real_sync\scripts\knowledge_card_schema_migration.test.mjs ^
  real_sync\scripts\knowledge_card_import_cli.test.mjs ^
  real_sync\scripts\knowledge_access_boundary.test.mjs ^
  real_sync\scripts\knowledge_personal_features.test.mjs ^
  real_sync\scripts\knowledge_render_security.test.mjs ^
  real_sync\scripts\knowledge_admin_contract.test.mjs ^
  real_sync\scripts\knowledge_employee_reading_experience.test.mjs ^
  real_sync\scripts\platform_business_domain_migration.test.mjs
```

结果：44 项测试，41 通过，0 失败，3 项环境性跳过。

跳过项：

1. 正式源目录测试：本地环境未设置 `KNOWLEDGE_SOURCE_ROOT`。
2. 隔离包确定性重建：本地环境未提供正式源目录。
3. 十三域迁移注册表：既有环境性跳过。

## 隔离包核验

文件：`real_sync/database/import_data/knowledge-cards-phase2.isolated-package.json`

- `schema_version`：`knowledge-card-isolated-package.v2`
- `record_count`：1417
- `publication_status_counts`：`isolated = 1417`
- 文件 SHA-256：`97d41b3428feafed6ef526f2363ddf09710727afe06e4b1cff8e6de4ac5d66d1`
- 包身份 SHA-256：`94f49fd31f2c4175195c821ff6e0a73c8ca1da733c12029ad7cc765ad90b2b84`
- 结论：隔离包默认不进入员工 `published` 可见面。

## 生产 schema 迁移

授权：用户在本会话明确选择「执行生产 schema 迁移 + dry-run」。

执行范围：仅执行知识卡二期 3 个定向迁移，未使用项目全量迁移器，避免误跑其它 pending 迁移。

- `202608260001_knowledge_card_phase2_schema.sql`
- `202608260002_knowledge_card_phase2_seed_categories.sql`
- `202608260003_knowledge_card_manifest_integrity.sql`

执行结果：

- `202608260001`：applied
- `202608260002`：applied
- `202608260003`：applied
- 应用时间：`2026-08-26 22:49:06`
- 迁移前快照：`/root/zx-knowledge-phase2-schema/reports/schema-before-20260826-224851.json`
- 迁移后快照：`/root/zx-knowledge-phase2-schema/reports/schema-after-20260826-224906.json`

生产核验：

- `knowledge_categories`：24（新增 `phase2_import` 过渡分类 1 条）
- `knowledge_items`：194（未导入新增知识）
- `user_knowledge_progress`：2（未改变）
- `drill_templates`：9（未改变）
- `knowledge_import_batches`：0
- `knowledge_item_versions`：0
- `knowledge_item_sources`：0
- `knowledge_item_relations`：0
- `knowledge_favorites`：0
- `knowledge_recent_views`：0
- `knowledge_audit_logs`：0
- 三条迁移均写入 `schema_migrations` 且 checksum 匹配。

## 生产只读 dry-run

执行命令未带 `--apply`，报告写在 Web 根目录外：

- 报告路径：`/root/zx-knowledge-phase2-task10-reports/import-dry-run-20260826-225040.json`
- 报告 SHA-256：`e0590ab1a55a6ddd1f3e0c5ae2d3ea1d8e0b64bd1286cf4f906005a38679d059`

结果：

```json
{
  "record_count": 1417,
  "insert": 1417,
  "skip": 0,
  "update_pending": 0,
  "manual_review_count": 0,
  "candidate_count": 5,
  "type_counts": {
    "action": 610,
    "assessment": 6,
    "game": 564,
    "safety": 11,
    "teaching_knowledge": 51,
    "teaching_organization": 16,
    "training_plan": 159
  },
  "risk_counts": {
    "中": 981,
    "低": 26,
    "高": 410
  }
}
```

旧库基线：194 条知识作为候选池参与相似度检查。候选关系仅报告，不自动合并、不覆盖旧知识。

候选 5 条：

| 新卡编码 | 旧知识 ID | 旧标题 | 相似度 |
|---|---:|---|---:|
| ACTION-0520 | 31 | 平板支撑触肩 | 0.8 |
| ACTION-0531 | 26 | 平衡木行走 | 1 |
| ACTION-0550 | 30 | 熊爬 | 1 |
| GAME-0455 | 32 | 折返跑 | 1 |
| GAME-0530 | 30 | 熊爬 | 1 |

重复无报告 dry-run 结果稳定：仍为 `record_count=1417`、`insert=1417`、`update_pending=0`、`manual_review_count=0`、`candidate_count=5`。

## 写入边界确认

- schema 迁移只新增二期表/列/索引/约束和 `phase2_import` 过渡分类。
- dry-run 未写导入批次、版本、来源、关系、收藏、最近浏览、审计日志。
- dry-run 后生产核心计数保持：`knowledge_items=194`、`user_knowledge_progress=2`、`drill_templates=9`。
- 无 `update_pending`，无 manual review 冲突。
- 二期关系表为空，因此无孤儿关系。
- 临时上传的可执行脚本、迁移 SQL、隔离包、本机临时密钥副本和本机临时脚本已清理。
- 保留服务器 `/root` 下的 schema 前后快照和 dry-run 报告作为审计证据。

## 结论

任务10「隔离摄取验收」通过。下一阶段可以进入任务11「后台与人工合并验收」，但仍禁止执行知识卡导入 `--apply`，除非再次获得明确授权。
