# 知识卡二期任务11-12：后台验收与员工端全量发布记录

作者：Monkeycode

## 范围

- 任务11：后台与人工合并验收。
- 任务12：员工端全量发布验收。
- 生产主机：`122.51.223.46`
- 生产 Web 根：`/www/wwwroot/122.51.223.46/`

## 执行结果

### 1. 生产隔离导入 apply

用户已明确授权执行 1417 张知识卡生产隔离导入。

- 数据文件 SHA-256：`97d41b3428feafed6ef526f2363ddf09710727afe06e4b1cff8e6de4ac5d66d1`
- package identity SHA-256：`94f49fd31f2c4175195c821ff6e0a73c8ca1da733c12029ad7cc765ad90b2b84`
- 结果：`inserted=1417`、`updated=0`、`skipped=0`、`candidates=5`
- batch：`batch_id=1`，初始状态 `isolated`
- manifest：`/root/zx-knowledge-phase2-import-artifacts/manifests/import-manifest-20260826-230320.json`
- manifest SHA-256：`b3d4abf312fcd0d020c97beb41de6a637423485c57b150a9d992e778ab046411`
- completion marker：`/root/zx-knowledge-phase2-import-artifacts/manifests/import-manifest-20260826-230320.json.completed`
- backup：`/root/zx-knowledge-phase2-import-artifacts/backups/knowledge-cards-backup-20260826-230321-import-before.json`
- apply 输出：`/root/zx-knowledge-phase2-import-artifacts/reports/import-apply-20260826-230320.out`

备注：首次 apply 因安全边界拒绝将备份写到脚本父目录内，失败发生在备份前、事务前；随后确认生产表仍为导入前计数，再改用 `/root/zx-knowledge-phase2-import-artifacts/` 成功导入。

### 2. 隔离导入后核验

导入后、发布前生产计数：

- `knowledge_items=1611`
- `knowledge_import_batches=1`
- `knowledge_item_versions=1417`
- `knowledge_item_sources=1417`
- `knowledge_item_relations=5`
- `knowledge_audit_logs=1`
- `publication_status`: `isolated=1417`、`published=194`
- `bad_visibility=0`
- 孤儿数据：versions/sources/relations 均为 `0`
- 旧数据保护：`user_knowledge_progress=2`、`drill_templates=9` 未变；旧进度或演练引用 isolated 数为 `0`

5 条候选关系：

1. `ACTION-0520`「支撑触肩」→ 旧卡 `31`「平板支撑触肩」
2. `ACTION-0531`「平衡木行走」→ 旧卡 `26`「平衡木行走」
3. `ACTION-0550`「熊爬」→ 旧卡 `30`「熊爬」
4. `GAME-0455`「折返跑」→ 旧卡 `32`「折返跑」
5. `GAME-0530`「熊爬」→ 旧卡 `30`「熊爬」

### 3. 生产代码部署

用户追加授权部署知识库相关后台接口/文件，核验后发布 1417 条内容。

部署范围：

- `api/admin/common.php`
- `api/admin/knowledge/index.php`
- `api/admin/services/KnowledgeOperationService.php`
- `api/knowledge/*.php`
- `mobile/knowledge.html`
- `mobile/knowledge-detail.html`

生产备份目录：

- `/root/zx-knowledge-phase2-deploy-backups/20260827-000335`

部署后 PHP 语法检查：全部通过。

接口烟测：

- `https://supercalf.com/api/knowledge/list.php?page=1&page_size=1`：未登录返回 `authentication_required`，未匿名泄露内容。
- `https://supercalf.com/api/admin/knowledge/index.php?action=list_batches`：未登录返回 `401`，后台未匿名开放。

### 4. 批量发布

使用已部署后台服务 `KnowledgeOperationService::publishBatch` 发布 batch `1`。

发布前：

- batch `1`：`isolated`
- batch isolated items：`1417`
- batch published items：`0`
- audit logs：`1`

发布后：

- batch `1`：`published`
- batch isolated items：`0`
- batch published active items：`1417`
- 全库 published active total：`1611`
- audit logs：`2`
- 最新审计：`action=publish_batch`、`target_type=batch`、`target_id=1`

### 5. 员工端发布后核验

直接调用生产 `KnowledgeListService` 只读验证：

- 员工端列表总数：`1611`
- 首屏记录发布态：`published`
- `content_type=action`：`610`
- `risk_level=高`：`410`
- DB：`published_active_total=1611`、`isolated_total=0`、`batch_status=published`
- 候选关系仍保留：`5`
- 审计日志：`2`

### 6. 发布后导入/回滚门禁

发布后再次 import dry-run：

- `record_count=1417`
- `insert=0`
- `skip=1417`
- `update_pending=0`
- `manual_review_count=0`
- `candidate_count=0`
- 结论：幂等通过。

rollback dry-run：

- 结果：`rollback blocked: database batch is not isolated`
- exit：`2`
- 报告：`/root/zx-knowledge-phase2-import-artifacts/reports/rollback-dry-run-after-publish-20260827-000620.out`
- 结论：发布后自动回滚被安全阻断；如需下架/回滚，应走后台发布状态或版本回滚流程，不执行导入 manifest 的破坏性回滚。

## 本地测试

发布前后已运行知识卡专项测试：

- `knowledge_admin_contract.test.mjs`
- `knowledge_card_import_cli.test.mjs`
- `knowledge_card_schema_migration.test.mjs`
- `knowledge_access_boundary.test.mjs`
- `knowledge_employee_reading_experience.test.mjs`
- `knowledge_render_security.test.mjs`
- `knowledge_personal_features.test.mjs`

最新结果：`32 pass / 0 fail`；`git diff --check` 通过。

## 7. 发布后终检

2026-08-27 生产只读终检：

- `knowledge_items=1611`
- `published_active=1611`
- `isolated=0`
- batch `1` 状态：`published`
- `knowledge_item_versions=1417`
- `knowledge_item_sources=1417`
- `candidate_relations=5`
- `audit_logs=2`
- 二期进度写入：`progress_on_phase2=0`
- 二期演练引用：`drill_refs_to_phase2=0`
- 员工端列表服务返回：`total=1611`，首屏 `non_published_in_page=0`
- 首条发布知识：`id=45`，标题「儿童七大身体素质总览」，状态 `published`

## 结论

- 1417 张二期知识卡已完成生产导入并发布。
- 员工端当前可见知识总量为 `1611`，包含旧 `194` 条与二期 `1417` 条。
- 后台接口和员工端接口均已部署，未登录接口未泄露内容。
- 候选关系、版本、来源、审计、幂等和回滚阻断均已核验。
- 旧知识 ID、历史进度、演练关联未被破坏。

## 后续留意

- 5 条 candidate 关系仍保留为候选，可后续由运营后台人工标记 `merged`、`duplicate` 或 `rejected`。
- 生产自动回滚已因发布状态被阻断；后续如需撤回二期内容，应走下架/版本流程，或另行制定人工恢复方案。
