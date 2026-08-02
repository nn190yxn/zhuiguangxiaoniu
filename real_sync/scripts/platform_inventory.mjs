#!/usr/bin/env node

import { createHash } from 'node:crypto';
import { existsSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { dirname, extname, join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const currentFile = fileURLToPath(import.meta.url);
const defaultProjectRoot = resolve(dirname(currentFile), '..');
const defaultWorkspaceRoot = resolve(defaultProjectRoot, '..');
const defaultMatrixPath = join(
  defaultWorkspaceRoot,
  '.monkeycode/specs/2026-07-31-full-site-multi-client-architecture-upgrade/function-matrix.md',
);

const platform_legacy_endpoint_governance = Object.freeze([
  'api/platform/LegacyEndpointGovernance.php',
  'api/platform/legacy_endpoint_catalog.php',
  'database/migrations/202608020003_platform_legacy_endpoint_governance.sql',
  'scripts/platform_legacy_endpoint_governance.test.mjs',
]);

const lifecycleValues = new Set([
  'planned',
  'in_development',
  'implemented',
  'deployed',
  'verified',
  'deprecated',
]);

const requiredAssetTypes = new Set([
  'ai',
  'api',
  'cron',
  'file',
  'migration',
  'mini-page',
  'page',
  'pwa',
  'worker',
]);

const ignoredDirectories = new Set([
  '.git',
  '.idea',
  '.vscode',
  'node_modules',
  'vendor',
]);

const frozenPrefixes = [
  'admin/recruitment-',
  'admin/workload.html',
  'api/admin/common.php',
  'api/admin/recruitment/',
  'api/recruitment/',
  'api/workload/',
  'database/migration_manifest.php',
  'database/migrations/202607310001_recruitment_resume_screening.sql',
  'mini-program/pages/workload/',
  'mobile/workload.html',
  'mobile/workload-v2.html',
  'scripts/migration_idempotency.test.mjs',
  'scripts/recruitment_resume_',
  'scripts/workload',
];

const domainRules = [
  [/recruitment|resume/i, ['BIZ-010', 'BIZ-011', 'BIZ-012']],
  [/workload/i, ['BIZ-001', 'BIZ-002', 'BIZ-003', 'BIZ-004', 'BIZ-005']],
  [/drill\/v2|drill[-_](?:ai|media|mobile|content|execution|learning|plan)/i, ['BIZ-006']],
  [/(?:^|\/)drill(?:\/|[-_])|weekly-drills/i, ['BIZ-007']],
  [/(?:^|\/)skill(?:\/|[-_])/i, ['BIZ-009']],
  [/(?:^|\/)learning(?:\/|[-_])|lesson/i, ['BIZ-014']],
  [/(?:^|\/)knowledge(?:\/|[-_])/i, ['BIZ-015']],
  [/(?:^|\/)exam(?:\/|[-_])/i, ['BIZ-016']],
  [/(?:^|\/)pass(?:\/|[-_])/i, ['BIZ-017']],
  [/(?:^|\/)policy(?:\/|[-_])|制度|体系文件/i, ['BIZ-018']],
  [/(?:^|\/)survey(?:\/|[-_])/i, ['BIZ-019']],
  [/(?:^|\/)campaign(?:\/|[-_])|周年庆/i, ['BIZ-020']],
  [/summer[-_]?camp|夏令营/i, ['BIZ-021']],
  [/physical|fitness|ai-services/i, ['BIZ-022']],
  [/(?:^|\/)points?(?:\/|[-_])/i, ['BIZ-023']],
  [/(?:^|\/)statistics?(?:\/|[-_])|monthly_stats/i, ['BIZ-024']],
  [/(?:^|\/)wecom(?:\/|[-_])/i, ['IAM-003', 'MSG-001', 'MSG-002']],
  [/(?:^|\/)reminder(?:\/|[-_])/i, ['MSG-003']],
  [/notification/i, ['MSG-004']],
  [/(?:^|\/)todos?(?:\/|[-_])/i, ['MSG-005']],
  [/(?:^|\/)auth(?:\/|[-_])|auth-jwt/i, ['IAM-001']],
  [/(?:^|\/)staff(?:\/|[-_])|staffs/i, ['IAM-006', 'IAM-007']],
  [/(?:^|\/)organization(?:\/|[-_])|assignment|position|store-service/i, ['IAM-009']],
  [/(?:^|\/)search(?:\/|[-_])/i, ['WEB-008']],
  [/(?:^|\/)stores?(?:\/|[-_])/i, ['WEB-002']],
  [/(?:^|\/)courses?(?:\/|[-_])/i, ['WEB-003']],
  [/(?:^|\/)news(?:\/|[-_])/i, ['WEB-004']],
  [/training|lessons/i, ['WEB-005']],
];

function normalizePath(path) {
  return path.split(sep).join('/');
}

function walkFiles(root, accept, prefix = '') {
  const directory = join(root, prefix);
  if (!existsSync(directory)) return [];

  const files = [];
  for (const entry of readdirSync(directory, { withFileTypes: true })) {
    if (entry.name.startsWith('.') || ignoredDirectories.has(entry.name)) continue;
    const child = join(prefix, entry.name);
    if (entry.isDirectory()) files.push(...walkFiles(root, accept, child));
    if (entry.isFile() && accept(child)) files.push(normalizePath(child));
  }
  return files;
}

function stableAssetId(type, path) {
  const digest = createHash('sha256').update(`${type}:${path}`).digest('hex').slice(0, 12);
  return `ASSET-${type.toUpperCase()}-${digest}`;
}

function parseTableCells(line) {
  return line
    .split('|')
    .slice(1, -1)
    .map((cell) => cell.trim());
}

export function parseCapabilityMatrix(source) {
  const groups = [];
  for (const line of source.split('\n')) {
    if (!/^\| (?:WEB|IAM|BIZ|MSG|CLIENT|PLATFORM|FASTAPI)-\d{3} /.test(line)) continue;
    const cells = parseTableCells(line);
    const [identity, ...titleParts] = cells[0].split(' ');
    const lifecycleMatch = cells.join(' ').match(/`(planned|in_development|implemented|deployed|verified|deprecated)`/);
    const governanceMatch = cells.at(-1)?.match(/^(保留|完善|重构|补建|合并|兼容承接)/);
    groups.push({
      id: identity,
      title: titleParts.join(' '),
      lifecycle: lifecycleMatch?.[1] || 'planned',
      governance: governanceMatch?.[1] || '完善',
      evidence: cells.slice(1, -1).join(' | '),
      target: cells.at(-1) || '',
    });
  }
  return groups;
}

function relatedDomainGroups(path) {
  const matches = domainRules.flatMap(([pattern, groups]) => (pattern.test(path) ? groups : []));
  return [...new Set(matches)];
}

function groupsForAsset(type, path) {
  const groups = relatedDomainGroups(path);

  if (type === 'api') groups.push('PLATFORM-001');
  if (type === 'worker') groups.push('PLATFORM-012');
  if (type === 'cron') groups.push('PLATFORM-013');
  if (type === 'migration') groups.push('PLATFORM-004');
  if (type === 'file') groups.push('PLATFORM-011');
  if (type === 'ai') groups.push('PLATFORM-006');
  if (type === 'pwa') groups.push('CLIENT-001', 'CLIENT-002');
  if (type === 'mini-page') groups.push('CLIENT-006');

  if (type === 'page' && groups.length === 0) {
    if (path === 'index.html') groups.push('WEB-001');
    else if (path === 'internal.html') groups.push('WEB-007');
    else if (path.startsWith('admin/')) groups.push('WEB-009');
    else groups.push('WEB-012');
  }
  if (groups.length === 0) groups.push('PLATFORM-001');
  return [...new Set(groups)];
}

function isFrozen(path) {
  return frozenPrefixes.some((prefix) => path === prefix || path.startsWith(prefix));
}

function asset(type, path, extra = {}) {
  return {
    id: stableAssetId(type, path),
    type,
    path,
    group_ids: groupsForAsset(type, path),
    ownership: isFrozen(path) ? 'parallel-change-frozen' : 'architecture-upgrade',
    ...extra,
  };
}

function discoverMiniPages(projectRoot) {
  const appPath = join(projectRoot, 'mini-program/app.json');
  if (!existsSync(appPath)) return [];
  const app = JSON.parse(readFileSync(appPath, 'utf8'));
  return (Array.isArray(app.pages) ? app.pages : []).map((route) => asset('mini-page', `mini-program/${route}`));
}

function discoverContentAssets(projectRoot, phpFiles) {
  const fileAssets = [];
  const aiAssets = [];
  const filePattern = /upload|download|export|file|media|attachment/i;
  const aiPattern = /ai-runtime|callAI|callAi|ocr|speech|transcrib|vision|doubao|deepseek|zhipu/i;

  for (const path of phpFiles) {
    const source = readFileSync(join(projectRoot, path), 'utf8');
    if (filePattern.test(path) || /move_uploaded_file|file_get_contents|readfile\s*\(/i.test(source)) {
      fileAssets.push(asset('file', path));
    }
    if (aiPattern.test(path) || aiPattern.test(source)) aiAssets.push(asset('ai', path));
  }
  return { fileAssets, aiAssets };
}

export function buildPlatformInventory({ projectRoot = defaultProjectRoot, matrixPath = defaultMatrixPath } = {}) {
  const root = resolve(projectRoot);
  const matrix = readFileSync(resolve(matrixPath), 'utf8');
  const groups = parseCapabilityMatrix(matrix);
  const phpFiles = walkFiles(root, (path) => extname(path) === '.php', 'api');
  const workerPaths = new Set([
    ...phpFiles.filter((path) => /worker\.php$/i.test(path)),
    ...walkFiles(root, (path) => /worker\.php$/i.test(path), 'scripts'),
  ]);
  const cronPaths = walkFiles(root, (path) => /(?:^|\/)cron[^/]*\.php$|wp-cron\.php$/i.test(path));
  const pages = walkFiles(root, (path) => extname(path) === '.html').map((path) => asset('page', path));
  const apis = phpFiles.filter((path) => !workerPaths.has(path)).map((path) => asset('api', path));
  const workers = [...workerPaths].sort().map((path) => asset('worker', path));
  const crons = cronPaths.map((path) => asset('cron', path));
  const migrations = walkFiles(root, (path) => extname(path) === '.sql', 'database/migrations')
    .map((path) => asset('migration', path));
  const pwaPaths = ['manifest.webmanifest', 'sw.js', 'js/mobile-pwa.js'].filter((path) => existsSync(join(root, path)));
  const pwa = pwaPaths.map((path) => asset('pwa', path));
  const miniPages = discoverMiniPages(root);
  const { fileAssets, aiAssets } = discoverContentAssets(root, phpFiles);
  const assets = [...pages, ...apis, ...workers, ...crons, ...migrations, ...pwa, ...miniPages, ...fileAssets, ...aiAssets]
    .sort((left, right) => left.id.localeCompare(right.id));
  const typeCounts = Object.fromEntries(
    [...new Set(assets.map(({ type }) => type))]
      .sort()
      .map((type) => [type, assets.filter((entry) => entry.type === type).length]),
  );
  const coveredGroupIds = new Set(assets.flatMap(({ group_ids: ids }) => ids));

  return {
    schema_version: 1,
    project_root: normalizePath(relative(defaultWorkspaceRoot, root) || '.'),
    generated_from: 'filesystem',
    groups,
    assets,
    summary: {
      group_count: groups.length,
      covered_group_count: groups.filter(({ id }) => coveredGroupIds.has(id)).length,
      asset_count: assets.length,
      frozen_asset_count: assets.filter(({ ownership }) => ownership === 'parallel-change-frozen').length,
      type_counts: typeCounts,
      legacy_endpoint_governance_evidence_count: platform_legacy_endpoint_governance.length,
    },
    governance_evidence: {
      platform_legacy_endpoint_governance,
    },
  };
}

function inventoryAssetExists(projectRoot, entry) {
  const path = join(projectRoot, entry.path);
  if (entry.type !== 'mini-page') return existsSync(path);
  return ['', '.js', '.json', '.wxml', '.wxss'].some((extension) => existsSync(`${path}${extension}`));
}

export function validatePlatformInventory(inventory, { projectRoot = defaultProjectRoot } = {}) {
  const issues = [];
  const groupIds = inventory.groups.map(({ id }) => id);
  const knownGroups = new Set(groupIds);
  const assetIds = inventory.assets.map(({ id }) => id);

  for (const path of inventory.governance_evidence?.platform_legacy_endpoint_governance || []) {
    if (!existsSync(join(projectRoot, path))) {
      issues.push({ code: 'MISSING_LEGACY_ENDPOINT_GOVERNANCE_EVIDENCE', path });
    }
  }

  if (inventory.groups.length !== 89) {
    issues.push({ code: 'GROUP_COUNT', expected: 89, actual: inventory.groups.length });
  }
  if (new Set(groupIds).size !== groupIds.length) {
    issues.push({ code: 'DUPLICATE_GROUP_ID' });
  }
  for (const group of inventory.groups) {
    if (!lifecycleValues.has(group.lifecycle)) {
      issues.push({ code: 'INVALID_LIFECYCLE', id: group.id, lifecycle: group.lifecycle });
    }
  }
  if (new Set(assetIds).size !== assetIds.length) {
    issues.push({ code: 'DUPLICATE_ASSET_ID' });
  }
  for (const entry of inventory.assets) {
    if (!inventoryAssetExists(projectRoot, entry)) {
      issues.push({ code: 'MISSING_ASSET_PATH', id: entry.id, path: entry.path });
    }
    if (entry.group_ids.length === 0) {
      issues.push({ code: 'MISSING_GROUP_REFERENCE', id: entry.id, path: entry.path });
    }
    for (const groupId of entry.group_ids) {
      if (!knownGroups.has(groupId)) {
        issues.push({ code: 'UNKNOWN_GROUP_REFERENCE', id: entry.id, group_id: groupId });
      }
    }
  }
  const actualTypes = new Set(inventory.assets.map(({ type }) => type));
  for (const type of requiredAssetTypes) {
    if (!actualTypes.has(type)) issues.push({ code: 'MISSING_ASSET_TYPE', type });
  }
  for (const prefix of frozenPrefixes) {
    const matching = inventory.assets.filter(({ path }) => path === prefix || path.startsWith(prefix));
    if (matching.length === 0) continue;
    if (matching.some(({ ownership }) => ownership !== 'parallel-change-frozen')) {
      issues.push({ code: 'FROZEN_OWNERSHIP_MISMATCH', prefix });
    }
  }
  return issues;
}

function argumentValue(name) {
  const index = process.argv.indexOf(name);
  return index >= 0 ? process.argv[index + 1] : null;
}

if (process.argv[1] && resolve(process.argv[1]) === currentFile) {
  const inventory = buildPlatformInventory({
    projectRoot: argumentValue('--root') || defaultProjectRoot,
    matrixPath: argumentValue('--matrix') || defaultMatrixPath,
  });
  const json = `${JSON.stringify(inventory, null, 2)}\n`;
  const output = argumentValue('--output');
  if (output) writeFileSync(resolve(output), json);
  else process.stdout.write(json);

  if (process.argv.includes('--check')) {
    const issues = validatePlatformInventory(inventory, {
      projectRoot: argumentValue('--root') || defaultProjectRoot,
    });
    if (issues.length > 0) {
      process.stderr.write(`${JSON.stringify({ issues }, null, 2)}\n`);
      process.exitCode = 1;
    }
  }
}
