import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import test from 'node:test';

const require = createRequire(import.meta.url);
const projectRoot = new URL('../', import.meta.url).pathname;
const proxy = require('../cloudfunctions/auth-proxy/index.js');

test('auth-proxy 仅转发登记认证路由', async () => {
  const handler = proxy.createHandler({
    transport(options) {
      return Promise.resolve({ statusCode: 200, body: { code: 0, data: { path: options.path } } });
    }
  });

  const login = await handler({
    protocol_version: 1,
    type: 'request',
    route: '/auth-jwt.php',
    method: 'POST',
    data: { username: 'u', password: 'p' }
  });
  assert.equal(login.upstream_status, 200);
  assert.equal(login.body.data.path, '/api/auth-jwt.php');

  const wxbind = await handler({
    protocol_version: 1,
    type: 'request',
    route: '/auth-jwt.php?action=wxbind',
    method: 'POST',
    data: { pending_wechat_bind_ticket: 'ticket' }
  });
  assert.equal(wxbind.upstream_status, 200);
  assert.equal(wxbind.body.data.path, '/api/auth-jwt.php?action=wxbind');

  const unknown = await handler({ protocol_version: 1, type: 'request', route: '/todos/my.php', method: 'GET' });
  assert.equal(unknown.upstream_status, 404);
  assert.equal(unknown.body.code, 'route_not_allowed');
});

test('auth-proxy 覆盖双登录、绑定、刷新和退出认证入口', async () => {
  const handler = proxy.createHandler({
    transport(options, body, route) {
      return Promise.resolve({ statusCode: 200, body: { code: 0, data: { path: options.path, method: options.method, action: route.action, body } } });
    }
  });
  const cases = [
    ['POST', '/auth-jwt.php', 'login'],
    ['POST', '/auth-jwt.php?action=wxlogin', 'wxlogin'],
    ['POST', '/auth-jwt.php?action=wxbind', 'wxbind'],
    ['POST', '/auth-jwt.php?action=wecomlogin', 'wecomlogin'],
    ['POST', '/auth-jwt.php?action=wecombind', 'wecombind'],
    ['GET', '/auth-jwt.php?action=verify', 'verify'],
    ['GET', '/auth-jwt.php?action=refresh', 'refresh'],
    ['POST', '/auth/refresh.php', 'refresh_session'],
    ['POST', '/auth/logout.php', 'logout']
  ];

  for (const [method, route, action] of cases) {
    const result = await handler({ protocol_version: 1, type: 'request', route, method, data: { sample: true } });
    assert.equal(result.upstream_status, 200);
    assert.equal(result.body.data.action, action);
    assert.equal(result.body.data.method, method);
  }
});

test('auth-proxy 对绑定路由执行幂等冲突保护', async () => {
  const store = proxy.createMemoryIdempotencyStore();
  const handler = proxy.createHandler({
    idempotencyStore: store,
    transport() {
      return Promise.resolve({ statusCode: 200, body: { code: 0, data: { ok: true } } });
    }
  });

  const first = await handler({
    protocol_version: 1,
    type: 'request',
    route: '/auth-jwt.php?action=wxbind',
    method: 'POST',
    data: { code: 'a', pending_wechat_bind_ticket: 'ticket-1' },
    header: { 'Idempotency-Key': 'bind-key' }
  });
  const second = await handler({
    protocol_version: 1,
    type: 'request',
    route: '/auth-jwt.php?action=wxbind',
    method: 'POST',
    data: { code: 'b', pending_wechat_bind_ticket: 'ticket-1' },
    header: { 'Idempotency-Key': 'bind-key' }
  });

  assert.equal(first.upstream_status, 200);
  assert.equal(second.upstream_status, 409);
  assert.equal(second.body.data.conflict_type, 'idempotency_key_reuse');
});

test('小程序绑定页不再跨页携带账号密码', () => {
  const loginSource = readFileSync(join(projectRoot, 'mini-program/pages/login/login.js'), 'utf8');
  const bindSource = readFileSync(join(projectRoot, 'mini-program/pages/wechat-bind/gate.js'), 'utf8');
  const appSource = readFileSync(join(projectRoot, 'mini-program/app.js'), 'utf8');

  assert.equal(loginSource.includes('setPendingWechatBind({ username, password'), false);
  assert.equal(loginSource.includes('setPendingWechatBind({ username, password, bindMode'), false);
  assert.equal(bindSource.includes('pending.password'), false);
  assert.equal(bindSource.includes('password: pending.password'), false);
  assert.ok(bindSource.includes('pending_wechat_bind_ticket: pending.ticket'));
  assert.ok(appSource.includes('ticket ? {'));
});

test('刷新凭据单次消费和复用撤销结构保持有效', () => {
  const service = readFileSync(join(projectRoot, 'api/auth/SessionService.php'), 'utf8');
  const store = readFileSync(join(projectRoot, 'api/auth/SessionStore.php'), 'utf8');
  const miniSession = readFileSync(join(projectRoot, 'api/auth/mini-program-session.php'), 'utf8');

  assert.ok(service.includes('rotateRefreshToken'));
  assert.ok(service.includes('reuse_detected'));
  assert.ok(service.includes('refresh_token_reused'));
  assert.ok(service.includes('version_mismatch'));
  assert.match(store, /SELECT[\s\S]*FOR UPDATE/);
  assert.ok(store.includes("status = 'rotated'"));
  assert.ok(miniSession.includes("$service->refresh($refreshToken"));
  assert.ok(miniSession.includes("revokeFamily"));
});

test('绑定票据消费具备锁定、设备校验和单次更新条件', () => {
  const source = readFileSync(join(projectRoot, 'api/auth-jwt.php'), 'utf8');

  assert.match(source, /WHERE t\.ticket_hash = \?[\s\S]*t\.consumed_at IS NULL[\s\S]*t\.expires_at > NOW\(\)[\s\S]*FOR UPDATE/);
  assert.ok(source.includes("UPDATE miniprogram_bind_tickets SET consumed_at = NOW() WHERE id = ? AND consumed_at IS NULL"));
  assert.ok(source.includes('$stmt->rowCount() !== 1'));
  assert.ok(source.includes('hash_equals((string)$staff[\'device_id\'], $deviceId)'));
  assert.ok(source.includes('hash_equals((string)$staff[\'device_fingerprint\'], $deviceFingerprint)'));
  assert.ok(source.includes('session_version = session_version + 1'));
});

test('认证云入口保持权限边界并只透传服务端认证上下文', async () => {
  let capturedOptions = null;
  const handler = proxy.createHandler({
    transport(options) {
      capturedOptions = options;
      return Promise.resolve({ statusCode: 200, body: { code: 0, data: { ok: true } } });
    }
  });

  const external = await handler({ protocol_version: 1, type: 'request', route: 'https://evil.example/auth-jwt.php', method: 'POST' });
  assert.equal(external.upstream_status, 400);
  assert.equal(external.body.code, 'invalid_route');

  const adminRoute = await handler({ protocol_version: 1, type: 'request', route: '/admin/staff/list.php', method: 'GET' });
  assert.equal(adminRoute.upstream_status, 404);

  await handler({
    protocol_version: 1,
    type: 'request',
    route: '/auth/refresh.php',
    method: 'POST',
    header: { Authorization: 'Bearer access-token', 'X-State-Version': '9' },
    data: { refresh_token: 'psr_' + 'a'.repeat(64) }
  });
  assert.equal(capturedOptions.headers.authorization, 'Bearer access-token');
  assert.equal(capturedOptions.headers['x-state-version'], '9');
  assert.equal(capturedOptions.headers.cookie, undefined);
  assert.equal(capturedOptions.headers['x-user-role'], undefined);
});

test('auth-jwt 签发并消费短期绑定票据', () => {
  const source = readFileSync(join(projectRoot, 'api/auth-jwt.php'), 'utf8');
  const migration = readFileSync(join(projectRoot, 'database/migrations/202608210001_miniprogram_bind_tickets.sql'), 'utf8');

  assert.ok(source.includes('pending_wechat_bind_ticket'));
  assert.ok(source.includes('issueMiniProgramBindTicket'));
  assert.ok(source.includes('consumeMiniProgramBindTicket'));
  assert.ok(source.includes('expires_in'));
  assert.ok(source.includes('DATE_ADD(NOW(), INTERVAL ? SECOND)'));
  assert.ok(source.includes('FOR UPDATE'));
  assert.ok(migration.includes('UNIQUE KEY uniq_miniprogram_bind_ticket_hash'));
  assert.ok(migration.includes("ENUM('wechat', 'wecom')"));
});
