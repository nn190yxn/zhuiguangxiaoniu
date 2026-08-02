#!/usr/bin/env node

import { existsSync, readFileSync } from 'node:fs';
import { join, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

import { checkMiniProgramContracts } from './check_miniprogram_contracts.mjs';
import { checkMiniProgramRoutes } from './check_miniprogram_routes.mjs';
import { buildPlatformContractSnapshot, compareContractSnapshots } from './platform_contract_snapshot.mjs';
import { buildPlatformInventory, validatePlatformInventory } from './platform_inventory.mjs';
import {
  functionCoverage,
  summarizeFunctionCoverage,
  validateFunctionCoverage,
} from './platform_function_coverage.mjs';

const currentFile = fileURLToPath(import.meta.url);
const defaultProjectRoot = resolve(dirname(currentFile), '..');

function checkPwa(projectRoot) {
  const issues = [];
  const manifestPath = join(projectRoot, 'manifest.webmanifest');
  const workerPath = join(projectRoot, 'sw.js');
  if (!existsSync(manifestPath)) return [{ code: 'MISSING_MANIFEST', path: 'manifest.webmanifest' }];
  if (!existsSync(workerPath)) return [{ code: 'MISSING_SERVICE_WORKER', path: 'sw.js' }];

  let manifest;
  try {
    manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
  } catch (error) {
    return [{ code: 'INVALID_MANIFEST', message: error.message }];
  }
  if (manifest.scope !== '/mobile/') issues.push({ code: 'INVALID_PWA_SCOPE', actual: manifest.scope });
  if (typeof manifest.start_url !== 'string' || !manifest.start_url.startsWith('/mobile/')) {
    issues.push({ code: 'INVALID_PWA_START_URL', actual: manifest.start_url });
  }
  const worker = readFileSync(workerPath, 'utf8');
  if (!/pathname\.startsWith\(['"]\/api\/['"]\)/.test(worker)) {
    issues.push({ code: 'PWA_API_CACHE_BOUNDARY_MISSING' });
  }
  return issues;
}

function checkLegacyEndpointGovernance(projectRoot) {
  const required = [
    'api/platform/LegacyEndpointGovernance.php',
    'api/platform/legacy_endpoint_catalog.php',
    'database/migrations/202608020003_platform_legacy_endpoint_governance.sql',
    'scripts/platform_legacy_endpoint_governance.test.mjs',
  ];
  return required
    .filter((path) => !existsSync(join(projectRoot, path)))
    .map((path) => ({ code: 'MISSING_LEGACY_ENDPOINT_GOVERNANCE_EVIDENCE', path }));
}

function frozenChanges(projectRoot, inventory) {
  const workspaceRoot = resolve(projectRoot, '..');
  const status = spawnSync('git', ['status', '--porcelain', '--untracked-files=all'], {
    cwd: workspaceRoot,
    encoding: 'utf8',
  });
  if (status.status !== 0) return [{ code: 'GIT_STATUS_FAILED', message: status.stderr.trim() }];
  const changed = status.stdout.split('\n').filter(Boolean).map((line) => line.slice(3));
  const frozen = new Set(
    inventory.assets
      .filter(({ ownership }) => ownership === 'parallel-change-frozen')
      .map(({ path }) => `real_sync/${path}`),
  );
  return changed.filter((path) => frozen.has(path)).sort().map((path) => ({ code: 'PARALLEL_CHANGE_FROZEN', path }));
}

export function evaluatePreflight(checks, { strictFrozen = false } = {}) {
  const blockingIssues = [];
  const warnings = [];
  for (const check of checks) {
    for (const issue of check.issues) {
      if (issue.code === 'PARALLEL_CHANGE_FROZEN' && !strictFrozen) warnings.push(issue);
      else blockingIssues.push({ check: check.name, ...issue });
    }
  }
  return {
    status: blockingIssues.length === 0 ? 'passed' : 'failed',
    blocking_issues: blockingIssues,
    warnings,
  };
}

export function runPlatformPreflight({
  projectRoot = defaultProjectRoot,
  contractBaseline = null,
  strictFrozen = false,
} = {}) {
  const root = resolve(projectRoot);
  const inventory = buildPlatformInventory({ projectRoot: root });
  const contracts = buildPlatformContractSnapshot({ projectRoot: root });
  const miniRoutes = checkMiniProgramRoutes(join(root, 'mini-program'));
  const miniContracts = checkMiniProgramContracts(root);
  const contractIssues = contractBaseline
    ? compareContractSnapshots(JSON.parse(readFileSync(resolve(contractBaseline), 'utf8')), contracts)
      .map((change) => ({ code: 'CONTRACT_DRIFT', ...change }))
    : [];
  const checks = [
    { name: 'inventory', issues: validatePlatformInventory(inventory, { projectRoot: root }) },
    { name: 'function_coverage', issues: validateFunctionCoverage(functionCoverage, inventory, { projectRoot: root }) },
    { name: 'contracts', issues: contractIssues },
    { name: 'mini_program_routes', issues: miniRoutes.errors.map((issue) => ({ code: 'MINI_PROGRAM_ROUTE', ...issue })) },
    { name: 'mini_program_contracts', issues: miniContracts.issues },
    { name: 'pwa', issues: checkPwa(root) },
    { name: 'legacy_endpoint_governance', issues: checkLegacyEndpointGovernance(root) },
    { name: 'frozen_paths', issues: frozenChanges(root, inventory) },
  ];
  const result = evaluatePreflight(checks, { strictFrozen });
  const coverageSummary = summarizeFunctionCoverage(functionCoverage);

  return {
    schema_version: 1,
    ...result,
    checks: checks.map(({ name, issues }) => ({ name, issue_count: issues.length })),
    metrics: {
      ...inventory.summary,
      ...coverageSummary,
      ...contracts.summary,
      mini_program_route_count: miniRoutes.registeredRoutes.length,
      mini_program_reference_count: miniRoutes.checkedReferences,
      mini_program_contract_category_count: miniContracts.categories.length,
    },
  };
}

function argumentValue(name) {
  const index = process.argv.indexOf(name);
  return index >= 0 ? process.argv[index + 1] : null;
}

if (process.argv[1] && resolve(process.argv[1]) === currentFile) {
  const report = runPlatformPreflight({
    projectRoot: argumentValue('--root') || defaultProjectRoot,
    contractBaseline: argumentValue('--contract-baseline'),
    strictFrozen: process.argv.includes('--strict-frozen'),
  });
  process.stdout.write(`${JSON.stringify(report, null, 2)}\n`);
  if (report.status === 'failed') process.exitCode = 1;
}
