import assert from 'node:assert/strict';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

import { buildProductionBaselineReport, compareBaselines, managedModules, resolveThreeWayFile } from './platform_production_baseline.mjs';

const projectRoot = fileURLToPath(new URL('../', import.meta.url));

test('生产 manifest 与仓库清单生成稳定对比状态', () => {
  const report = buildProductionBaselineReport({ projectRoot });
  const local = report.repository.files.find(({ path }) => path === 'internal.html');
  const comparisons = compareBaselines(report.repository, { files: [
    { path: 'internal.html', exists: true, sha256: local.sha256, byte_size: local.byte_size },
    { path: 'production-only.php', exists: true, sha256: 'a'.repeat(64), byte_size: 12 },
  ] });
  const byPath = new Map(comparisons.map((entry) => [entry.path, entry]));
  assert.equal(byPath.get('internal.html').status, 'same_hash');
  assert.equal(byPath.get('production-only.php').status, 'production_only');
});

test('敏感配置文件只记录存在性和大小', () => {
  const report = buildProductionBaselineReport({ projectRoot });
  const config = report.repository.files.find(({ path }) => path === 'api/config.php');
  assert.equal(config.exists, true);
  assert.equal(config.sha256, null);
  assert.equal(config.hash_status, 'skipped_sensitive');
  assert.equal(typeof config.byte_size, 'number');
});

test('生产敏感 manifest 不透传哈希', () => {
  const [comparison] = compareBaselines({ files: [{ path: 'api/config.php', exists: true, sha256: null, hash_status: 'skipped_sensitive' }] }, { files: [{ path: 'api/config.php', exists: true, sha256: 'b'.repeat(64), byte_size: 30 }] });
  assert.equal(comparison.status, 'hash_unavailable');
  assert.equal(comparison.production.sha256, null);
});

test('重点模块覆盖登录、PWA、工作量、体测和 AI', () => {
  for (const module of ['authentication', 'pwa', 'workload', 'fitness', 'ai']) assert.ok(managedModules[module].length > 0);
});

test('三方状态优先保留服务器基线并标记未闭环来源', () => {
  const server = { exists: true, sha256: 'a'.repeat(64), hash_status: 'available' };
  const local = { exists: true, sha256: 'b'.repeat(64), hash_status: 'available' };
  const github = { exists: true, sha256: 'c'.repeat(64), hash_status: 'available' };
  const serverBaseline = resolveThreeWayFile({ path: 'sw.js', server, local, github });
  const localCandidate = resolveThreeWayFile({ path: 'sw.js', server: local, local, github });
  const synced = resolveThreeWayFile({ path: 'sw.js', server, local: server, github: server });

  assert.equal(serverBaseline.status, 'server_baseline');
  assert.deepEqual(serverBaseline.warnings, ['server_changes_not_recovered_locally', 'local_changes_not_pushed_to_github']);
  assert.equal(localCandidate.status, 'local_candidate');
  assert.equal(synced.status, 'github_synced');
  assert.equal(serverBaseline.server.content_summary, 'aaaaaaaaaaaa');
});

test('manifest 输入可生成报告', () => {
  const root = mkdtempSync(join(tmpdir(), 'platform-baseline-'));
  const manifestPath = join(root, 'manifest.json');
  writeFileSync(manifestPath, JSON.stringify({ files: [] }));
  const report = buildProductionBaselineReport({ projectRoot, productionManifestPath: manifestPath });
  assert.equal(report.production_source, 'manifest');
  assert.equal(report.production.file_count, 0);
});
