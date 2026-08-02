import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import test from 'node:test';

const require = createRequire(import.meta.url);
const root = new URL('../', import.meta.url);
const projectRoot = new URL('..', import.meta.url).pathname;
const miniProgramRoot = join(projectRoot, 'mini-program');
const authPath = require.resolve('../mini-program/utils/auth.js');
const apiPath = require.resolve('../mini-program/utils/api.js');

function jwt(exp) {
  const encode = value => Buffer.from(JSON.stringify(value)).toString('base64url');
  return `${encode({ alg: 'none' })}.${encode({ exp })}.signature`;
}

function javascriptFiles(directory) {
  return readdirSync(directory).flatMap(name => {
    const path = join(directory, name);
    return statSync(path).isDirectory() ? javascriptFiles(path) : (path.endsWith('.js') ? [path] : []);
  });
}

function createRuntime(handlers = {}) {
  const storage = new Map();
  const app = { globalData: { apiBase: 'https://example.test/api', token: null, userInfo: null } };
  global.getApp = () => app;
  global.wx = {
    getStorageSync: key => storage.get(key),
    setStorageSync: (key, value) => storage.set(key, value),
    removeStorageSync: key => storage.delete(key),
    reLaunch: handlers.reLaunch || (() => {}),
    request: handlers.request || (() => {}),
    uploadFile: handlers.uploadFile || (() => ({ onProgressUpdate() {} })),
    getFileInfo: handlers.getFileInfo,
  };
  delete require.cache[authPath];
  delete require.cache[apiPath];
  return { storage, app, auth: require(authPath), api: require(apiPath) };
}

test('原生网络调用只存在于统一小程序 API 客户端', () => {
  const offenders = [];
  for (const path of javascriptFiles(miniProgramRoot)) {
    if (path === apiPath) continue;
    const source = readFileSync(path, 'utf8');
    if (/wx\.(?:request|uploadFile)\s*\(/.test(source)) {
      offenders.push(relative(miniProgramRoot, path));
    }
  }
  assert.deepEqual(offenders, []);
});

test('统一请求传播请求 ID、幂等键、状态版本、认证和超时', async () => {
  let captured = null;
  const runtime = createRuntime({
    request(options) {
      captured = options;
      setImmediate(() => options.success({
        statusCode: 200,
        data: { code: 0, request_id: options.header['X-Request-ID'], data: { saved: true } },
      }));
    },
  });
  runtime.auth.setSession({ token: jwt(Math.floor(Date.now() / 1000) + 900) });

  const result = await runtime.api.request({
    url: '/objects.php',
    method: 'POST',
    data: { name: 'object' },
    requestId: 'req-fixed',
    idempotencyKey: 'idem-fixed',
    stateVersion: 7,
  });

  assert.equal(captured.url, 'https://example.test/api/objects.php');
  assert.equal(captured.timeout, 15000);
  assert.equal(captured.header.Authorization.startsWith('Bearer '), true);
  assert.equal(captured.header['X-Request-ID'], 'req-fixed');
  assert.equal(captured.header['Idempotency-Key'], 'idem-fixed');
  assert.deepEqual(captured.data, { name: 'object', state_version: 7 });
  assert.equal(result.request_id, 'req-fixed');
});

test('匿名请求跳过旧会话刷新和 Authorization 注入', async () => {
  let refreshCalls = 0;
  let loginRequest = null;
  const runtime = createRuntime({
    request(options) {
      if (options.url.includes('mini-program-session.php')) refreshCalls += 1;
      loginRequest = options;
      setImmediate(() => options.success({ statusCode: 200, data: { code: 0, data: { token: 'new' } } }));
    },
  });
  runtime.storage.set('device_id', 'device-anonymous');
  runtime.auth.setSession({
    token: jwt(Math.floor(Date.now() / 1000) - 10),
    refresh_token: 'psr_' + 'a'.repeat(64),
    session_id: 'b'.repeat(32),
    session_version: 2,
    session_type: 'device',
  });

  await runtime.api.request({ url: '/auth-jwt.php', method: 'POST', auth: false });

  assert.equal(refreshCalls, 0);
  assert.equal(loginRequest.header.Authorization, undefined);
});

test('受保护请求在本地无 Token 时直接进入重新认证且不发起网络请求', async () => {
  let requestCalls = 0;
  let redirectCalls = 0;
  const runtime = createRuntime({
    request() {
      requestCalls += 1;
    },
    reLaunch() {
      redirectCalls += 1;
    },
  });

  await assert.rejects(
    runtime.api.request({ url: '/protected.php' }),
    error => error.category === 'unauthorized',
  );

  assert.equal(requestCalls, 0);
  assert.equal(redirectCalls, 1);
  assert.equal(runtime.storage.get('auth_state'), 'reauthentication');
});

test('主动刷新失败清理会话并只触发一次重新认证', async () => {
  let refreshCalls = 0;
  let refreshRequestId = '';
  let redirectCalls = 0;
  const runtime = createRuntime({
    reLaunch() {
      redirectCalls += 1;
    },
    request(options) {
      refreshCalls += 1;
      refreshRequestId = options.header['X-Request-ID'];
      setImmediate(() => options.success({ statusCode: 401, data: { code: 401, message: 'expired' } }));
    },
  });
  runtime.storage.set('device_id', 'device-expired');
  runtime.auth.setSession({
    token: jwt(Math.floor(Date.now() / 1000) - 10),
    refresh_token: 'psr_' + 'a'.repeat(64),
    session_id: 'b'.repeat(32),
    session_version: 2,
    session_type: 'device',
  });

  const results = await Promise.allSettled([
    runtime.api.request({ url: '/one.php' }),
    runtime.api.request({ url: '/two.php' }),
  ]);

  assert.equal(results.every(result => result.status === 'rejected'), true);
  assert.equal(refreshCalls, 1);
  assert.match(refreshRequestId, /^mp_/);
  assert.equal(redirectCalls, 1);
  assert.equal(runtime.auth.getToken(), '');
  assert.equal(runtime.auth.getRefreshToken(), '');
  assert.equal(runtime.storage.get('auth_state'), 'reauthentication');
});

test('上传计算 SHA-256 摘要并传播请求恢复元数据', async () => {
  let captured = null;
  let progressHandler = null;
  const runtime = createRuntime({
    getFileInfo(options) {
      setImmediate(() => options.success({ digest: 'a'.repeat(64) }));
    },
    uploadFile(options) {
      captured = options;
      setImmediate(() => options.success({
        statusCode: 200,
        data: JSON.stringify({ code: 0, request_id: options.header['X-Request-ID'], data: { uploaded: true } }),
      }));
      return { onProgressUpdate(handler) { progressHandler = handler; } };
    },
  });
  runtime.auth.setSession({ token: jwt(Math.floor(Date.now() / 1000) + 900) });
  const progress = [];

  const pending = runtime.api.uploadFile({
    url: '/upload.php',
    filePath: '/tmp/evidence.jpg',
    requestId: 'req-upload',
    idempotencyKey: 'idem-upload',
    stateVersion: 9,
    formData: { report_id: 3 },
    onProgress: value => progress.push(value),
  });
  await new Promise(resolve => setImmediate(resolve));
  progressHandler({ progress: 37 });
  const result = await pending;

  assert.equal(captured.timeout, 60000);
  assert.equal(captured.header['X-Request-ID'], 'req-upload');
  assert.equal(captured.header['Idempotency-Key'], 'idem-upload');
  assert.equal(captured.header['Content-Type'], undefined);
  assert.equal(captured.formData.file_sha256, 'a'.repeat(64));
  assert.equal(captured.formData.state_version, 9);
  assert.deepEqual(progress, [37]);
  assert.equal(result.data.uploaded, true);
});

test('上传非法 JSON 返回可重试的协议错误', async () => {
  const runtime = createRuntime({
    uploadFile(options) {
      setImmediate(() => options.success({ statusCode: 200, data: '<html>gateway error</html>' }));
      return { onProgressUpdate() {} };
    },
  });
  runtime.auth.setSession({ token: jwt(Math.floor(Date.now() / 1000) + 900) });

  await assert.rejects(
    runtime.api.uploadFile({ url: '/upload.php', filePath: '/tmp/evidence.jpg', uploadDigest: 'b'.repeat(64) }),
    error => error.category === 'protocol' && error.retryable === true && Boolean(error.requestId),
  );
});

test('不完整设备会话清理旧刷新凭据', () => {
  const runtime = createRuntime();
  runtime.auth.setSession({
    token: jwt(Math.floor(Date.now() / 1000) + 900),
    refresh_token: 'psr_' + 'a'.repeat(64),
    session_id: 'b'.repeat(32),
    session_version: 2,
    session_type: 'device',
  });

  runtime.auth.setSession({ token: jwt(Math.floor(Date.now() / 1000) + 1800), session_type: 'device' });

  assert.equal(runtime.auth.getRefreshToken(), '');
  assert.equal(runtime.auth.getSession().sessionId, '');
});
