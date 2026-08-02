import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { test } from 'node:test';

const root = new URL('..', import.meta.url);
const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const hasPhp = spawnSync('php', ['-v'], { encoding: 'utf8' }).status === 0;

const servicePath = 'api/platform/LegacyEndpointGovernance.php';
const catalogPath = 'api/platform/legacy_endpoint_catalog.php';
const migrationPath = 'database/migrations/202608020003_platform_legacy_endpoint_governance.sql';

test('历史入口治理使用冻结 catalog 且排除健康与 canonical 平台入口', { skip: !hasPhp }, () => {
  assert.equal(existsSync(new URL(`../${catalogPath}`, import.meta.url)), true);
  const php = String.raw`
    $catalog = require '${catalogPath}';
    echo json_encode($catalog, JSON_UNESCAPED_SLASHES);
  `;
  const result = spawnSync('php', ['-r', php], { cwd: root, encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  const catalog = JSON.parse(result.stdout);
  assert.ok(catalog.length >= 13);
  assert.ok(catalog.every((entry) => entry.endpoint.startsWith('/api/')));
  assert.ok(catalog.every((entry) => entry.method && entry.consumer && entry.domain));
  assert.equal(catalog.some(({ endpoint }) => endpoint === '/api/platform/health.php'), false);
  assert.equal(catalog.some(({ endpoint }) => endpoint === '/api/platform/capabilities.php'), false);
});

test('增量迁移提供幂等调用、聚合、审批和审计结构且保持 additive', () => {
  const migration = read(migrationPath);
  for (const table of [
    'platform_legacy_endpoints',
    'platform_legacy_endpoint_invocations',
    'platform_legacy_endpoint_retirement_approvals',
    'platform_legacy_endpoint_audit_events',
  ]) {
    assert.match(migration, new RegExp(`CREATE TABLE IF NOT EXISTS ${table}\\b`));
  }
  for (const column of [
    'endpoint', 'http_method', 'consumer', 'domain_code', 'invocation_count', 'last_invoked_at',
    'migration_status', 'replacement_endpoint', 'owner', 'observation_window_started_at',
    'rollback_plan', 'evidence_json', 'approved_by', 'approved_at',
  ]) {
    assert.match(migration, new RegExp(`\\b${column}\\b`));
  }
  assert.match(migration, /UNIQUE KEY `uq_platform_legacy_endpoint_identity`/);
  assert.match(migration, /UNIQUE KEY `uq_platform_legacy_invocation_key`/);
  assert.match(migration, /UNIQUE KEY `uq_platform_legacy_retirement_idempotency`/);
  assert.match(migration, /INSERT INTO platform_legacy_endpoints/);
  assert.match(migration, /ON DUPLICATE KEY UPDATE owner/);
  assert.doesNotMatch(migration, /\b(?:DROP|TRUNCATE|RENAME)\b/i);
});

test('服务对调用写入 fail-open，对退役判断 fail-closed', () => {
  const service = read(servicePath);
  assert.match(service, /function recordInvocation\(/);
  assert.match(service, /INSERT IGNORE INTO platform_legacy_endpoint_invocations/);
  assert.match(service, /ON DUPLICATE KEY UPDATE/);
  assert.match(service, /legacy_endpoint\.invocation_record_failed/);
  assert.match(service, /'recorded' => false/);
  assert.match(service, /function retirementDecision\(/);
  assert.match(service, /schema_not_ready/);
  assert.match(service, /'eligible' => false/);
});

test('退役门禁为每个缺失条件返回机器可读 blocker', { skip: !hasPhp }, () => {
  const php = String.raw`
    require '${servicePath}';
    $decision = LegacyEndpointGovernance::evaluateRetirementSnapshot([
      'migration_status' => 'migrating',
      'invocation_count' => 3,
      'last_invoked_at' => '2026-08-02 00:00:00',
      'observation_window_started_at' => null,
      'observation_window_days' => 30,
      'replacement_endpoint' => null,
      'replacement_status' => 'unknown',
      'owner' => null,
      'approval_status' => 'submitted',
      'rollback_plan' => null,
      'evidence' => [],
    ], new DateTimeImmutable('2026-09-15 00:00:00'));
    echo json_encode($decision);
  `;
  const result = spawnSync('php', ['-r', php], { cwd: root, encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  const decision = JSON.parse(result.stdout);
  assert.equal(decision.eligible, false);
  const blockers = new Set(decision.blockers.map(({ code }) => code));
  for (const code of [
    'migration_status_not_eligible',
    'observation_window_missing',
    'invocations_in_observation_window',
    'replacement_endpoint_missing',
    'replacement_unavailable',
    'owner_missing',
    'retirement_approval_missing',
    'rollback_plan_missing',
    'evidence_incomplete',
  ]) assert.ok(blockers.has(code), code);
});

test('完整退役证据通过纯判定且不会执行入口删除或禁用', { skip: !hasPhp }, () => {
  const php = String.raw`
    require '${servicePath}';
    echo json_encode(LegacyEndpointGovernance::evaluateRetirementSnapshot([
      'migration_status' => 'eligible',
      'invocation_count' => 0,
      'last_invoked_at' => '2026-07-01 00:00:00',
      'observation_window_started_at' => '2026-08-01 00:00:00',
      'observation_window_days' => 30,
      'replacement_endpoint' => '/api/v2/example',
      'replacement_status' => 'available',
      'owner' => 'platform-team',
      'approval_status' => 'approved',
      'rollback_plan' => 'Restore the compatibility controller and verify the contract snapshot.',
      'evidence' => [
        'contract_regression_passed' => true,
        'consumer_inventory_complete' => true,
        'replacement_health_verified' => true,
        'rollback_plan_tested' => true,
      ],
    ], new DateTimeImmutable('2026-09-15 00:00:00')));
  `;
  const result = spawnSync('php', ['-r', php], { cwd: root, encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  assert.deepEqual(JSON.parse(result.stdout), { eligible: true, blockers: [] });
  const service = read(servicePath);
  assert.doesNotMatch(service, /\b(?:unlink|DROP|DELETE\s+FROM)\b/i);
});

test('Kernel 兼容统一路径自动统计且避免端点重复埋点', () => {
  const bootstrap = read('api/kernel/bootstrap.php');
  const compatibility = read('api/kernel/Compatibility.php');
  assert.match(bootstrap, /LegacyEndpointGovernance\.php/);
  assert.match(bootstrap, /PlatformApiCompatibility::observeLegacyInvocation/);
  assert.match(compatibility, /function observeLegacyInvocation\(/);
  assert.match(compatibility, /legacy_endpoint_catalog\.php/);
  assert.match(compatibility, /PlatformBusinessDomainRegistry/);
  assert.match(compatibility, /platform\/health\.php|health\.php/);
  assert.match(compatibility, /platform\/capabilities\.php|capabilities\.php/);
  assert.ok(
    bootstrap.indexOf('PlatformApiCompatibility::observeLegacyInvocation')
      < bootstrap.indexOf('function platformApiResponse'),
    '调用统计必须在业务响应前发生，以覆盖异常请求',
  );
});

test('退役审批使用行锁、独立审批人与状态冲突保护', () => {
  const service = read(servicePath);
  assert.match(service, /approval\(\$db, \$approvalId, true\)/);
  assert.match(service, /retirement_approval_requires_distinct_reviewer/);
  assert.match(service, /retirement_approval_not_submitted/);
  assert.match(service, /retirement_approval_state_conflict/);
});

test('管理端点使用四项具名权限并结构化审计全部变更', () => {
  const permissions = read('api/admin/common.php');
  for (const permission of [
    'legacy_endpoint.view',
    'legacy_endpoint.manage',
    'legacy_endpoint.retirement_submit',
    'legacy_endpoint.retirement_approve',
  ]) assert.match(permissions, new RegExp(permission.replace('.', '\\.')));

  const endpoints = {
    'api/admin/platform/legacy-endpoints.php': 'legacy_endpoint.view',
    'api/admin/platform/legacy-endpoint-status.php': 'legacy_endpoint.manage',
    'api/admin/platform/legacy-endpoint-retirement-submit.php': 'legacy_endpoint.retirement_submit',
    'api/admin/platform/legacy-endpoint-retirement-approve.php': 'legacy_endpoint.retirement_approve',
  };
  for (const [path, permission] of Object.entries(endpoints)) {
    const source = read(path);
    assert.match(source, new RegExp(`requirePermission\\('${permission.replace('.', '\\.')}\\'\\)`));
    assert.match(source, /PlatformApiLogger/);
  }
  assert.match(read(servicePath), /platform_legacy_endpoint_audit_events/);
});

test('readiness、inventory 和覆盖清单纳入历史入口治理证据', () => {
  const health = read('api/platform/HealthService.php');
  const inventory = read('scripts/platform_inventory.mjs');
  const coverage = read('scripts/platform_function_coverage.mjs');
  const preflight = read('scripts/platform_preflight.mjs');
  assert.match(health, /LegacyEndpointGovernance::readiness/);
  assert.match(inventory, /platform_legacy_endpoint_governance/);
  assert.match(coverage, /platform_legacy_endpoint_governance\.test\.mjs/);
  assert.match(preflight, /legacy_endpoint_governance/);
});
