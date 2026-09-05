import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const projectRoot = new URL('../', import.meta.url).pathname;
const harnessPath = new URL('./critical_side_effects_mysql.integration.php', import.meta.url).pathname;
const source = readFileSync(harnessPath, 'utf8');
const hasTestDatabase = ['TEST_DB_HOST', 'TEST_DB_NAME', 'TEST_DB_USER', 'TEST_DB_PASSWORD']
  .every((key) => Boolean(process.env[key]));

test('MySQL harness 强制专用测试库并覆盖五类副作用', () => {
  assert.match(source, /TEST_DB_NAME must identify a dedicated test database/);
  assert.match(source, /exerciseExchange/);
  assert.match(source, /exerciseCheckin/);
  assert.match(source, /exerciseExam/);
  assert.match(source, /exerciseLessonCreate/);
  assert.match(source, /exerciseLessonExport/);
  assert.match(source, /runConcurrent/);
  assert.match(source, /verifyReplay/);
});

test('MySQL harness 拒绝生产命名数据库', () => {
  const result = spawnSync('php', [harnessPath], {
    cwd: projectRoot,
    encoding: 'utf8',
    env: {
      ...process.env,
      TEST_DB_HOST: '127.0.0.1',
      TEST_DB_NAME: 'production',
      TEST_DB_USER: 'placeholder',
      TEST_DB_PASSWORD: 'placeholder',
    },
  });
  assert.notEqual(result.status, 0);
  assert.match(result.stderr, /dedicated test database/);
});

test('五类副作用通过真实 MySQL 并发与重试验证', { skip: !hasTestDatabase }, () => {
  const result = spawnSync('php', [harnessPath], {
    cwd: projectRoot,
    encoding: 'utf8',
    env: process.env,
    timeout: 120_000,
  });
  assert.equal(result.status, 0, result.stderr);
  const output = JSON.parse(result.stdout);
  assert.equal(output.ok, true);
  assert.equal(output.database_classification, 'dedicated_test');
  assert.equal(output.total_checks, 5);
  assert.equal(output.total_requests, 15);
});
