import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const projectRoot = new URL('../', import.meta.url).pathname;
const read = (path) => readFileSync(new URL(path, `file://${projectRoot}/`), 'utf8');

test('积分排行接口使用服务端权威积分并提供稳定排名语义', () => {
  const source = read('api/points/ranking.php');

  assert.match(source, /getCurrentUserId\(\)/);
  assert.match(source, /REQUEST_METHOD.*GET/s);
  assert.match(source, /up\.accumulated_points DESC, up\.user_id ASC/);
  assert.match(source, /COUNT\(\*\) \+ 1/);
  assert.match(source, /'ranking' => \$ranking, 'me' => \$me/);
  assert.match(source, /error_log\('points\/ranking error:/);
  assert.doesNotMatch(source, /jsonResponse\([^\n]*\$e->getMessage\(\)/);
});

test('积分写接口保留并发保护且客户端重试复用同一幂等键', () => {
  const checkin = read('api/points/checkin.php');
  const exchange = read('api/points/exchange.php');
  const page = read('mini-program/pages/points/index.js');

  assert.match(checkin, /GET_LOCK/);
  assert.match(checkin, /daily_checkin_/);
  assert.match(exchange, /FOR UPDATE/);
  assert.match(exchange, /beginTransaction\(\)/);
  assert.match(page, /idempotencyKey: api\.createIdempotencyKey\('points_exchange'\)/);
  assert.match(page, /idempotencyKey: api\.createIdempotencyKey\('daily_checkin'\)/);
  assert.match(page, /this\.runWrite\(this\.data\.pendingOperation\)/);
});
