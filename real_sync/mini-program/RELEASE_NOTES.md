# 追光小牛小程序发布入口

微信开发者工具请导入本目录：

`real_sync/mini-program`

不要导入仓库根目录，也不要导入历史目录。

## 当前说明

- 当前正式小程序只保留这一套目录。
- 历史副本已归档到：
  - `archive/mini-program-duplicates-20260604/`
- 已断开入口的历史页面已归档到：
  - `archive/mini-program-orphan-pages-20260604/`
- 底部 TabBar 当前为：
  - 首页
  - 学习
  - 工作量
  - 知识库
  - 我的

## 发布前检查

- 确认 `project.config.json` 没有 `srcMiniprogramRoot: "miniprogram/"`。
- 微信开发者工具导入路径必须是 `real_sync/mini-program`。
- 发布前用真实员工账号测试登录、工作量、学习、知识库、语音演练。
