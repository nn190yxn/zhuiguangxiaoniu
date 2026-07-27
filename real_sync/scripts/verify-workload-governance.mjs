#!/usr/bin/env node

import { readdirSync } from 'node:fs';
import { extname, join, relative } from 'node:path';
import { spawnSync } from 'node:child_process';

const root = new URL('..', import.meta.url).pathname;
const quick = process.argv.includes('--quick');
const results = [];

function filesUnder(directory, predicate) {
  const found = [];
  for (const entry of readdirSync(directory, { withFileTypes: true })) {
    const path = join(directory, entry.name);
    if (entry.isDirectory()) found.push(...filesUnder(path, predicate));
    if (entry.isFile() && predicate(path)) found.push(path);
  }
  return found.sort();
}

function run(name, command, args) {
  const started = Date.now();
  const child = spawnSync(command, args, { cwd: root, encoding: 'utf8', timeout: 120_000 });
  const passed = child.status === 0;
  results.push({ name, passed, duration_ms: Date.now() - started });
  if (!passed) {
    process.stderr.write(child.stdout || '');
    process.stderr.write(child.stderr || '');
  }
  return passed;
}

const phpRoots = ['api/workload', 'database', 'scripts'].map((path) => join(root, path));
const phpFiles = phpRoots.flatMap((path) => filesUnder(path, (file) => extname(file) === '.php'));
let passed = true;
const phpAvailable = spawnSync('php', ['-v'], { encoding: 'utf8' }).status === 0;
if (phpAvailable) {
  for (const file of phpFiles) {
    passed = run(`php-lint:${relative(root, file)}`, 'php', ['-l', file]) && passed;
  }
} else {
  results.push({ name: 'php-lint', passed: false, error: 'php executable unavailable' });
  passed = false;
}

const allTests = filesUnder(join(root, 'scripts'), (file) => file.endsWith('.test.mjs'));
const testFiles = quick
  ? allTests.filter((file) => /(?:workload_|migration_)/.test(file.split('/').pop() || ''))
  : allTests;
passed = run('node-tests', process.execPath, ['--test', ...testFiles]) && passed;

const summary = {
  passed,
  mode: quick ? 'quick' : 'full',
  php_files: phpFiles.length,
  node_test_files: testFiles.length,
  checks: results,
};
process.stdout.write(`${JSON.stringify(summary, null, 2)}\n`);
process.exitCode = passed ? 0 : 1;
