# 追光小牛仓库清理盘点与执行记录

生成时间：2026-06-04

## 结论

仓库已经完成一轮保守清理：只归档，不删除。

当前正式主线仍然是：

- `real_sync/`
- `real_sync/mini-program/`
- `real_sync/api/`
- `real_sync/admin/`
- `real_sync/database/`

微信开发者工具应导入：

- `E:\程序开发\追光小牛\git\zhuiguangxiaoniu\real_sync\mini-program`

## 已完成归档

### 小程序重复目录归档

已归档到：

- `archive/mini-program-duplicates-20260604/`
- `archive/mini-program-orphan-pages-20260604/`

主要内容：

- 旧 `mini-program-dev/`
- 旧小程序压缩包
- 旧根目录小程序工具文件
- 已取消的制度中心相关孤立页面
- 旧话术记录孤立页面

### 仓库保守清理归档

已归档到：

- `archive/repo-cleanup-20260604/`
- `archive/repo-cleanup-20260604/MANIFEST.json`

本次共归档 66 项，包含：

- 根目录旧 `api/`
- 根目录旧 `admin/`
- 根目录散落 HTML/PHP/JS/ZIP/临时脚本
- `real_sync/` 内明显的 `tmp-*`、`*.bak-*`、`debug_*`、`test_*`、`check_*`
- `real_sync/scripts/` 内一次性测试、调试、排查、验证脚本

## 本次明确未移动

以下目录和文件没有动：

- `.monkeycode/`
- `skills/`
- `复盘标准/`
- `追光小牛/`
- `real_sync/mini-program/`
- `real_sync/api/`
- `real_sync/admin/`
- `real_sync/database/`
- `服务器现网全面审计与API专项问题清单_2026-05-15.md`
- `销售沟通录音点评报告_小汤圆体验课.md`

原因：这些内容可能是业务资料、项目历史、复盘标准、旧站快照或当前主线代码，不能按垃圾文件直接处理。

## 当前仓库结构建议

建议把 `real_sync/` 作为唯一主开发目录。

后续开发时不要再改：

- 根目录旧 `api/`
- 根目录旧 `admin/`
- 根目录旧小程序目录
- 根目录散落页面

这些内容现在已经在 `archive/` 下，只作为历史回滚和比对资料。

## 后续可选清理

还可以继续整理，但需要单独确认：

- `.monkeycode/` 是否移入 `docs/project-history/`
- `skills/` 与 `复盘标准/` 是否统一放入 `docs/skills/`
- `追光小牛/` 是否归档为旧站快照
- 审计文档是否移入 `docs/audits/`
- 销售点评报告是否移入 `docs/business/`

这些属于业务资料整理，不建议自动执行。

## 验收标准

清理后需要满足：

- 小程序 `app.json` 声明页面全部存在
- 小程序 JS 语法检查通过
- `project.config.json` 不包含错误的 `srcMiniprogramRoot`
- `real_sync/api/knowledge/list.php` 等当前主线接口仍存在
- Git 状态中归档动作可读
- 没有删除业务资料

