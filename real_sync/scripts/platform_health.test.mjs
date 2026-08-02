import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const service = readFileSync(new URL('../api/platform/HealthService.php', import.meta.url), 'utf8');
const endpoint = readFileSync(new URL('../api/platform/health.php', import.meta.url), 'utf8');

test('分层健康服务覆盖 live、ready 和 dependencies', () => {
  assert.match(service, /public static function live\(\)/);
  assert.match(service, /public static function ready\(PDO \$db, array \$versions\)/);
  assert.match(service, /public static function dependencies\(PDO \$db, array \$versions, array \$environment = \[\]\)/);
  assert.match(service, /SELECT 1/);
  assert.match(service, /MigrationReadiness/);
  assert.match(service, /platform_outbox_events/);
  assert.match(service, /platform_jobs/);
  assert.match(service, /oldest_pending_age_seconds/);
  assert.doesNotMatch(service, /platform_job_leases/);
  assert.match(service, /PHP_SAPI === 'cli'/);
  assert.match(service, /'configured' => array_keys/);
  assert.doesNotMatch(service, /DB_PASSWORD|JWT_SECRET|API_KEY\s*=>\s*\$value/);
});

test('健康端点使用 Kernel 响应并限制检查类型', () => {
  assert.match(endpoint, /platformApiContext\(/);
  assert.match(endpoint, /platformApiInstallExceptionHandler\(/);
  assert.match(endpoint, /\['live', 'ready', 'dependencies'\]/);
  assert.match(endpoint, /PlatformHealthService::live\(\)/);
  assert.match(endpoint, /PlatformHealthService::ready\(/);
  assert.match(endpoint, /PlatformHealthService::dependencies\(/);
  assert.match(endpoint, /\$result\['status'\] === 'unhealthy' \? 503 : 200/);
  assert.match(endpoint, /platformApiResponse\(\$context, \['check' => \$check, 'health' => \$result\]/);
  assert.doesNotMatch(endpoint, /echo\s+json_encode/);
});
