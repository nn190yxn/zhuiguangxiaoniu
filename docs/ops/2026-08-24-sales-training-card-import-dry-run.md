# 销售培训卡一期生产部署与 Dry-run 记录

> 作者：Monkeycode
> 日期：2026-08-24
> 状态：代码与 300 张培训卡均已上线，生产验收通过

## 范围

- 75 张销售母卡拆分为 300 张 K/S/D/C 培训卡。
- 新增 3 个销售培训模块，不覆盖既有 7 个模块和 56 张培训卡。
- 本期不处理 1417 张教练/训练知识卡。

## 版本与数据包

- 权限修复提交：`137571a`
- 安全渲染提交：`2c7b67f`
- 数据包提交：`cf7e92a`
- 安全导入/回滚 CLI 提交：`c61ee06`
- 数据包 SHA-256：`2ecb957caf6b08492539a6a72b05fd0f710aa74f6441891d0398d0fdf234583d`
- 本地测试：29/29 通过
- 独立高风险复核：PASS

## 线上基线与代码部署

部署前，线上以下文件与改造前 Git 基线 `cd68d76` 的 SHA-256 完全一致，未发现线上热修复：

- `api/config.php`
- `api/drill/training-modules.php`
- `api/drill/training-cards.php`
- `training-card.html`

代码已原子部署到 `/www/wwwroot/122.51.223.46`，正式文件 SHA-256 与仓库一致。旧文件备份位于：

`/root/zx-sales-code-backup-20260824-162514`

数据包未放入 Web 根目录，生产操作材料位于：

`/root/zx-sales-training-20260824`

部署后检查：

- 所有 PHP 文件语法检查通过（线上 PHP 8.2.28）。
- 匿名访问培训模块 API 返回 HTTP 401。
- Web 访问导入 CLI 返回 HTTP 403。
- 培训卡页面返回 HTTP 200。

## 正式目录 Dry-run

使用正式目录中的导入器连续执行两次只读 dry-run，三个输出文件逐字节一致：

- 报告 SHA-256：`cb39691a914036ba59c93745e1fcff2ed2494d3f412eaca1ea5c7fa8facc2aa7`
- 模块：新增 3、跳过 0、待更新 0
- 卡片：新增 300、跳过 0、待更新 0
- 冲突：0
- K/S/D/C：各 75
- 模块卡量：100 / 84 / 116

数据库前后状态完全一致：

| 指标 | Dry-run 前 | Dry-run 后 |
|---|---:|---:|
| `training_modules` | 7 | 7 |
| `training_cards` | 56 | 56 |
| `user_progress` | 0 | 0 |
| 目标模块 | 0 | 0 |
| 目标卡片 | 0 | 0 |
| 导入命名锁空闲 | 是 | 是 |

结论：dry-run 未写数据库、未获取导入锁、未创建 manifest 或数据库备份。

## 5 项人工复核

这些项目只是标题/主题相似，不是编码冲突，也不会覆盖旧卡：

| 新卡 | 新标题 | 旧卡 | 旧标题 | 判断 |
|---|---|---|---|---|
| `sales-0041-d` | 低龄家长沟通｜演练 | `D-comm-001` | 家长沟通演练 | 低龄专项，可并存 |
| `sales-0041-c` | 低龄家长沟通｜通关 | `C-comm-001` | 家长沟通通关项 | 低龄专项，可并存 |
| `sales-0042-d` | 高龄家长沟通｜演练 | `D-comm-001` | 家长沟通演练 | 高龄专项，可并存 |
| `sales-0042-c` | 高龄家长沟通｜通关 | `C-comm-001` | 家长沟通通关项 | 高龄专项，可并存 |
| `sales-0053-s` | 转介绍｜话术 | `S-renewal-002` | 转介绍话术 | 新销售体系标准卡，保留旧卡并存 |

生产导入已在用户明确授权后使用 `--ack-manual-review` 执行，未使用 `--allow-update`。

## 生产导入与验收

生产 `--apply` 于 2026-08-24 执行一次，退出码为 0，未重试：

- batch ID：`3b052fe19e45bd858a08cb56cf01b592`
- completed manifest：`/root/zx-sales-training-20260824/import-manifest-20260824-164555.json`
- manifest SHA-256：`d7534102f4316927bedf346b7d66dfb1dde98c72a528180778a9f6f5a0d62ec7`
- 导入前数据库备份：`/root/zx-sales-training-20260824/sales-import-backup-20260824-164555-c34ad5d3.json`
- 备份 SHA-256：`43e8635443c117a8cebedc72571b23e39a2ba2d123aaf59b069ec3d0ed3d4b5e`
- manifest 记录：新增模块 3、卡片 300；更新模块/卡片均为 0
- 数据库总量：7/56 → 10/356；`user_progress` 仍为 0
- 既有非目标数据：模块 7、卡片 56，数量保持不变
- 目标卡：重复编码 0、孤儿卡 0，全部状态为启用
- 模块角色均为 `consultant`，卡量为 100 / 84 / 116
- K/S/D/C 分别为 75 / 75 / 75 / 75

提交后再次 dry-run：

- 模块：insert 0、skip 3、update 0
- 卡片：insert 0、skip 300、update 0
- 冲突和人工复核：均为 0

精确 rollback dry-run 已通过，计划只包含本批次 300 张卡和 3 个模块；**未执行 rollback apply**。后续若出现学习进度或未知卡片，回滚检查会自动阻断。

验收期间曾因 shell 转义使辅助路径记录文件末尾多出字母 `n`，造成第一次 rollback dry-run 找不到 manifest；数据库导入和真实 manifest 均正常。修正记录文件后，rollback dry-run 通过，未重复执行生产导入。