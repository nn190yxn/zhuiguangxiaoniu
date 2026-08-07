# 来源裁决记录

## 2026-08-06 工作量换算结果

| 文件或能力 | 保留来源 | 证据 | 当前状态 |
| --- | --- | --- | --- |
| `WorkloadConversionResultQueryService.php` | 服务器 | 实时 SHA-256：`f941373df182ca27419abe8aac68f2fc9229068039f073c42a24d1edb72556e7`；服务器文件大小 `10003` 字节 | 已逐文件回收至本地 |
| 日报与审核换算摘要 | 服务器行为 + 本地平台适配 | 现网 `my-report.php`、`audit-list.php` 均返回 `conversion_results` 与 `conversion_summary` | 已合并并通过专项测试 |
| 指标统计换算摘要 | 服务器行为 + 本地统计服务 | 现网 `WorkloadMetricSelectionService.php` 按权限范围调用 `summaryForScope` | 已合并并通过专项测试 |
| 管理后台 KPI | 服务器行为 + 本地页面 | 现网后台显示有效点数、待审核点数和达标差额 | 已合并并通过专项测试 |

## 未闭环项

- GitHub 固化提交：`87e6a48`；记录提交：`ef705ad`；远程分支：`origin/260802-feat-full-site-architecture`，已推送。
- 登录、PWA、体测和 AI 的服务器差异仍需逐文件裁决和专项验证。
- 生产发布和授权会话验收尚未执行。
- 2026-08-06 只读预检发现开发指南列出的 `platform_http_cache_baseline.mjs`、`platform_baseline_consistency.mjs` 和 `platform_change_batch.mjs` 未在当前仓库中；发布观察可使用现有 `platform_release_gate.mjs`，缓存与一致性证据需按批次人工采集。

## 2026-08-06 高影响模块实时裁决

| 模块 | 服务器来源摘要 | 本地来源摘要 | 裁决 | 后续证据 |
| --- | --- | --- | --- | --- |
| 登录 | `mobile/login.html` `5579e9694ef0`，`app-auth.js` `d0d8eab390bc`，`auth-jwt.php` `9e112bb46de5`，`refresh.php` `3c5a821699d8` | 四个文件均与服务器不同 | `server_baseline` | 已完成匿名 HTTP 契约；待授权登录与刷新旅程验收 |
| PWA | `sw.js` `16d51bfe1707`，`manifest.webmanifest` `07f34e585fc1` | `manifest.webmanifest` 一致；Service Worker 不同 | Service Worker 为 `server_baseline` | 已完成静态专项测试；待离线刷新与登录后验收 |
| 体测 | `fitness-assessment-app.html` `447353a7d607` | `19da598e60e9` | `server_baseline` | 已完成 OCR 静态专项测试；待真实图片与授权会话验收 |
| AI | `ai-runtime.php` `680ade55586ca`，`ai-services.php` `2a975e047e25` | 两文件与服务器不同，且本地存在未提交改动 | 服务器保持 `server_baseline`，本地保留 `local_candidate` | 待隔离差异、专项回归和独立发布批次 |

服务器与本地内容不同的高影响文件不进入本轮工作量回收提交；每项将在独立发布批次中记录备份、验证和回滚证据。

## 本地验证证据

- 2026-08-06 工作量发布批次：生产服务由 `f941373df182` 更新为 `c83652f7e50f`；发布前备份位于 `/www/backup/workload-conversion-20260806T181800Z/`，候选与生产文件均通过服务器 `php -l`。
- 2026-08-06 工作量匿名接口契约：`/api/platform/health.php?check=ready` 返回 200；`/api/workload/my-report.php` 与 `/api/workload/audit-list.php` 均返回规范 401，未出现 5xx。
- 2026-08-06 发布后三方报告：工作量换算服务在生产、工作区与 GitHub 分支 `origin/260802-feat-full-site-architecture` 的 SHA-256 均为 `c83652f7e50f`；报告汇总为 `github_synced: 9`、`server_baseline: 11`、`production_verified: 0`。
- 授权会话验收和完整 30 分钟发布观察待浏览器侧确认后记录；在此之前不将模块标记为 `production_verified`。
- 2026-08-06 生产匿名 HTTP 契约：`https://supercalf.com/`、`/internal.html` 与 `/api/platform/health.php?check=ready` 均返回 HTTP 200。
- `node --test scripts/platform_production_baseline.test.mjs`：6/6 通过。
- `node --test scripts/workload_conversion_results.test.mjs`：6/6 通过。
- `node --test scripts/platform_session_service.test.mjs`：3/3 通过。
- `node --test scripts/mobile_pwa_shell.test.mjs`：7/7 通过。
- `node --test scripts/fitness_assessment_ocr.test.mjs`：7/7 通过。
- `node --test scripts/ai_runtime_convergence.test.mjs`：5/5 通过。
- 工作量、认证和 AI 的受影响 PHP 文件均已通过 `php -l`。
