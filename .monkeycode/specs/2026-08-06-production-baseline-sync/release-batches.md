# 生产发布批次

本文件仅定义发布门槛和操作证据。生产写入需要在执行时复核摘要、创建备份，并使用有效员工会话完成验收。

## 执行通则

1. 在服务器执行前运行 `sha256sum` 复核本批次全部文件；摘要与下方记录一致时进入备份阶段。
2. 在同一服务器创建带 UTC 时间戳的批次备份目录，并逐文件备份。
3. 使用精确文件清单写入候选版本，保持文件权限与所有者。
4. 运行对应的匿名 HTTP 契约、授权会话旅程和 15 分钟观察。
5. 验收失败时从该批次备份逐文件恢复，并再次运行核心 HTTP 契约。

## 批次 A：登录与 PWA

### 发布范围

- `mobile/login.html`
- `js/app-auth.js`
- `api/auth-jwt.php`
- `api/auth/refresh.php`
- `sw.js`

### 当前生产摘要

| 文件 | SHA-256 前缀 |
| --- | --- |
| `mobile/login.html` | `5579e9694ef0` |
| `js/app-auth.js` | `d0d8eab390bc` |
| `api/auth-jwt.php` | `9e112bb46de5` |
| `api/auth/refresh.php` | `3c5a821699d8` |
| `sw.js` | `16d51bfe1707` |

### 验收

- 有效员工账号完成登录、刷新令牌与退出旅程。
- 离线页面恢复、Service Worker 更新和登录后跳转保持可用。
- `node --test scripts/platform_session_service.test.mjs` 与 `node --test scripts/mobile_pwa_shell.test.mjs` 均通过。

## 批次 B：体测与 AI

### 发布范围

- `fitness-assessment-app.html`
- `api/ai-runtime.php`
- `api/ai-services.php`

### 当前生产摘要

| 文件 | SHA-256 前缀 |
| --- | --- |
| `fitness-assessment-app.html` | `447353a7d607` |
| `api/ai-runtime.php` | `680ade55586ca` |
| `api/ai-services.php` | `2a975e047e25` |

### 验收

- 使用脱敏真实体测图片完成 OCR 请求和结果展示。
- 使用有效员工会话完成运动规划请求，确认错误信息不包含敏感配置。
- `node --test scripts/fitness_assessment_ocr.test.mjs` 与 `node --test scripts/ai_runtime_convergence.test.mjs` 均通过。

## 批次 C：工作量换算

### 发布范围

- `api/workload/services/WorkloadConversionResultQueryService.php`

### 当前生产摘要

| 文件 | SHA-256 前缀 |
| --- | --- |
| `api/workload/services/WorkloadConversionResultQueryService.php` | `f941373df182` |

### 验收

- 员工日报、审核列表和指标统计返回一致的换算摘要。
- 验证点数规则和必做项规则同时存在时的达标、待审核和未达标状态。
- `node --test scripts/workload_conversion_results.test.mjs` 通过 6 项；目标 PHP 文件通过 `php -l`。

## 授权会话门槛

发布验收需要由用户提供或自行登录的有效员工会话完成。会话凭据只在浏览器会话中使用，验收记录只保留通过结果、时间和功能范围。
