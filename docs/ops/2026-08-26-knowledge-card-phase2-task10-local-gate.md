# 知识卡二期任务10隔离摄取本地门禁记录

> 作者：Monkeycode

## 状态

- 日期：2026-08-26
- 阶段：任务10「隔离摄取验收」本地门禁已完成；生产只读 dry-run 待具备服务器执行权限后补跑。
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
- `package_sha256`：`94f49fd31f2c4175195c821ff6e0a73c8ca1da733c12029ad7cc765ad90b2b84`
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

## 生产只读 dry-run 状态

任务10要求生产只读 dry-run 进一步确认：

- 1417 张统计；
- 旧 194 条候选；
- 无冲突；
- 无孤儿；
- 无非目标更新。

本轮未完成该项，原因：

1. 本机 `php` 不可用：`'php' 不是内部或外部命令，也不是可运行的程序或批处理文件。`
2. SSH 只读连通性检查失败：`root@122.51.223.46: Permission denied (publickey,gssapi-keyex,gssapi-with-mic).`

安全结论：在缺少服务器执行权限和 PHP 环境前，不应伪造 dry-run 结果，也不应尝试绕过权限。任务10不能标记为完整通过；只能确认本地门禁通过，生产只读 dry-run 待补。

## 后续动作

拿到服务器 SSH 执行环境后，在服务器应用根目录 `/www/wwwroot/122.51.223.46` 执行只读命令，报告路径必须放在 Web 根目录外，例如 `/root/zx-knowledge-phase2-task10/`：

```sh
cd /www/wwwroot/122.51.223.46
DATA_SHA256=94f49fd31f2c4175195c821ff6e0a73c8ca1da733c12029ad7cc765ad90b2b84
REPORT_PATH=/root/zx-knowledge-phase2-task10/import-dry-run-$(date +%Y%m%d-%H%M%S).json
php scripts/import_knowledge_cards.php import \
  database/import_data/knowledge-cards-phase2.isolated-package.json \
  --sha256 "$DATA_SHA256" \
  --report "$REPORT_PATH"
```

禁止追加 `--apply`，除非进入后续阶段并获得明确授权。
