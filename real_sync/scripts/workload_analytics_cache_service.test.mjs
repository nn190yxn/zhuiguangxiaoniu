import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { spawnSync } from 'node:child_process';
import { test } from 'node:test';

const servicePath = new URL('../api/workload/services/WorkloadAnalyticsCacheService.php', import.meta.url);
const service = readFileSync(servicePath, 'utf8');
const hasPhp = spawnSync('php', ['-v'], { encoding: 'utf8' }).status === 0;

function executePhp(program) {
  const result = spawnSync('php', ['-r', program], { encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  return JSON.parse(result.stdout);
}

test('[validates 9.3] cache keys isolate permissions, sources, filters, and metric versions', { skip: !hasPhp }, () => {
  const directory = mkdtempSync(join(tmpdir(), 'workload-cache-key-'));
  const output = executePhp(`
    require ${JSON.stringify(servicePath.pathname)};
    $cache = new WorkloadAnalyticsCacheService(${JSON.stringify(directory)});
    $filters = ['date_from'=>'2026-07-01','date_to'=>'2026-07-31','sources'=>['h5'],'store_ids'=>[],
      'role_codes'=>['sales'],'staff_ids'=>[],'metric_codes'=>['visits'],'report_statuses'=>[],'audit_statuses'=>[]];
    $staff = ['scope_type'=>'staff','staff_id'=>10,'store_ids'=>[],'can_export'=>true];
    $manager = ['scope_type'=>'stores','staff_id'=>20,'store_ids'=>[2,1],'can_export'=>true];
    $managerReordered = ['can_export'=>true,'store_ids'=>[1,2],'staff_id'=>20,'scope_type'=>'stores'];
    echo json_encode([
      $cache->key('facts', $filters, $staff, 'v1'),
      $cache->key('facts', $filters, $manager, 'v1'),
      $cache->key('facts', $filters, $managerReordered, 'v1'),
      $cache->key('facts', $filters, $manager, 'v2')
    ]);
  `);
  assert.notEqual(output[0], output[1]);
  assert.equal(output[1], output[2]);
  assert.notEqual(output[1], output[3]);
  for (const dimension of ['date_from', 'date_to', 'permission_scope', 'sources', 'store_ids', 'staff_ids', 'metric_codes', 'metric_version']) {
    assert.match(service, new RegExp(`'${dimension}'`));
  }
});

test('[validates 9.4] invalidation removes only cache entries affected by a staff report change', { skip: !hasPhp }, () => {
  const directory = mkdtempSync(join(tmpdir(), 'workload-cache-invalidate-'));
  const output = executePhp(`
    require ${JSON.stringify(servicePath.pathname)};
    $cache = new WorkloadAnalyticsCacheService(${JSON.stringify(directory)});
    $filters = ['date_from'=>'2026-07-01','date_to'=>'2026-07-31','sources'=>[],'store_ids'=>[],
      'role_codes'=>[],'staff_ids'=>[],'metric_codes'=>[]];
    $scopeA = ['scope_type'=>'staff','staff_id'=>10];
    $scopeB = ['scope_type'=>'staff','staff_id'=>11];
    $keyA = $cache->key('facts', $filters, $scopeA, 'v1');
    $keyB = $cache->key('facts', $filters, $scopeB, 'v1');
    $cache->put($keyA, ['owner'=>10], $cache->dependencies($filters, $scopeA, 'v1'));
    $cache->put($keyB, ['owner'=>11], $cache->dependencies($filters, $scopeB, 'v1'));
    $count = $cache->invalidate(['date'=>'2026-07-24','staff_id'=>10]);
    echo json_encode([$count, $cache->get($keyA), $cache->get($keyB)]);
  `);
  assert.equal(output[0], 1);
  assert.equal(output[1], null);
  assert.deepEqual(output[2], { owner: 11 });
});

test('[validates 9.3, 9.4] analytics reads through bounded cache and write paths invalidate after changes', () => {
  const analytics = readFileSync(new URL('../api/workload/services/WorkloadAnalyticsQueryService.php', import.meta.url), 'utf8');
  const paths = [
    '../api/workload/save-report.php',
    '../api/workload/audit-action.php',
    '../api/workload/correct-report.php',
    '../api/workload/obligation-worker.php',
    '../api/workload/obligation-backfill-worker.php',
  ];
  assert.match(analytics, /\$this->cache->get\(\$cacheKey\)/);
  assert.match(analytics, /\$this->cache->put\(/);
  assert.match(service, /MAX_ENTRY_BYTES = 5242880/);
  for (const path of paths) {
    assert.match(readFileSync(new URL(path, import.meta.url), 'utf8'), /WorkloadAnalyticsCacheService[\s\S]*->invalidate\(/);
  }
});
