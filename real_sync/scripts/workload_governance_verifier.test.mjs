import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const verifier = readFileSync(new URL('./verify-workload-governance.mjs', import.meta.url), 'utf8');

test('[validates 10.6, 10.7, 12.2] governance verifier combines PHP syntax and Node suites', () => {
  assert.match(verifier, /php-lint:/);
  assert.match(verifier, /'php', \['-l', file\]/);
  assert.match(verifier, /php executable unavailable/);
  assert.match(verifier, /\.endsWith\('\.test\.mjs'\)/);
  assert.match(verifier, /process\.execPath, \['--test', \.\.\.testFiles\]/);
  assert.match(verifier, /timeout: 120_000/);
  assert.match(verifier, /JSON\.stringify\(summary/);
});

test('[validates 12.2] full mode includes every script test and quick mode retains workload and migrations', () => {
  assert.match(verifier, /quick\s*\? allTests\.filter/);
  assert.match(verifier, /\(\?:workload_\|migration_\)/);
  assert.match(verifier, /mode: quick \? 'quick' : 'full'/);
});
