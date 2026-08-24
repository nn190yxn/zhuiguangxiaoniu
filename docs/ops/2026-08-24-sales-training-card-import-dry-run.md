# 销售培训卡一期生产部署与 Dry-run 记录

> 作者：Monkeycode
> 日期：2026-08-24
> 状态：代码已部署，数据库尚未执行 `--apply`

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

建议在生产导入时显式使用 `--ack-manual-review`，不使用 `--allow-update`。

## 待授权的生产操作

只有获得用户明确确认后，才可运行以下类型的命令：

```text
php /www/wwwroot/122.51.223.46/scripts/import_sales_training_cards.php import \
  /root/zx-sales-training-20260824/sales-training-cards.v1.json \
  --sha256 2ecb957caf6b08492539a6a72b05fd0f710aa74f6441891d0398d0fdf234583d \
  --apply \
  --backup-dir /root/zx-sales-training-20260824 \
  --manifest /root/zx-sales-training-20260824/import-manifest-<唯一时间戳>.json \
  --ack-manual-review
```

生产导入后必须立即验证：

1. manifest 状态为 `completed`，且记录 3 个模块 ID 和 300 个卡片 ID。
2. 数据库总量从 7/56 变为 10/356，既有 56 张卡保持不变。
3. 再次 dry-run 得到模块 skip=3、卡片 skip=300、insert/update=0。
4. 运行 rollback dry-run，确认精确计划；不得主动执行 rollback apply。
5. 若已有学习进度或出现未知卡片，回滚必须自动阻断。
