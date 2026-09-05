import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const require = createRequire(import.meta.url);
const root = new URL('../', import.meta.url);
const read = path => readFileSync(new URL(path, root), 'utf8');
const authPath = require.resolve('../mini-program/utils/auth.js');
const apiPath = require.resolve('../mini-program/utils/api.js');
const endpoint = read('api/auth/mini-program-session.php');
const apiSource = read('mini-program/utils/api.js');
const config = read('api/config.php');
const sourceMatrix = JSON.parse(read('mini-program/business-domain-matrix.json'));
const deployedMatrix = JSON.parse(read('cloudfunctions/api-proxy/business-domain-matrix.json'));

const validatesCriteria = criteria => `[validates ${criteria.join(', ')}]`;

function matrixEndpoint(matrix, domainId, path) {
  const domain = matrix.migration_domains.find(({ id }) => id === domainId);
  return domain?.endpoints.find(endpointEntry => endpointEntry.path === path);
}

function jwt(exp) {
  const encode = value => Buffer.from(JSON.stringify(value)).toString('base64url');
  return `${encode({ alg: 'none' })}.${encode({ exp })}.signature`;
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
  };
  delete require.cache[authPath];
  delete require.cache[apiPath];
  return { storage, app, auth: require(authPath), api: require(apiPath) };
}

test('小程序设备会话保留兼容 Token 键并保存轮换凭据', () => {
  const { auth, storage } = createRuntime();
  auth.setSession({
    token: jwt(Math.floor(Date.now() / 1000) + 900),
    refresh_token: 'psr_' + 'a'.repeat(64),
    session_id: 'b'.repeat(32),
    session_version: 7,
    session_type: 'device',
  });

  assert.equal(storage.get('token'), storage.get('jwt_token'));
  assert.equal(auth.getRefreshToken(), 'psr_' + 'a'.repeat(64));
  assert.deepEqual(auth.getSession(), {
    refreshToken: 'psr_' + 'a'.repeat(64),
    sessionId: 'b'.repeat(32),
    sessionVersion: 7,
    sessionType: 'device',
  });
});

test('小程序设备会话保留迁移 readiness 的 503 状态', () => {
  assert.match(endpoint, /\$status = \$error->httpStatus\(\)/);
  assert.match(endpoint, /if \(\$status === 401\)/);
  assert.match(endpoint, /json_response\(\$status/);
  assert.match(config, /\$code >= 400 && \$code <= 599/);
});

test(`${validatesCriteria(['8.1'])} PHP、客户端和两份业务矩阵统一使用 POST 刷新设备会话`, () => {
  const refreshPath = '/auth/mini-program-session.php?action=refresh';

  assert.match(endpoint, /Access-Control-Allow-Methods: POST, OPTIONS/);
  assert.match(endpoint, /REQUEST_METHOD[\s\S]*!== 'POST'/);
  assert.match(apiSource, /route: '\/auth\/mini-program-session\.php\?action=refresh',[\s\S]*?method: 'POST'/);
  assert.equal(matrixEndpoint(sourceMatrix, 'auth_session', refreshPath)?.method, 'POST');
  assert.equal(matrixEndpoint(deployedMatrix, 'auth_session', refreshPath)?.method, 'POST');
});

test('兼容 JSON 响应保留标准 HTTP 错误状态', () => {
  for (const status of [405, 500, 503]) {
    const php = `register_shutdown_function(static function (): void { fwrite(STDERR, (string)http_response_code()); }); require 'api/config.php'; json_response(${status}, 'failed', []);`;
    const result = spawnSync('php', ['-r', php], {
      cwd: root,
      encoding: 'utf8',
      env: {
        ...process.env,
        DB_PASSWORD: 'test-only-placeholder',
        JWT_SECRET: 'test-only-placeholder-with-sufficient-length',
      },
    });
    assert.equal(result.status, 0, result.stderr);
    assert.equal(result.stderr, String(status));
    assert.equal(JSON.parse(result.stdout).code, status);
  }
});

test('并发过期请求共享一次刷新并分别重放原请求', async () => {
  let refreshCalls = 0;
  let businessCalls = 0;
  const runtime = createRuntime({
    request(options) {
      setImmediate(() => {
        if (options.url.includes('mini-program-session.php')) {
          refreshCalls += 1;
          options.success({
            statusCode: 200,
            data: { code: 0, data: {
              token: jwt(Math.floor(Date.now() / 1000) + 900),
              refresh_token: 'psr_' + 'c'.repeat(64),
              session_id: 'd'.repeat(32),
              session_version: 4,
              session_type: 'device',
            } },
          });
          return;
        }
        businessCalls += 1;
        options.success({ statusCode: 200, data: { code: 0, data: { call: businessCalls } } });
      });
    },
  });
  runtime.storage.set('device_id', 'device-1');
  runtime.auth.setSession({
    token: jwt(Math.floor(Date.now() / 1000) - 10),
    refresh_token: 'psr_' + 'a'.repeat(64),
    session_id: 'b'.repeat(32),
    session_version: 4,
    session_type: 'device',
  });

  const results = await Promise.all([
    runtime.api.request({ url: '/one.php' }),
    runtime.api.request({ url: '/two.php' }),
  ]);

  assert.equal(refreshCalls, 1);
  assert.equal(businessCalls, 2);
  assert.equal(results.every(result => result.code === 0), true);
});

test('先后返回的并发 401 识别已更新 Token 并避免二次刷新', async () => {
  let refreshCalls = 0;
  let oldTokenCalls = 0;
  let replayCalls = 0;
  const oldToken = jwt(Math.floor(Date.now() / 1000) + 900);
  const newToken = jwt(Math.floor(Date.now() / 1000) + 1800);
  const runtime = createRuntime({
    request(options) {
      if (options.url.includes('mini-program-session.php')) {
        refreshCalls += 1;
        setImmediate(() => options.success({ statusCode: 200, data: { code: 0, data: {
          token: newToken,
          refresh_token: 'psr_' + 'c'.repeat(64),
          session_id: 'd'.repeat(32),
          session_version: 4,
          session_type: 'device',
        } } }));
        return;
      }
      if (options.header.Authorization === `Bearer ${oldToken}`) {
        oldTokenCalls += 1;
        setTimeout(() => options.success({ statusCode: 401, data: { code: 401, message: 'expired' } }), oldTokenCalls === 1 ? 0 : 20);
        return;
      }
      replayCalls += 1;
      setImmediate(() => options.success({ statusCode: 200, data: { code: 0, data: { replayed: true } } }));
    },
  });
  runtime.storage.set('device_id', 'device-http-401');
  runtime.auth.setSession({
    token: oldToken,
    refresh_token: 'psr_' + 'a'.repeat(64),
    session_id: 'b'.repeat(32),
    session_version: 4,
    session_type: 'device',
  });

  await Promise.all([
    runtime.api.request({ url: '/one.php' }),
    runtime.api.request({ url: '/two.php' }),
  ]);
  assert.equal(refreshCalls, 1);
  assert.equal(replayCalls, 2);
});

test('上传响应中的业务 401 触发一次刷新并重试上传', async () => {
  let refreshCalls = 0;
  let uploadCalls = 0;
  const runtime = createRuntime({
    request(options) {
      setImmediate(() => {
        refreshCalls += 1;
        options.success({ statusCode: 200, data: { code: 0, data: {
          token: jwt(Math.floor(Date.now() / 1000) + 900),
          refresh_token: 'psr_' + 'e'.repeat(64),
          session_id: 'f'.repeat(32),
          session_version: 5,
          session_type: 'device',
        } } });
      });
    },
    uploadFile(options) {
      uploadCalls += 1;
      setImmediate(() => options.success(uploadCalls === 1
        ? { statusCode: 200, data: JSON.stringify({ code: 401, message: 'expired' }) }
        : { statusCode: 200, data: JSON.stringify({ code: 0, data: { uploaded: true } }) }));
      return { onProgressUpdate() {} };
    },
  });
  runtime.storage.set('device_id', 'device-2');
  runtime.auth.setSession({
    token: jwt(Math.floor(Date.now() / 1000) + 900),
    refresh_token: 'psr_' + 'a'.repeat(64),
    session_id: 'b'.repeat(32),
    session_version: 5,
    session_type: 'device',
  });

  const result = await runtime.api.uploadFile({ url: '/upload.php', filePath: '/tmp/file' });
  assert.equal(result.data.uploaded, true);
  assert.equal(refreshCalls, 1);
  assert.equal(uploadCalls, 2);
});

test('退出立即清理本地凭据并请求撤销服务端会话族', async () => {
  let logoutRequest = null;
  const runtime = createRuntime({
    request(options) {
      logoutRequest = options;
      setImmediate(() => options.success({ statusCode: 200, data: { code: 0 } }));
    },
  });
  runtime.storage.set('device_id', 'device-logout');
  runtime.auth.setSession({
    token: jwt(Math.floor(Date.now() / 1000) + 900),
    refresh_token: 'psr_' + 'a'.repeat(64),
    session_id: 'b'.repeat(32),
    session_version: 5,
    session_type: 'device',
  });

  const pending = runtime.api.logoutSession();
  assert.equal(runtime.auth.getToken(), '');
  assert.equal(runtime.auth.getRefreshToken(), '');
  await pending;
  assert.match(logoutRequest.url, /action=logout$/);
  assert.equal(logoutRequest.data.device_id, 'device-logout');
});

test('服务端将设备会话绑定身份摘要、设备和当前会话版本', () => {
  const migration = read('database/migrations/202607310003_miniprogram_device_sessions.sql');
  const helper = read('api/auth/MiniProgramSession.php');
  const endpoint = read('api/auth/mini-program-session.php');
  const authApi = read('api/auth-jwt.php');
  const wecom = read('api/wecom/_common.php');
  const capabilities = read('api/platform/capabilities.php');

  assert.match(migration, /ADD COLUMN identity_hash CHAR\(64\)/);
  assert.match(helper, /hash\('sha256', \$provider \. ':' \. \$identity\)/);
  assert.match(helper, /hash_equals\(\(string\)\(\$session\['device_id'\]/);
  assert.match(helper, /wechat_identity_changed/);
  assert.match(endpoint, /platformValidateMiniProgramSession/);
  assert.match(endpoint, /->refresh\(\$refreshToken, \(int\)\(\$staff\['session_version'\]/);
  assert.match(authApi, /client_type[^\n]*mini_program/);
  assert.match(authApi, /session_version = session_version \+ 1/);
  assert.equal((wecom.match(/session_version = session_version \+ 1/g) || []).length >= 2, true);
  assert.match(capabilities, /mini_program_device_session/);
  assert.match(capabilities, /legacy_bearer_compatible' => true/);
});

test('身份摘要稳定区分微信和企业微信身份', () => {
  const php = String.raw`
    require 'api/auth/MiniProgramSession.php';
    echo json_encode([
      platformMiniProgramIdentity(['openid' => 'openid-1', 'wecom_userid' => 'member-1'], 'wechat'),
      platformMiniProgramIdentity(['openid' => 'openid-1', 'wecom_userid' => 'member-1'], 'wecom'),
      platformMiniProgramIdentity([], 'wechat'),
    ]);
  `;
  const result = spawnSync('php', ['-r', php], { cwd: root, encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  const identities = JSON.parse(result.stdout);
  assert.equal(identities[0].provider, 'wechat');
  assert.equal(identities[1].provider, 'wecom');
  assert.match(identities[0].hash, /^[a-f0-9]{64}$/);
  assert.notEqual(identities[0].hash, identities[1].hash);
  assert.equal(identities[2].hash, '');
});
