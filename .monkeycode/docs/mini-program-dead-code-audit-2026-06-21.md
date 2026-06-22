# 小程序孤立残留代码审计

日期：2026-06-21

## 审计范围

- 仓库：`/tmp/opencode/zhuiguangxiaoniu`
- 小程序目录：`real_sync/mini-program/`
- 目标：确认最终上线范围之外的页面模块是否仍被注册、引用或可见访问

## 当前注册页面结论

- 当前小程序注册页以 `real_sync/mini-program/app.json` 为准。
- 已注册页面共 31 个，范围包含：首页、登录、协议、制度中心、学习中心、考试、知识卡、演练、通关、通知、工作量、提醒设置、我的。
- 当前注册页中未包含以下排除模块：`points`、`assessment`、`survey`、`webview`、`skill`。

## 已确认的孤立残留目录

- `real_sync/mini-program/pages/points/*`
- `real_sync/mini-program/pages/assessment/*`
- `real_sync/mini-program/pages/webview/*`
- `real_sync/mini-program/pages/survey/*`
- `real_sync/mini-program/pages/skill/*`
- `real_sync/mini-program/pages/agreement/service/*`
- `real_sync/mini-program/pages/agreement/privacy/*`

## 引用关系结论

- `points/*`：当前未注册，未发现来自首页、学习页、我的页或其他已注册页面的入口。
- `assessment/*`：当前未注册，仅在自身代码中跳转到 `webview/index`。
- `webview/*`：当前未注册，仅被 `assessment/*` 引用。
- `survey/*`：当前未注册，仅在 `survey/fill` 与 `survey/result` 内部互相跳转。
- `skill/*`：当前未注册，仅在 `skill/history`、`skill/result`、`skill/record` 内部互相跳转。
- `agreement/service/*` 与 `agreement/privacy/*`：为旧版协议副本目录，当前已注册并实际使用的是同级文件 `pages/agreement/service.wxml` 与 `pages/agreement/privacy.wxml`，子目录内旧文件没有外部引用。

## 用户可见范围核对结果

- 首页、学习页、协议页已清理积分、签到和错误时间口径残留。
- 当前已注册页面中，未发现评估中心、问卷、积分兑换、WebView、录音复盘的可见入口。
- 当前仓库中仍存在相关历史源码，后续维护时容易误判为有效功能。

## 风险评估

- 运行风险：低。当前残留目录未注册，且未接入现有页面流转。
- 维护风险：中。后续做全局搜索、页面梳理、上线核对时，这批历史目录会持续干扰判断。
- 审核风险：低。只要 `app.json` 和可见入口保持现状，这批目录不会进入当前小程序实际使用路径。

## 建议动作

- 第一步：保留本审计文档，作为后续清理前的只读基线。
- 第二步：在删除前再次做一次零引用复核，重点确认 `app.json`、首页、学习页、我的页、通知页没有新增回跳。
- 第三步：经确认后，统一移除以上 7 组孤立残留目录，减少仓库噪音。
- 第四步：删除后执行一次全量静态复查，确认没有新的缺失引用或页面注册错误。

## 删除前核对清单

- `real_sync/mini-program/app.json` 中没有相关页面注册。
- `real_sync/mini-program/pages/index/*` 中没有相关入口。
- `real_sync/mini-program/pages/learning/*` 中没有相关入口。
- `real_sync/mini-program/pages/mine/*` 中没有相关入口。
- `real_sync/mini-program/pages/notifications/*` 中没有相关入口。
- 全局搜索结果只剩模块内部互跳，不存在来自已注册页面的外部引用。

## 当前状态

- 本轮已完成零引用审计。
- 本轮未执行物理删除。
- 后续如需实际清理，按本文件清单逐组执行即可。
