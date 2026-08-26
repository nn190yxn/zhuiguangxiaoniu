# 知识卡二期任务10隔离摄取门禁记录

> 作者：Monkeycode

## 状态

- 日期：2026-08-26
- 阶段：任务10「隔离摄取验收」本地门禁已完成；生产 dry-run 已尝试但在 schema preflight 阶段停止。
- 边界：未执行生产部署、生产迁移、生产导入、生产 `--apply`、生产回滚。

## 本地验收命令

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
- 结论：本地隔离包未进入员工 published 可见面。

## 导入器只读边界核验

覆盖测试：`real_sync/scripts/knowledge_card_import_cli.test.mjs`

已验证：

- 默认 dry-run 不获取命名锁。
- 默认 dry-run 不创建备份。
- 默认 dry-run 不写 manifest。
- 默认 dry-run 不执行 `INSERT/UPDATE/DELETE`。
- 显式 `--apply` 才允许写入路径，且要求备份、manifest、事务、非目标摘要断言。
- 报告/备份/manifest 输出路径必须位于 Web 根目录外，原子写入、0600、禁止覆盖。

## 生产预检与 dry-run 结果

任务10要求生产只读 dry-run 进一步确认：

- 1417 张统计；
- 旧 194 条候选；
- 无冲突；
- 无孤儿；
- 无非目标更新。

本轮收到服务器密钥后完成了生产预检，但未达到完整 dry-run 输出：

1. 服务器 PHP CLI 可用：`PHP 8.2.28`。
2. 生产 Web 根目录 `/www/wwwroot/122.51.223.46` 当前没有二期导入器和隔离包。
3. 为避免部署到 Web 根目录，仅临时上传 dry-run 所需文件到 `/root/zx-knowledge-phase2-task10/`，并软链复用线上 `api/config.php`。
4. 执行命令未带 `--apply`，导入器在 schema preflight 阶段停止：`required table missing: knowledge_import_batches (run migration 202608260001/202608260002 first)`。
5. 只读数据库基线确认：
   - `knowledge_categories`：存在，23 条。
   - `knowledge_items`：存在，194 条。
   - `user_knowledge_progress`：存在，2 条。
   - `drill_templates`：存在，9 条。
   - 二期表 `knowledge_import_batches / knowledge_item_versions / knowledge_item_sources / knowledge_item_relations / knowledge_favorites / knowledge_recent_views / knowledge_audit_logs` 均不存在。
6. 临时上传目录、临时报告目录、本机临时密钥副本和临时脚本已清理。

安全结论：生产 dry-run 未进入差异计算阶段，原因是生产尚未应用二期 schema。任务10不能标记为完整通过；下一步必须先进入“生产 schema 迁移授权/执行/核验”环节，再补跑任务10 dry-run。仍禁止执行任何生产 `--apply` 导入。

## 后续动作

需要单独确认并执行生产 schema 迁移后，再在服务器上补跑只读 dry-run。dry-run 使用文件 SHA-256，不使用包身份 SHA：

```sh
cd /root/zx-knowledge-phase2-task10
DATA_SHA256=97d41b3428feafed6ef526f2363ddf09710727afe06e4b1cff8e6de4ac5d66d1
REPORT_PATH=/root/zx-knowledge-phase2-task10-reports/import-dry-run-$(date +%Y%m%d-%H%M%S).json
php scripts/import_knowledge_cards.php import \
  database/import_data/knowledge-cards-phase2.isolated-package.json \
  --sha256 "$DATA_SHA256" \
  --report "$REPORT_PATH"
```

禁止追加 `--apply`，除非进入后续阶段并获得明确授权。
