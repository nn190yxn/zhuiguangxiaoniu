import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { test } from 'node:test';

const root = new URL('..', import.meta.url);
const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const hasPhp = spawnSync('php', ['-v'], { encoding: 'utf8' }).status === 0;

test('兼容元数据保留业务字段并提供稳定版本', { skip: !hasPhp }, () => {
  const php = String.raw`
    require 'api/kernel/bootstrap.php';
    echo json_encode(PlatformApiCompatibility::withMetadata(
      ['enabled' => false, 'meta' => ['source' => 'legacy']],
      '2.1.0',
      ['status', 'status', 'request_id']
    ));
  `;
  const result = spawnSync('php', ['-r', php], { cwd: root, encoding: 'utf8' });

  assert.equal(result.status, 0, result.stderr);
  const output = JSON.parse(result.stdout);
  assert.equal(output.enabled, false);
  assert.equal(output.meta.source, 'legacy');
  assert.equal(output.meta.api_kernel_version, '1.0.0');
  assert.equal(output.meta.response_contract_version, '1.0');
  assert.equal(output.meta.endpoint_version, '2.1.0');
  assert.deepEqual(output.meta.capabilities, ['status', 'request_id']);
});

test('能力版本端点通过 Kernel 输出请求 ID 与能力元数据', { skip: !hasPhp }, () => {
  const php = String.raw`
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/api/platform/capabilities.php';
    $_SERVER['HTTP_X_REQUEST_ID'] = 'capability-request-1234';
    require 'api/platform/capabilities.php';
  `;
  const result = spawnSync('php', ['-r', php], { cwd: root, encoding: 'utf8' });

  assert.equal(result.status, 0, result.stderr);
  const output = JSON.parse(result.stdout);
  assert.equal(output.code, 0);
  assert.equal(output.request_id, 'capability-request-1234');
  assert.equal(output.data.api_kernel_version, '1.0.0');
  assert.equal(output.data.meta.endpoint_version, '1.3.0');
  assert.equal(output.data.capabilities.includes('state_version_conflict'), true);
  assert.equal(output.data.capabilities.includes('mini_program_device_session'), true);
  assert.equal(output.data.capabilities.includes('mini_program_feature_versions'), true);
  assert.equal(output.data.mini_program.fallback_mode, 'explicit_allowlist');
  assert.equal(output.data.mini_program.features.workload.minimum_client_version, '1.0.0');
  assert.equal(output.data.client_sessions.mini_program.legacy_bearer_compatible, true);
  assert.equal(output.data.capabilities.includes('incremental_cursor'), true);
  assert.equal(output.data.capabilities.includes('server_drafts'), true);
  assert.equal(output.data.sync_contract.levels.A.max_stale_seconds, 30);
  assert.equal(output.data.sync_contract.levels.B.max_stale_seconds, 300);
  assert.equal(output.data.sync_contract.levels.C.max_stale_seconds, 1800);
});

test('首批只读迁移端点保留历史字段并使用 Kernel 门面', () => {
  const health = read('api/admin/staff/data-health.php');
  const status = read('api/wecom/status.php');

  for (const source of [health, status]) {
    assert.match(source, /kernel\/bootstrap\.php/);
    assert.match(source, /platformApiContext/);
    assert.match(source, /platformApiAuthContext/);
    assert.match(source, /PlatformApiCompatibility::withMetadata/);
    assert.match(source, /platformApiResponse\(\$context, \$[a-z]+\)->send\(\)/);
  }
  assert.match(health, /'healthy' => \$result\['healthy'\]/);
  assert.match(status, /'enabled' => \$enabled/);
  assert.match(status, /'directory_sync' => \$enabled/);
  assert.match(status, /'recovery_requirements'/);
});
