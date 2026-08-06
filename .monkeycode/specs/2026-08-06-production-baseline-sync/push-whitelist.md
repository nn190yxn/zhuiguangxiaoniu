# GitHub 推送白名单

## 2026-08-06 基线与工作量回收批次

### 待提交文件

- `real_sync/api/workload/services/WorkloadConversionResultQueryService.php`
- `real_sync/scripts/workload_conversion_results.test.mjs`
- `real_sync/scripts/platform_production_baseline.mjs`
- `real_sync/scripts/platform_production_baseline.test.mjs`
- `.monkeycode/specs/2026-08-06-production-baseline-sync/requirements.md`
- `.monkeycode/specs/2026-08-06-production-baseline-sync/design.md`
- `.monkeycode/specs/2026-08-06-production-baseline-sync/tasklist.md`
- `.monkeycode/specs/2026-08-06-production-baseline-sync/source-decisions.md`
- `.monkeycode/specs/2026-08-06-production-baseline-sync/three-way-baseline-report.json`
- `.monkeycode/specs/2026-08-06-production-baseline-sync/push-whitelist.md`

### 验证证据

- `node --test scripts/platform_production_baseline.test.mjs`：6 项通过。
- `node --test scripts/workload_conversion_results.test.mjs`：6 项通过。
- `php -l api/workload/services/WorkloadConversionResultQueryService.php`：通过。
- `php -l api/workload/audit-list.php`：通过。
- `php -l api/workload/my-report.php`：通过。
- `git diff --check`：通过。

### 隔离范围

登录、PWA、体测、AI、小程序、招聘、运营页面及既有项目文档的工作区改动保留在本地，等待独立裁决、测试和发布批次。本批次不包含生产写入操作。
