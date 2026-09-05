import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { test } from 'node:test';

const root = new URL('..', import.meta.url);
const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const hasPhp = spawnSync('php', ['-v'], { encoding: 'utf8' }).status === 0;

test('平台幂等 migration 保存身份、作用域、指纹、状态和响应快照', () => {
  const sql = read('database/migrations/202609040005_platform_idempotency_records.sql');
  for (const field of [
    'actor_type',
    'actor_id',
    'operation',
    'business_scope',
    'idempotency_key_hash',
    'request_fingerprint',
    'request_id',
    'status',
    'http_status',
    'response_json',
    'expires_at',
  ]) {
    assert.match(sql, new RegExp(`\\b${field}\\b`));
  }
  assert.match(sql, /ENUM\('processing', 'completed', 'failed'\)/);
  assert.match(sql, /UNIQUE KEY uq_platform_idempotency_identity \(actor_type, actor_id, operation, business_scope, idempotency_key_hash\)/);
  assert.match(sql, /KEY idx_platform_idempotency_expiry \(expires_at, status\)/);
});

test('平台幂等执行器覆盖唯一键竞争、处理中、重放、冲突和过期重置', () => {
  const source = read('api/platform/IdempotencyService.php');
  assert.match(source, /final class PlatformIdempotencyService/);
  assert.match(source, /PlatformRequestContext \$context/);
  assert.match(source, /INSERT IGNORE INTO platform_idempotency_records/);
  assert.match(source, /FOR UPDATE/);
  assert.match(source, /idempotency_in_progress/);
  assert.match(source, /idempotency_fingerprint_conflict/);
  assert.match(source, /resetExpired/);
  assert.match(source, /SAVEPOINT platform_idempotency_operation/);
  assert.match(source, /ROLLBACK TO SAVEPOINT platform_idempotency_operation/);
  assert.doesNotMatch(source, /(?:response|payload).*idempotencyKey/);
});

test('规范化请求指纹与对象键顺序无关且保留列表顺序', { skip: !hasPhp }, () => {
  const php = String.raw`
    require 'api/platform/IdempotencyService.php';
    echo json_encode([
      PlatformIdempotencyService::fingerprint(['b' => 2, 'a' => ['d' => 4, 'c' => 3]]),
      PlatformIdempotencyService::fingerprint(['a' => ['c' => 3, 'd' => 4], 'b' => 2]),
      PlatformIdempotencyService::fingerprint(['items' => [1, 2]]),
      PlatformIdempotencyService::fingerprint(['items' => [2, 1]]),
    ]);
  `;
  const result = spawnSync('php', ['-r', php], { cwd: root, encoding: 'utf8', timeout: 10_000 });
  assert.equal(result.status, 0, result.stderr);
  const [first, equivalent, ordered, reversed] = JSON.parse(result.stdout);
  assert.equal(first, equivalent);
  assert.notEqual(ordered, reversed);
  for (const fingerprint of [first, equivalent, ordered, reversed]) {
    assert.match(fingerprint, /^[a-f0-9]{64}$/);
  }
});
