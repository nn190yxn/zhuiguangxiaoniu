import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { test } from 'node:test';

const root = new URL('..', import.meta.url);
const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const hasPhp = spawnSync('php', ['-v'], { encoding: 'utf8' }).status === 0;

const domains = [
  ['drill', ['BIZ-006'], 'api/drill/v2/home.php'],
  ['skill', ['BIZ-009'], 'api/skill/upload-recording.php'],
  ['reminder', ['MSG-003'], 'api/reminder/jobs.php'],
  ['wecom', ['MSG-001'], 'api/wecom/sync-members.php'],
  ['content', ['BIZ-019', 'BIZ-020', 'BIZ-021', 'BIZ-022'], 'api/campaign/list.php'],
];

const endpointContracts = [
  ['api/drill/v2/home.php', 'drill', /requireAuthenticated\(/],
  ['api/skill/upload-recording.php', 'skill', /requireAuthenticated\(/],
  ['api/reminder/jobs.php', 'reminder', /requirePermission\('reminder\.manage'\)/],
  ['api/wecom/sync-members.php', 'wecom', /requirePermission\('wecom\.sync'\)/],
  ['api/campaign/list.php', 'content', /requireAuthenticated\(/],
];

test('运行时注册表登记五个运营域、稳定功能 ID 和可用消费者', { skip: !hasPhp }, () => {
  const php = String.raw`
    require 'api/platform/BusinessDomainRegistry.php';
    echo json_encode(PlatformBusinessDomainRegistry::all(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  `;
  const result = spawnSync('php', ['-r', php], { cwd: root, encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  const registry = JSON.parse(result.stdout);

  for (const [domain, functionIds, endpoint] of domains) {
    assert.deepEqual(registry[domain].function_ids, functionIds);
    assert.equal(registry[domain].endpoint, endpoint);
    assert.match(registry[domain].endpoint_version, /^\d+\.\d+\.\d+$/);
    assert.ok(registry[domain].legacy_consumers.length > 0);
    assert.ok(registry[domain].capabilities.length > 0);
    assert.equal(existsSync(new URL(`../${endpoint}`, import.meta.url)), true, endpoint);
    for (const consumer of registry[domain].legacy_consumers) {
      assert.equal(existsSync(new URL(`../${consumer}`, import.meta.url)), true, consumer);
    }
  }
});

test('五个代表入口接入 Kernel、认证权限、兼容元数据和结构化审计', () => {
  for (const [endpoint, domain, authContract] of endpointContracts) {
    const source = read(endpoint);
    assert.match(source, /kernel\/bootstrap\.php/, endpoint);
    assert.match(source, new RegExp(`platformApiContext\\(\\['domain' => '${domain}'`), endpoint);
    assert.match(source, /platformApiInstallExceptionHandler\(/, endpoint);
    assert.match(source, /platformApiAuthContext\(/, endpoint);
    assert.match(source, authContract, endpoint);
    assert.match(source, /PlatformApiCompatibility::withMetadata\(/, endpoint);
    assert.match(source, /PlatformApiLogger/, endpoint);
    assert.match(source, /platformApiResponse\(/, endpoint);
  }
});

test('提醒查询保持 GET，手工运行通过 platform_jobs 入队', () => {
  const source = read('api/reminder/jobs.php');
  assert.match(source, /\['GET', 'POST'\]/);
  assert.match(source, /\$method === 'GET'/);
  assert.match(source, /PlatformPdoJobQueueStore/);
  assert.match(source, /'reminder\.schedule\.tick'/);
  assert.match(source, /\['report_date' => \$reportDate, 'phase' => \$phase\]/);
  assert.doesNotMatch(source, /reminderBuild(?:Learning|Workload)Jobs\(/);
  assert.doesNotMatch(source, /reminderDispatchJob\(/);
});

test('企微查询保持 GET，手工同步通过 platform_jobs 入队', () => {
  const source = read('api/wecom/sync-members.php');
  assert.match(source, /\['GET', 'POST'\]/);
  assert.match(source, /\$method === 'GET'/);
  assert.match(source, /PlatformPdoJobQueueStore/);
  assert.match(source, /'wecom\.members\.sync'/);
  assert.doesNotMatch(source, /wecomSyncMembers\(/);
});

test('技能录音进入平台私有存储并保留 recording_url 字段', () => {
  const source = read('api/skill/upload-recording.php');
  assert.match(source, /PlatformPrivateFileStorage/);
  assert.match(source, /->storeUploadedFile\(/);
  assert.match(source, /recording_url/);
  assert.match(source, /local_private/);
  assert.doesNotMatch(source, /uploads\/review-recordings/);
  assert.doesNotMatch(source, /move_uploaded_file\(/);
});

test('AI 仅复用既有权威 Runtime，运营迁移不读取或装配新密钥', () => {
  const skillWorker = read('api/skill/skill-worker.php');
  const drillAdapter = read('api/drill/v2/services/DrillAiAdapter.php');
  assert.match(drillAdapter, /ai-runtime\.php/);
  assert.match(drillAdapter, /ai_gateway_text_generate\(/);
  assert.doesNotMatch(skillWorker, /getenv\([^)]*(?:KEY|SECRET|TOKEN)/i);
  assert.doesNotMatch(skillWorker, /PlatformAiCapabilityGateway/);
  for (const [endpoint] of endpointContracts) {
    assert.doesNotMatch(read(endpoint), /getenv\([^)]*(?:KEY|SECRET|TOKEN)/i, endpoint);
  }
});
