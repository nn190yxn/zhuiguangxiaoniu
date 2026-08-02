#!/usr/bin/env node

import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { dirname, extname, join, relative, resolve } from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const currentFile = fileURLToPath(import.meta.url);
const defaultProjectRoot = resolve(dirname(currentFile), '..');
const configFileName = 'platform_regression_preflight.config.json';
const externalEnvironmentPattern = /DB_PASSWORD|database credentials|SQLSTATE|Connection refused|php_network_getaddresses|Access denied for user|could not find driver/i;

export function loadRegressionPreflightConfig({ projectRoot = defaultProjectRoot } = {}) {
  const configPath = join(resolve(projectRoot), 'scripts', configFileName);
  const config = JSON.parse(readFileSync(configPath, 'utf8'));
  if (config.schema_version !== 1 || !Array.isArray(config.stages) || config.stages.length === 0) {
    throw new TypeError('invalid regression preflight config');
  }
  const ids = config.stages.map(({ id }) => id);
  if (ids.some((id) => typeof id !== 'string') || new Set(ids).size !== ids.length) {
    throw new TypeError('regression preflight stage ids must be unique strings');
  }
  return config;
}

export function classifyStageResult(stage, exitCode, stdout = '', stderr = '') {
  if (stage.classification === 'approval') return 'approval_required';
  if (exitCode === 0) return 'passed';
  if (stage.classification === 'external_environment' && externalEnvironmentPattern.test(`${stdout}\n${stderr}`)) {
    return 'blocked_external';
  }
  return 'failed';
}

export function evaluateRegressionPreflight(stages) {
  const blockingStageIds = stages.filter(({ status }) => status === 'failed').map(({ id }) => id);
  const blockedExternalStageIds = stages.filter(({ status }) => status === 'blocked_external').map(({ id }) => id);
  const approvalRequiredStageIds = stages.filter(({ status }) => status === 'approval_required').map(({ id }) => id);
  return {
    schema_version: 1,
    status: blockingStageIds.length > 0
      ? 'failed'
      : blockedExternalStageIds.length > 0 || approvalRequiredStageIds.length > 0
        ? 'passed_with_gates'
        : 'passed',
    exit_code: blockingStageIds.length > 0 ? 1 : 0,
    blocking_stage_ids: blockingStageIds,
    blocked_external_stage_ids: blockedExternalStageIds,
    approval_required_stage_ids: approvalRequiredStageIds,
    wave_evidence: summarizeWaveEvidence(stages),
    stages,
  };
}

function summarizeWaveEvidence(stages) {
  const waves = [...new Set(stages.flatMap(({ waves = [] }) => waves))].sort((left, right) => left - right);
  return waves.map((wave) => {
    const waveStages = stages.filter(({ waves = [] }) => waves.includes(wave));
    return {
      wave,
      status: waveStages.some(({ status }) => status === 'failed')
        ? 'failed'
        : waveStages.some(({ status }) => status === 'blocked_external' || status === 'approval_required')
          ? 'passed_with_gates'
          : 'passed',
      stage_ids: waveStages.map(({ id }) => id),
    };
  });
}

function walkFiles(root, predicate) {
  const files = [];
  for (const entry of readdirSync(root, { withFileTypes: true })) {
    const path = join(root, entry.name);
    if (entry.isDirectory()) files.push(...walkFiles(path, predicate));
    else if (entry.isFile() && predicate(path)) files.push(path);
  }
  return files.sort();
}

function sanitizedEnvironment() {
  const environment = { ...process.env };
  for (const name of ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DATABASE_URL']) delete environment[name];
  return environment;
}

function runCommand(stage, projectRoot) {
  if (stage.classification === 'approval') return { exitCode: null, stdout: '', stderr: '' };
  if (stage.runner === 'node_test_all') {
    const tests = readdirSync(join(projectRoot, 'scripts'))
      .filter((name) => name.endsWith('.test.mjs'))
      .sort()
      .map((name) => join('scripts', name));
    const result = spawnSync(process.execPath, ['--test', ...tests], {
      cwd: projectRoot,
      encoding: 'utf8',
      maxBuffer: 128 * 1024 * 1024,
    });
    return { exitCode: result.status ?? 1, stdout: result.stdout || '', stderr: result.stderr || '', fileCount: tests.length };
  }
  if (stage.runner === 'php_lint_all') {
    const files = walkFiles(projectRoot, (path) => extname(path) === '.php');
    const failures = [];
    for (const file of files) {
      const result = spawnSync('php', ['-l', file], { cwd: projectRoot, encoding: 'utf8' });
      if (result.status !== 0) failures.push(`${relative(projectRoot, file)}: ${(result.stderr || result.stdout || '').trim()}`);
    }
    return { exitCode: failures.length === 0 ? 0 : 1, stdout: `php_file_count=${files.length}\n`, stderr: failures.join('\n'), fileCount: files.length };
  }
  if (stage.runner === 'documentation_links') return checkDocumentationLinks(projectRoot);

  const [executable, ...args] = stage.command;
  const result = spawnSync(executable === 'node' ? process.execPath : executable, args, {
    cwd: stage.workspace_command ? resolve(projectRoot, '..') : projectRoot,
    encoding: 'utf8',
    maxBuffer: 64 * 1024 * 1024,
    env: stage.sanitized_database_environment ? sanitizedEnvironment() : process.env,
  });
  return { exitCode: result.status ?? 1, stdout: result.stdout || '', stderr: result.stderr || '' };
}

function checkDocumentationLinks(projectRoot) {
  const workspaceRoot = resolve(projectRoot, '..');
  const roots = [join(workspaceRoot, '.monkeycode', 'docs'), join(workspaceRoot, '.monkeycode', 'specs')].filter(existsSync);
  const markdownFiles = roots.flatMap((root) => walkFiles(root, (path) => extname(path) === '.md'));
  const missing = [];
  const linkPattern = /\[[^\]]*\]\(([^)]+)\)/g;
  for (const file of markdownFiles) {
    const source = readFileSync(file, 'utf8');
    for (const match of source.matchAll(linkPattern)) {
      const target = match[1].trim().replace(/^<|>$/g, '').split('#')[0];
      if (!target || /^(?:https?:|mailto:|\/)/.test(target)) continue;
      const decoded = decodeURIComponent(target);
      if (!existsSync(resolve(dirname(file), decoded))) missing.push(`${relative(workspaceRoot, file)} -> ${target}`);
    }
  }
  return {
    exitCode: missing.length === 0 ? 0 : 1,
    stdout: `markdown_file_count=${markdownFiles.length}\nchecked_local_links=${countLocalLinks(markdownFiles)}\n`,
    stderr: missing.join('\n'),
  };
}

function countLocalLinks(files) {
  let count = 0;
  for (const file of files) {
    for (const match of readFileSync(file, 'utf8').matchAll(/\[[^\]]*\]\(([^)]+)\)/g)) {
      const target = match[1].trim();
      if (target && !/^(?:https?:|mailto:|#|\/)/.test(target)) count += 1;
    }
  }
  return count;
}

function evidenceSummary(stage, result) {
  const output = `${result.stdout}\n${result.stderr}`;
  const tapMetric = (name) => output.match(new RegExp(`^# ${name} (\\d+)$`, 'm'))?.[1];
  const metrics = ['tests', 'pass', 'fail', 'skipped']
    .map((name) => tapMetric(name) ? `${name}=${tapMetric(name)}` : null)
    .filter(Boolean);
  if (result.fileCount !== undefined) metrics.unshift(`files=${result.fileCount}`);
  if (metrics.length > 0) return metrics.join(', ');
  try {
    const parsed = JSON.parse(result.stdout);
    const values = stage.id === 'inventory_89'
      ? [
          `coverage_group_count=${parsed.coverage_group_count}`,
          `test_files=${parsed.coverage_test_file_count}`,
          `approval_required=${parsed.coverage_release_verification_counts?.approval_required}`,
          `blocked_external=${parsed.coverage_release_verification_counts?.blocked_external}`,
        ]
      : stage.id === 'platform_preflight'
        ? [
            `group_count=${parsed.metrics?.group_count}`,
            `coverage_group_count=${parsed.metrics?.coverage_group_count}`,
            `endpoint_count=${parsed.metrics?.endpoint_count}`,
            `mini_program_routes=${parsed.metrics?.mini_program_route_count}`,
          ]
        : stage.id === 'migration_compatibility'
          ? [`compatible=${parsed.compatible}`, `checked_versions=${parsed.checked_versions?.length}`, `issues=${parsed.issues?.length}`]
          : [];
    const present = values.filter((value) => !value.endsWith('=undefined'));
    if (present.length > 0) return present.join(', ');
  } catch {
    // Non-JSON command output falls through to a compact excerpt.
  }
  const compact = output.trim().split('\n').filter(Boolean).slice(-3).join(' | ');
  return compact.slice(0, 600) || (stage.classification === 'approval' ? 'requires controlled production approval' : 'completed without output');
}

export function runRegressionPreflight({ projectRoot = defaultProjectRoot } = {}) {
  const root = resolve(projectRoot);
  const config = loadRegressionPreflightConfig({ projectRoot: root });
  const stages = [];
  for (const stage of config.stages) {
    const started = process.hrtime.bigint();
    const result = runCommand(stage, root);
    const durationMs = Number(process.hrtime.bigint() - started) / 1_000_000;
    stages.push({
      id: stage.id,
      name: stage.name,
      waves: stage.waves,
      classification: stage.classification,
      command: stage.command,
      duration_ms: Math.round(durationMs),
      status: classifyStageResult(stage, result.exitCode, result.stdout, result.stderr),
      exit_code: result.exitCode,
      evidence_summary: evidenceSummary(stage, result),
    });
  }
  return evaluateRegressionPreflight(stages);
}

if (process.argv[1] && resolve(process.argv[1]) === currentFile) {
  try {
    const report = runRegressionPreflight();
    process.stdout.write(`${JSON.stringify(report, null, 2)}\n`);
    process.exitCode = report.exit_code;
  } catch (error) {
    process.stderr.write(`${JSON.stringify({ schema_version: 1, status: 'failed', error: error.message })}\n`);
    process.exitCode = 1;
  }
}
