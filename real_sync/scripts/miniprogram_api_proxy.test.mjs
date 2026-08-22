import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import test from 'node:test';

const require = createRequire(import.meta.url);
const projectRoot = new URL('../', import.meta.url).pathname;
const proxy = require('../cloudfunctions/api-proxy/index.js');

test('api-proxy 登记固定路由并拒绝未知路由', async () => {
  const handler = proxy.createHandler();
  const routes = proxy.buildRouteRegistry();

  assert.ok(routes.some(({ id }) => id === 'GET /todos/my.php'));
  assert.ok(routes.some(({ id }) => id === 'POST /workload/save-report.php'));
  assert.ok(routes.some(({ id }) => id === 'POST /exam/index.php?action=assign'));
  assert.ok(routes.some(({ id }) => id === 'GET /points/exchange.php'));

  const result = await handler({
    protocol_version: 1,
    type: 'request',
    route: '/unknown.php',
    method: 'GET',
    request_id: 'req-unknown'
  });

  assert.equal(result.upstream_status, 404);
  assert.equal(result.body.code, 'route_not_allowed');
  assert.equal(result.request_id, 'req-unknown');
});

test('api-proxy 按方法和 action 精确匹配登记路由', async () => {
  const handler = proxy.createHandler({
    transport() {
      return Promise.resolve({ statusCode: 200, body: { code: 0, data: { ok: true } } });
    }
  });

  const allowed = await handler({
    protocol_version: 1,
    type: 'request',
    route: '/exam/index.php?action=assign',
    method: 'POST',
    header: { 'Idempotency-Key': 'exam-assign-1' }
  });
  const wrongAction = await handler({
    protocol_version: 1,
    type: 'request',
    route: '/exam/index.php?action=detail',
    method: 'POST',
    header: { 'Idempotency-Key': 'exam-detail-1' }
  });
  const unknownAction = await handler({
    protocol_version: 1,
    type: 'request',
    route: '/policy/notify.php?action=archive',
    method: 'POST',
    header: { 'Idempotency-Key': 'notify-archive-1' }
  });

  assert.equal(allowed.upstream_status, 200);
  assert.equal(allowed.route_id, 'POST /exam/index.php?action=assign');
  assert.equal(wrongAction.upstream_status, 404);
  assert.equal(unknownAction.upstream_status, 404);
});

test('api-proxy 转发允许路由、方法、请求体和核心请求头', async () => {
  let capturedOptions = null;
  let capturedBody = null;
  let capturedRoute = null;
  const handler = proxy.createHandler({
    upstreamOrigin: 'https://upstream.example/api',
    transport(options, body, route) {
      capturedOptions = options;
      capturedBody = body;
      capturedRoute = route;
      return Promise.resolve({ statusCode: 200, body: { code: 0, data: { saved: true } } });
    }
  });

  const result = await handler({
    protocol_version: 1,
    type: 'request',
    route: '/workload/save-report.php',
    method: 'POST',
    request_id: 'req-save',
    data: { value: 9 },
    header: {
      Authorization: 'Bearer token',
      'Idempotency-Key': 'idem-save',
      'X-State-Version': '7'
    }
  });

  assert.equal(result.upstream_status, 200);
  assert.equal(result.body.data.saved, true);
  assert.equal(capturedOptions.hostname, 'upstream.example');
  assert.equal(capturedOptions.path, '/api/workload/save-report.php');
  assert.equal(capturedOptions.method, 'POST');
  assert.equal(capturedOptions.headers.authorization, 'Bearer token');
  assert.equal(capturedOptions.headers['idempotency-key'], 'idem-save');
  assert.equal(capturedOptions.headers['x-state-version'], '7');
  assert.equal(capturedBody, JSON.stringify({ value: 9 }));
  assert.equal(capturedRoute.domain, 'workload_report');
});

test('api-proxy 阻断错误方法、外部 route 和过大响应', async () => {
  const handler = proxy.createHandler({
    transport() {
      return Promise.resolve({ statusCode: 200, body: { code: 0, data: 'x'.repeat(1024 * 1024) } });
    }
  });

  const wrongMethod = await handler({ protocol_version: 1, type: 'request', route: '/todos/my.php', method: 'POST' });
  assert.equal(wrongMethod.upstream_status, 404);

  const external = await handler({ protocol_version: 1, type: 'request', route: 'https://evil.example/ping', method: 'GET' });
  assert.equal(external.upstream_status, 400);
  assert.equal(external.body.code, 'invalid_route');

  const tooLarge = await handler({ protocol_version: 1, type: 'request', route: '/todos/my.php', method: 'GET' });
  assert.equal(tooLarge.upstream_status, 502);
  assert.equal(tooLarge.body.code, 'response_too_large');
});

test('api-proxy 写入脱敏结构化日志', async () => {
  const logs = [];
  const handler = proxy.createHandler({
    logger(entry) {
      logs.push(entry);
    },
    transport() {
      return Promise.resolve({ statusCode: 200, body: { code: 0, data: { ok: true } } });
    }
  });

  await handler({
    protocol_version: 1,
    type: 'request',
    route: '/workload/save-report.php',
    method: 'POST',
    request_id: 'req-log',
    data: { staff_id: 42, phone: '13800000000' },
    header: { Authorization: 'Bearer secret-token' }
  });

  assert.equal(logs.length, 1);
  assert.equal(logs[0].route_id, 'POST /workload/save-report.php');
  assert.equal(logs[0].domain, 'workload_report');
  assert.equal(logs[0].status, 200);
  assert.equal(logs[0].request_id, 'req-log');
  assert.match(logs[0].staff_digest, /^[a-f0-9]{16}$/);
  assert.equal(JSON.stringify(logs).includes('13800000000'), false);
  assert.equal(JSON.stringify(logs).includes('secret-token'), false);
});

test('api-proxy 对相同幂等键和相同请求摘要复用首次结果', async () => {
  let transportCalls = 0;
  const store = proxy.createMemoryIdempotencyStore();
  const handler = proxy.createHandler({
    idempotencyStore: store,
    transport() {
      transportCalls += 1;
      return Promise.resolve({ statusCode: 200, body: { code: 0, data: { result_id: transportCalls } } });
    }
  });
  const event = {
    protocol_version: 1,
    type: 'request',
    route: '/workload/save-report.php',
    method: 'POST',
    request_id: 'req-idem-1',
    data: { staff_id: 7, value: 1 },
    header: { 'Idempotency-Key': 'idem-same' }
  };

  const first = await handler(event);
  const second = await handler(Object.assign({}, event, { request_id: 'req-idem-2' }));

  assert.equal(transportCalls, 1);
  assert.deepEqual(second.body, first.body);
  assert.equal(second.body.data.result_id, 1);
});

test('api-proxy 对相同幂等键和不同请求摘要返回稳定冲突', async () => {
  const store = proxy.createMemoryIdempotencyStore();
  const handler = proxy.createHandler({
    idempotencyStore: store,
    transport() {
      return Promise.resolve({ statusCode: 200, body: { code: 0, data: { saved: true } } });
    }
  });

  const first = await handler({
    protocol_version: 1,
    type: 'request',
    route: '/workload/save-report.php',
    method: 'POST',
    request_id: 'req-conflict-1',
    data: { staff_id: 7, value: 1 },
    header: { 'Idempotency-Key': 'idem-conflict' }
  });
  const second = await handler({
    protocol_version: 1,
    type: 'request',
    route: '/workload/save-report.php',
    method: 'POST',
    request_id: 'req-conflict-2',
    data: { staff_id: 7, value: 2 },
    header: { 'Idempotency-Key': 'idem-conflict' }
  });

  assert.equal(first.upstream_status, 200);
  assert.equal(second.upstream_status, 409);
  assert.equal(second.body.data.conflict_type, 'idempotency_key_reuse');
  assert.equal(second.body.data.recovery_action, 'reload');
});

test('api-proxy 权威数据源边界保持在现有 PHP 上游', async () => {
  const source = readFileSync(join(projectRoot, 'cloudfunctions/api-proxy/index.js'), 'utf8');
  const forbiddenFragments = ['wx-server-sdk', '.database(', 'collection(', 'add({', 'doc('];

  for (const fragment of forbiddenFragments) {
    assert.equal(source.includes(fragment), false, `api-proxy 不应直接写云数据库: ${fragment}`);
  }

  let capturedOptions = null;
  const handler = proxy.createHandler({
    upstreamOrigin: 'https://supercalf.com/api',
    transport(options) {
      capturedOptions = options;
      return Promise.resolve({ statusCode: 200, body: { code: 0, data: { ok: true } } });
    }
  });

  await handler({
    protocol_version: 1,
    type: 'request',
    route: '/points/checkin.php',
    method: 'POST',
    request_id: 'req-authority',
    data: { staff_id: 7 },
    header: { 'Idempotency-Key': 'idem-authority' }
  });

  assert.equal(capturedOptions.hostname, 'supercalf.com');
  assert.equal(capturedOptions.path, '/api/points/checkin.php');
});
