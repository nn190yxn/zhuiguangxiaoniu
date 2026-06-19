# 安全清理记录

日期：2026-06-04

## 当前发现

提交前扫描发现以下文件包含真实敏感配置或 API Key，不适合继续进入公开 GitHub 仓库：

- `real_sync/wp-config.php`
- `real_sync/set_doubao_config.php`

已执行：

- 将 `real_sync/wp-config.php` 从 Git 跟踪中移除，保留本地文件
- 将 `real_sync/set_doubao_config.php` 从 Git 跟踪中移除，保留本地文件
- 在 `.gitignore` 中加入忽略规则，避免后续误提交

## 风险说明

这两个文件此前已经出现在 Git 历史中。即使当前提交删除它们，如果仓库曾经公开过，旧提交中的敏感内容仍可能被访问。

因此，推送到 GitHub 前建议完成：

- 轮换数据库密码
- 轮换 WordPress salts
- 轮换豆包 API Key
- 如要继续公开仓库，执行 Git 历史清理后再强制推送

## 当前建议

在完成密钥轮换和历史清理前，不建议把当前仓库继续作为公开仓库使用。

如果后续会改为私有仓库，仍建议轮换已经暴露过的密钥。
