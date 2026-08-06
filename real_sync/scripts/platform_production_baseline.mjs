#!/usr/bin/env node

import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { dirname, extname, join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

import { buildPlatformInventory } from './platform_inventory.mjs';
import { functionCoverage } from './platform_function_coverage.mjs';

const currentFile = fileURLToPath(import.meta.url);
const defaultProjectRoot = resolve(dirname(currentFile), '..');
const defaultProductionRoot = '/www/wwwroot/122.51.223.46';
const defaultWorkspaceRoot = resolve(defaultProjectRoot, '..');
const sensitivePathPattern = /(^|\/)(?:\.env|config\.php|\.env\.[^/]+|.*secret.*|.*credential.*|.*private.*|.*key.*)(?:$|\.)/i;
const releaseStatuses = new Set(['server_baseline', 'local_candidate', 'github_synced', 'release_ready', 'production_verified']);

export const managedModules = Object.freeze({
  authentication: ['mobile/login.html', 'js/app-auth.js', 'api/auth-jwt.php', 'api/auth/refresh.php', 'api/auth/SessionFactory.php', 'scripts/platform_session_service.test.mjs'],
  pwa: ['sw.js', 'manifest.webmanifest', 'mobile/login.html', 'scripts/mobile_pwa_shell.test.mjs'],
  workload: ['admin/workload.html', 'api/workload/my-report.php', 'api/workload/audit-list.php', 'api/workload/services/WorkloadConversionResultQueryService.php', 'api/workload/services/WorkloadMetricSelectionService.php', 'scripts/workload_conversion_results.test.mjs'],
  fitness: ['fitness-assessment-app.html', 'scripts/fitness_assessment_ocr.test.mjs'],
  ai: ['api/ai-runtime.php', 'api/ai-services.php', 'scripts/ai_runtime_convergence.test.mjs'],
});

function normalizePath(path) {
  return path.split(sep).join('/');
}

function sha256(value) {
  return createHash('sha256').update(value).digest('hex');
}

function isSensitivePath(path) {
  return sensitivePathPattern.test(path);
}

function inferType(path) {
  if (path.startsWith('api/') && path.endsWith('.php')) return 'api';
  if (path.endsWith('.sql')) return 'migration';
  if (path === 'sw.js' || path.endsWith('.webmanifest')) return 'pwa';
  if (path.endsWith('.html')) return 'page';
  if (path.startsWith('mini-program/')) return 'mini-page';
  return extname(path).replace(/^\./, '') || 'file';
}

function fileMetadata(root, path, extra = {}) {
  const absolute = join(root, path);
  if (!existsSync(absolute)) return { path, exists: false, sha256: null, hash_status: 'missing', byte_size: null, mtime_ms: null, ...extra };
  const stats = statSync(absolute);
  if (stats.isDirectory()) {
    return { path, exists: true, sha256: null, hash_status: 'directory', byte_size: null, mtime_ms: Math.trunc(stats.mtimeMs), ...extra };
  }
  const sensitive = isSensitivePath(path);
  return {
    path,
    exists: true,
    sha256: sensitive ? null : sha256(readFileSync(absolute)),
    hash_status: sensitive ? 'skipped_sensitive' : 'available',
    byte_size: stats.size,
    mtime_ms: Math.trunc(stats.mtimeMs),
    ...extra,
  };
}

function expectedPathsFromInventory(inventory) {
  const paths = new Set(inventory.assets.map(({ path }) => path));
  for (const entry of functionCoverage) {
    for (const field of ['executable_items', 'static_evidence', 'automated_tests']) {
      for (const path of entry[field] || []) if (!path.startsWith('external:')) paths.add(path);
    }
  }
  for (const pathsByModule of Object.values(managedModules)) for (const path of pathsByModule) paths.add(path);
  return [...paths].sort();
}

export function buildRepositoryBaseline({ projectRoot = defaultProjectRoot } = {}) {
  const root = resolve(projectRoot);
  const inventory = buildPlatformInventory({ projectRoot: root });
  const assets = new Map(inventory.assets.map((asset) => [asset.path, asset]));
  const files = expectedPathsFromInventory(inventory).map((path) => {
    const asset = assets.get(path);
    return fileMetadata(root, path, { type: asset?.type || inferType(path), group_ids: asset?.group_ids || [], ownership: asset?.ownership || 'repository-baseline' });
  });
  return { root: normalizePath(root), file_count: files.length, missing_count: files.filter(({ exists }) => !exists).length, sensitive_hash_skipped_count: files.filter(({ hash_status }) => hash_status === 'skipped_sensitive').length, files };
}

function normalizeManifestFile(entry) {
  const path = normalizePath(entry.path || '');
  const sensitive = isSensitivePath(path);
  return {
    path,
    exists: entry.exists !== false,
    sha256: sensitive ? null : entry.sha256 || null,
    hash_status: sensitive ? 'skipped_sensitive' : entry.hash_status || (entry.sha256 ? 'available' : 'unavailable'),
    byte_size: Number.isFinite(entry.byte_size) ? entry.byte_size : null,
    mtime_ms: Number.isFinite(entry.mtime_ms) ? entry.mtime_ms : null,
    type: entry.type || inferType(path),
    ownership: entry.ownership || 'production-runtime',
  };
}

export function loadProductionManifest(path) {
  if (!path) return null;
  const manifest = JSON.parse(readFileSync(resolve(path), 'utf8'));
  const rawFiles = Array.isArray(manifest.files) ? manifest.files : manifest.production?.files || [];
  return { root: manifest.root || manifest.production_root || defaultProductionRoot, file_count: rawFiles.length, files: rawFiles.map(normalizeManifestFile).filter(({ path }) => path) };
}

function summary(entry) {
  if (!entry) return null;
  return { exists: entry.exists, sha256: entry.sha256, content_summary: entry.sha256 ? entry.sha256.slice(0, 12) : null, hash_status: entry.hash_status, byte_size: entry.byte_size, mtime_ms: entry.mtime_ms, commit_at: entry.commit_at || null };
}

export function compareBaselines(repository, production) {
  if (!production) return [];
  const local = new Map(repository.files.map((entry) => [entry.path, entry]));
  const server = new Map(production.files.map(normalizeManifestFile).map((entry) => [entry.path, entry]));
  return [...new Set([...local.keys(), ...server.keys()])].sort().map((path) => {
    const repositoryFile = local.get(path) || null;
    const productionFile = server.get(path) || null;
    const status = repositoryFile?.exists && productionFile?.exists
      ? (repositoryFile.sha256 && productionFile.sha256 ? (repositoryFile.sha256 === productionFile.sha256 ? 'same_hash' : 'different_hash') : 'hash_unavailable')
      : repositoryFile?.exists ? 'repository_only' : productionFile?.exists ? 'production_only' : 'missing_both';
    return { path, status, repository: summary(repositoryFile), production: summary(productionFile) };
  });
}

function gitFileMetadata(workspaceRoot, reference, path) {
  const relativePath = `real_sync/${path}`;
  const content = spawnSync('git', ['show', `${reference}:${relativePath}`], { cwd: workspaceRoot, encoding: null });
  if (content.status !== 0) return { path, exists: false, sha256: null, hash_status: 'missing', byte_size: null, mtime_ms: null, commit_at: null };
  const date = spawnSync('git', ['log', '-1', '--format=%cI', reference, '--', relativePath], { cwd: workspaceRoot, encoding: 'utf8' });
  const commitAt = date.status === 0 && date.stdout.trim() ? Date.parse(date.stdout.trim()) : null;
  const sensitive = isSensitivePath(path);
  return { path, exists: true, sha256: sensitive ? null : sha256(content.stdout), hash_status: sensitive ? 'skipped_sensitive' : 'available', byte_size: content.stdout.length, mtime_ms: commitAt, commit_at: date.stdout.trim() || null };
}

function moduleForPath(path) {
  return Object.entries(managedModules).find(([, paths]) => paths.includes(path))?.[0] || 'other';
}

function resolveStatus({ server, local, github, override }) {
  if (override && releaseStatuses.has(override)) return override;
  if (server?.exists && (!local?.exists || server.sha256 !== local.sha256) && server.hash_status === 'available') return 'server_baseline';
  if (local?.exists && github?.exists && local.sha256 === github.sha256 && (!server?.exists || server.sha256 === local.sha256)) return 'github_synced';
  if (local?.exists && (!github?.exists || local.sha256 !== github.sha256)) return 'local_candidate';
  return 'server_baseline';
}

function statusWarnings(status, server, local, github) {
  const warnings = [];
  if (status === 'server_baseline' && (!local?.exists || server?.sha256 !== local?.sha256)) warnings.push('server_changes_not_recovered_locally');
  if (local?.exists && (!github?.exists || local.sha256 !== github.sha256)) warnings.push('local_changes_not_pushed_to_github');
  if (status === 'release_ready') warnings.push('requires_production_backup_and_validation_evidence');
  if (status === 'production_verified') warnings.push('requires_recorded_authorized_session_validation');
  return warnings;
}

export function resolveThreeWayFile({ path, server, local, github, statusOverride = null }) {
  const status = resolveStatus({ server, local, github, override: statusOverride });
  return {
    path,
    module: moduleForPath(path),
    status,
    server: summary(server),
    local: summary(local),
    github: summary(github),
    warnings: statusWarnings(status, server, local, github),
  };
}

function loadStatusOverrides(path) {
  if (!path) return {};
  const source = JSON.parse(readFileSync(resolve(path), 'utf8'));
  return source.files && typeof source.files === 'object' ? source.files : source;
}

export function buildThreeWayBaselineReport({ projectRoot = defaultProjectRoot, productionManifestPath, workspaceRoot = defaultWorkspaceRoot, githubRef = 'origin/master', statusOverrides = {} } = {}) {
  const repository = buildRepositoryBaseline({ projectRoot });
  const production = loadProductionManifest(productionManifestPath);
  const localByPath = new Map(repository.files.map((entry) => [entry.path, entry]));
  const serverByPath = new Map((production?.files || []).map((entry) => [entry.path, entry]));
  const paths = [...new Set(Object.values(managedModules).flat())].sort();
  const files = paths.map((path) => {
    const local = localByPath.get(path) || fileMetadata(resolve(projectRoot), path);
    const server = serverByPath.get(path) || { path, exists: false, sha256: null, hash_status: 'missing', byte_size: null, mtime_ms: null };
    const github = gitFileMetadata(resolve(workspaceRoot), githubRef, path);
    return resolveThreeWayFile({ path, server, local, github, statusOverride: statusOverrides[path] });
  });
  const counts = Object.fromEntries([...releaseStatuses].map((status) => [status, files.filter((file) => file.status === status).length]));
  return { schema_version: 2, generated_at: new Date().toISOString(), production_root: production?.root || defaultProductionRoot, github_ref: githubRef, files, summary: { status_counts: counts, warning_count: files.reduce((count, file) => count + file.warnings.length, 0) } };
}

export function buildProductionBaselineReport({ projectRoot = defaultProjectRoot, productionManifestPath = null } = {}) {
  const repository = buildRepositoryBaseline({ projectRoot });
  const production = loadProductionManifest(productionManifestPath);
  const comparisons = compareBaselines(repository, production);
  const counts = Object.fromEntries([...new Set(comparisons.map(({ status }) => status))].sort().map((status) => [status, comparisons.filter((item) => item.status === status).length]));
  return { schema_version: 1, generated_at: new Date().toISOString(), production_root: production?.root || defaultProductionRoot, production_source: production ? 'manifest' : 'not_provided', repository, production, comparisons, summary: { repository_file_count: repository.file_count, repository_missing_count: repository.missing_count, comparison_count: comparisons.length, comparison_status_counts: counts } };
}

function argumentValue(name) {
  const index = process.argv.indexOf(name);
  return index >= 0 ? process.argv[index + 1] : null;
}

if (process.argv[1] && resolve(process.argv[1]) === currentFile) {
  const output = argumentValue('--output');
  const productionManifestPath = argumentValue('--production-manifest');
  const report = process.argv.includes('--three-way')
    ? buildThreeWayBaselineReport({ projectRoot: argumentValue('--root') || defaultProjectRoot, productionManifestPath, githubRef: argumentValue('--github-ref') || 'origin/master', statusOverrides: loadStatusOverrides(argumentValue('--status-overrides')) })
    : buildProductionBaselineReport({ projectRoot: argumentValue('--root') || defaultProjectRoot, productionManifestPath });
  const json = `${JSON.stringify(report, null, 2)}\n`;
  if (output) {
    const path = resolve(output);
    mkdirSync(dirname(path), { recursive: true });
    writeFileSync(path, json);
  } else process.stdout.write(json);
}
