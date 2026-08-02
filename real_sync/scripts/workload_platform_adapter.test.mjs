import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { test } from 'node:test';

const root = new URL('..', import.meta.url);
const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const hasPhp = spawnSync('php', ['-v'], { encoding: 'utf8' }).status === 0;

function runPhp(source) {
  const result = spawnSync('php', ['-d', 'display_errors=1', '-r', source], {
    cwd: root,
    encoding: 'utf8',
    timeout: 10_000,
  });
  assert.equal(result.status, 0, result.stderr);
  return JSON.parse(result.stdout);
}

test('workload 域登记 BIZ-001 至 BIZ-005、代表入口和现有消费者', { skip: !hasPhp }, () => {
  const registry = runPhp(String.raw`
    require 'api/platform/BusinessDomainRegistry.php';
    echo json_encode(PlatformBusinessDomainRegistry::get('workload'));
  `);
  assert.deepEqual(registry.function_ids, ['BIZ-001', 'BIZ-002', 'BIZ-003', 'BIZ-004', 'BIZ-005']);
  assert.equal(registry.endpoint, 'api/workload/my-report.php');
  assert.ok(registry.capabilities.includes('state_version'));
  assert.ok(registry.capabilities.includes('platform_job_queue'));
  for (const consumer of registry.legacy_consumers) {
    assert.equal(existsSync(new URL(`../${consumer}`, import.meta.url)), true, consumer);
  }
});

test('工作量同步 Adapter 使用平台同步表持久化版本并输出等级 A 对象', () => {
  const source = read('api/workload/platform/WorkloadPlatformAdapter.php');
  assert.match(source, /PlatformSyncProtocol::syncObject\(/);
  assert.match(source, /PlatformStateVersion::next\(/);
  assert.match(source, /platform_sync_changes/);
  assert.match(source, /'submission'/);
  assert.match(source, /'A'/);
  assert.match(source, /FOR UPDATE/);
});

test('工作量核心读写入口通过 Kernel 和 Adapter 保持兼容响应', () => {
  for (const endpoint of ['api/workload/my-report.php', 'api/workload/save-report.php']) {
    const source = read(endpoint);
    assert.match(source, /kernel\/bootstrap\.php/, endpoint);
    assert.match(source, /WorkloadPlatformAdapter\.php/, endpoint);
    assert.match(source, /platformApiContext\(/, endpoint);
    assert.match(source, /PlatformApiCompatibility::withMetadata\(/, endpoint);
    assert.match(source, /PlatformApiLogger/, endpoint);
    assert.doesNotMatch(source, /workloadEnsureSchema\(/, endpoint);
  }
  assert.match(read('api/workload/save-report.php'), /recordSubmission\(/);
  assert.match(read('api/workload/my-report.php'), /submissionState\(/);
});

test('工作量导出 Adapter 应用临时私有文件策略并保留现有 file_path', { skip: !hasPhp }, () => {
  const policy = runPhp(String.raw`
    require 'api/workload/platform/WorkloadPlatformFileAdapter.php';
    echo json_encode(WorkloadPlatformFileAdapter::policy());
  `);
  assert.equal(policy.asset_class, 'temporary_export');
  assert.equal(policy.access_mode, 'owner_scoped');
  assert.equal(policy.download_expiry_required, true);
  assert.equal(policy.retention_required, true);
  const source = read('api/workload/platform/WorkloadPlatformFileAdapter.php');
  assert.match(source, /PlatformFileAssetPolicy::TEMPORARY_EXPORT/);
  assert.match(read('api/workload/services/WorkloadExportJobService.php'), /PLATFORM_PRIVATE_FILE_ROOT/);
  assert.match(read('api/workload/services/WorkloadExportJobService.php'), /legacyExportDirectory/);
  assert.match(source, /file_path/);
  assert.match(source, /expires_at/);
});

test('平台任务 registry 通过薄 Handler 复用导出和告警领域服务', () => {
  const registry = read('api/platform/jobs/registry.php');
  const exportHandler = read('api/platform/jobs/WorkloadExportJobHandler.php');
  const alertHandler = read('api/platform/jobs/WorkloadAlertJobHandler.php');
  assert.match(registry, /workload\.export\.process/);
  assert.match(registry, /workload\.alert\.run/);
  assert.match(exportHandler, /WorkloadPlatformJobAdapter/);
  assert.match(alertHandler, /WorkloadAlertWorkerService/);
  assert.match(exportHandler + alertHandler, /assertCurrent\(/);
  assert.match(exportHandler + alertHandler, /heartbeatIfDue\(/);
});

test('平台健康检查包含工作量 readiness 且失败关闭', () => {
  const health = read('api/platform/HealthService.php');
  const adapter = read('api/workload/platform/WorkloadPlatformAdapter.php');
  assert.match(health, /WorkloadPlatformAdapter::readiness\(/);
  assert.match(health, /'workload'/);
  assert.match(adapter, /information_schema\.TABLES/);
  assert.match(adapter, /information_schema\.COLUMNS/);
  assert.match(adapter, /schema_not_ready/);
});

test('核心入口、平台 Adapter 和工作量 Worker 不执行运行时 DDL', () => {
  const sources = [
    'api/workload/my-report.php',
    'api/workload/save-report.php',
    'api/workload/platform/WorkloadPlatformAdapter.php',
    'api/workload/platform/WorkloadPlatformFileAdapter.php',
    'api/workload/platform/WorkloadPlatformJobAdapter.php',
    'api/workload/services/WorkloadAlertWorkerService.php',
  ].map(read).join('\n');
  assert.doesNotMatch(sources, /\b(?:CREATE|ALTER|DROP|TRUNCATE)\s+(?:TABLE|INDEX)\b/i);
});
