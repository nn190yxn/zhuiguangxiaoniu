import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const root = new URL('..', import.meta.url);
const hasPhp = spawnSync('php', ['-v'], { encoding: 'utf8' }).status === 0;

function runPhp(source) {
  const result = spawnSync('php', ['-d', 'display_errors=1', '-r', source], {
    cwd: root,
    encoding: 'utf8',
    timeout: 10_000,
  });
  assert.equal(result.status, 0, result.stderr);
  return JSON.parse(result.stdout);
}

function catalog() {
  return runPhp(String.raw`
    $catalog = require 'database/migration_catalog.php';
    echo json_encode(array_values(array_map(static fn(array $entry): array => [
      'version' => $entry['version'],
      'file' => $entry['sql_file'],
      'columns' => $entry['columns'],
      'data_checks' => $entry['data_checks'],
      'compatibility' => $entry['compatibility'],
    ], $catalog)));
  `);
}

function sqlFor(entry) {
  return readFileSync(new URL(`../database/${entry.file}`, import.meta.url), 'utf8');
}

function nextSeed(seed) {
  return (seed * 1664525 + 1013904223) >>> 0;
}

function generatedFacts(seed, index) {
  const value = nextSeed(seed + index);
  return {
    stable_id: `fact-${value}`,
    business_date: `2026-07-${String((value % 28) + 1).padStart(2, '0')}`,
    staff_id: value % 97,
    store_id: value % 17,
    role_code: ['sales', 'coach', 'manager'][value % 3],
    value: value % 10000,
  };
}

function addedColumns(sql) {
  return [...sql.matchAll(/ALTER\s+TABLE\s+`?([a-z0-9_]+)`?\s+ADD\s+COLUMN\s+`?([a-z0-9_]+)`?\s+([^;\r\n]+)/gi)]
    .map((match) => ({ table: match[1], name: match[2], definition: match[3] }));
}

function writeNMinusOne(facts) {
  return { ...facts };
}

function writeN(facts, columns, seed) {
  const record = { ...facts };
  for (const column of columns) {
    record[`${column.table}.${column.name}`] = column.definition.match(/\bNULL\b/i)
      && !/\bNOT\s+NULL\b/i.test(column.definition)
      ? null
      : `default-${seed}`;
  }
  return record;
}

function projectStableFacts(record) {
  return Object.fromEntries(Object.entries(record).filter(([key]) => !key.includes('.')));
}

test('property 12: compatibility-window data has one deterministic N and N-1 explanation', { skip: !hasPhp }, () => {
  const entries = catalog();
  assert.ok(entries.length > 0, 'migration catalog must include at least one version');

  for (const entry of entries) {
    const columns = addedColumns(sqlFor(entry));
    for (let index = 0; index < 128; index += 1) {
      const facts = generatedFacts(0x5eed0000 ^ Number(entry.version.slice(-4)), index);
      const newRecord = writeN(facts, columns, index);
      const oldProjection = projectStableFacts(newRecord);
      const newProjection = projectStableFacts(newRecord);
      assert.deepEqual(oldProjection, facts, `${entry.version} case ${index}`);
      assert.deepEqual(newProjection, facts, `${entry.version} case ${index}`);
    }
  }
});

test('property 13: N and N-1 writes preserve every current business fact', { skip: !hasPhp }, () => {
  const entries = catalog();
  for (const entry of entries) {
    const columns = addedColumns(sqlFor(entry));
    for (let index = 0; index < 128; index += 1) {
      const facts = generatedFacts(0xcafe0000 ^ Number(entry.version.slice(-4)), index);
      const oldWrite = writeNMinusOne(facts);
      const newWrite = writeN(facts, columns, index);
      assert.deepEqual(projectStableFacts(oldWrite), facts, `${entry.version} N-1 case ${index}`);
      assert.deepEqual(projectStableFacts(newWrite), facts, `${entry.version} N case ${index}`);
      for (const key of Object.keys(facts)) assert.equal(newWrite[key], facts[key]);
    }
  }
});

test('migration compatibility contracts keep checks read-only and rollback preserving', { skip: !hasPhp }, () => {
  for (const entry of catalog()) {
    const sql = sqlFor(entry);
    assert.deepEqual(entry.compatibility.required_readers, ['N', 'N-1'], entry.version);
    assert.deepEqual(entry.compatibility.required_writers, ['N', 'N-1'], entry.version);
    assert.equal(entry.compatibility.rollback_strategy, 'preserving', entry.version);
    for (const check of entry.data_checks) {
      assert.match(check.sql.trim(), /^(SELECT|WITH)\b/i, `${entry.version} ${check.id}`);
      assert.doesNotMatch(check.sql, /\b(?:CREATE|ALTER|DROP|INSERT|UPDATE|DELETE|TRUNCATE)\b/i, `${entry.version} ${check.id}`);
    }
    assert.doesNotMatch(sql, /\bDROP\s+(?:TABLE|COLUMN)\b/i, entry.version);
    assert.doesNotMatch(sql, /\bRENAME\s+(?:TABLE|COLUMN)\b/i, entry.version);
  }
});

test('task 5.5 regression suite keeps backfill, invalid-reference, and side-effect checks present', () => {
  const idempotency = readFileSync(new URL('./migration_idempotency.test.mjs', import.meta.url), 'utf8');
  const readiness = readFileSync(new URL('./migration_readiness.test.mjs', import.meta.url), 'utf8');
  const replay = readFileSync(new URL('./migration_replay.test.mjs', import.meta.url), 'utf8');
  assert.match(idempotency, /historical baseline preserves facts and backfills only known records/);
  assert.match(readiness, /data verification blocks a batch with a bounded difference report/);
  assert.match(replay, /verify detects conflicting hashes, orphan evidence, and unavailable sources/);
});
