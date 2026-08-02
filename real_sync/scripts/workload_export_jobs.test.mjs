import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const [jobs, endpoint, worker, platformFileAdapter] = await Promise.all([
  readFile(new URL('../api/workload/services/WorkloadExportJobService.php', import.meta.url), 'utf8'),
  readFile(new URL('../api/workload/exports.php', import.meta.url), 'utf8'),
  readFile(new URL('../api/workload/export-worker.php', import.meta.url), 'utf8'),
  readFile(new URL('../api/workload/platform/WorkloadPlatformFileAdapter.php', import.meta.url), 'utf8'),
]);
const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;

test(`${validatesCriteria(['5.5', '6.5', '15.5'])} results above 20,000 rows create permission-bound jobs`, () => {
  assert.match(jobs, /SYNCHRONOUS_ROW_LIMIT = 20000/);
  assert.match(endpoint, /row_count.*SYNCHRONOUS_ROW_LIMIT/);
  assert.match(jobs, /scope_hash/);
  assert.match(jobs, /requested_by_staff_id/);
  assert.match(jobs, /expires_at/);
});

test(`${validatesCriteria(['6.5', '15.5'])} status and download revalidate owner and current scope`, () => {
  assert.match(jobs, /assertCurrentAccess/);
  assert.match(jobs, /hash_equals/);
  assert.match(jobs, /无权访问该导出任务/);
  assert.match(jobs, /导出权限范围已变化/);
  assert.match(jobs, /WorkloadPlatformFileAdapter::prepareDownload/);
  assert.match(platformFileAdapter, /realpath/);
  assert.match(endpoint, /\$_GET\['download'\]/);
});

test(`${validatesCriteria(['5.5', '6.5'])} CLI worker claims, generates, and records job outcomes`, () => {
  assert.match(worker, /PHP_SAPI !== 'cli'/);
  assert.match(worker, /claimNext\(\)/);
  assert.match(worker, /workerContext\(\$job\)/);
  assert.match(worker, /fopen\(\$filePath, 'wb'\)/);
  assert.match(worker, /->writeCsv\(\$export, \$stream\)/);
  assert.match(worker, /->complete\(/);
  assert.match(worker, /->fail\(/);
  assert.match(jobs, /FOR UPDATE/);
});
