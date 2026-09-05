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

test('积分兑换由统一幂等执行器拥有完整业务事务', () => {
  const exchange = read('api/points/exchange.php');
  const service = read('api/points/PointsExchangeService.php');
  const page = read('mini-program/pages/points/index.js');

  assert.match(exchange, /HTTP_IDEMPOTENCY_KEY/);
  assert.match(exchange, /new PlatformIdempotencyService\(\$db\)/);
  assert.match(exchange, /'points\.exchange'/);
  assert.match(exchange, /'item:' \.[^\n]*\$itemId/);
  assert.match(exchange, /new PointsExchangeService\(\$db\)/);
  assert.match(service, /FOR UPDATE[\s\S]*UPDATE user_points[\s\S]*INSERT INTO points_records[\s\S]*UPDATE points_exchange_items[\s\S]*INSERT INTO points_exchange_records/);
  assert.match(service, /inTransaction\(\)/);
  assert.doesNotMatch(exchange, /\$db->beginTransaction\(\)/);
  assert.doesNotMatch(exchange, /\$db->commit\(\)/);
  assert.match(exchange, /PlatformApiResponse::success/);
  assert.match(exchange, /throw new PlatformApiException/);

  assert.match(page, /idempotencyKey: api\.createIdempotencyKey\('points_exchange'\)/);
  assert.match(page, /this\.runWrite\(this\.data\.pendingOperation\)/);
});

test('每日签到由命名锁和用户业务日期唯一键共同保护', () => {
  const checkin = read('api/points/checkin.php');
  const service = read('api/points/DailyCheckinService.php');
  const migration = read('database/migrations/202609040006_points_daily_checkins.sql');
  const page = read('mini-program/pages/points/index.js');

  assert.match(checkin, /new DailyCheckinService\(\$db\)/);
  assert.match(checkin, /HTTP_IDEMPOTENCY_KEY/);
  assert.match(checkin, /new PlatformIdempotencyService\(\$db\)/);
  assert.match(checkin, /'points\.daily_checkin'/);
  assert.match(checkin, /'date:' \. \$businessDate/);
  assert.match(service, /GET_LOCK/);
  assert.match(service, /finally/);
  assert.match(service, /RELEASE_LOCK/);
  assert.match(service, /daily_checkin_/);
  assert.match(service, /business_date/);
  assert.match(service, /daily_checkin_already_completed/);
  assert.match(service, /inTransaction\(\)/);
  assert.match(checkin, /PlatformApiResponse::success/);
  assert.match(checkin, /throw new PlatformApiException/);
  assert.doesNotMatch(checkin, /\$db->beginTransaction\(\)|\$db->commit\(\)|\$db->rollBack\(\)/);
  assert.match(migration, /ADD COLUMN business_date DATE NULL/);
  assert.match(migration, /rules\.code = 'daily_checkin'/);
  assert.match(migration, /UNIQUE KEY uq_points_records_user_business_date \(user_id, business_date\)/);
  assert.match(page, /idempotencyKey: api\.createIdempotencyKey\('daily_checkin'\)/);
});

test('积分页面保留生产积分流水并接入统一读取状态', () => {
  const page = read('mini-program/pages/points/index.js');
  const view = read('mini-program/pages/points/index.wxml');
  assert.match(page, /\/points\/records\.php\?page=1&page_size=50/);
  assert.match(page, /recordsState: viewState\.readState\('loading'\)/);
  assert.match(page, /viewState\.fromError\(error, '积分记录加载失败', 'loadRecords'\)/);
  assert.match(page, /this\.data\.activeTab === 'records' \? this\.loadRecords\(\) : this\.loadAll\(\)/);
  assert.match(view, /bindtap="selectRecords"/);
  assert.match(view, /item\.points_display/);
});
