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
const directTransport = require('../mini-program/utils/transports/direct.js');
const cloudTransport = require('../mini-program/utils/transports/cloud.js');
const shadowTransport = require('../mini-program/utils/transports/shadow.js');
const cloudConfig = require('../mini-program/config/cloud.js');

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
  const app = { globalData: Object.assign({ apiBase: 'https://example.test/api', token: null, userInfo: null }, handlers.globalData || {}) };
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
  if (handlers.callFunction) {
    global.wx.cloud = { callFunction: handlers.callFunction };
  }
  delete require.cache[authPath];
  delete require.cache[apiPath];
  return { storage, app, auth: require(authPath), api: require(apiPath) };
}

test('原生网络调用只存在于统一小程序 API 客户端', () => {
  const offenders = [];
  for (const path of javascriptFiles(miniProgramRoot)) {
    if (path === apiPath) continue;
    const source = readFileSync(path, 'utf8');
    const sourcePath = relative(miniProgramRoot, path);
    if (/wx\.(?:request|uploadFile)\s*\(/.test(source) && !sourcePath.startsWith('utils/transports/')) {
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

test('cloud transport 将云函数包络转换为现有响应对象', async () => {
  let captured = null;
  const runtime = createRuntime({
    globalData: { cloudbase: { TRANSPORT: 'cloud' } },
    callFunction(options) {
      captured = options;
      setImmediate(() => options.success({
        result: {
          upstream_status: 200,
          body: { code: 0, request_id: options.data.request_id, data: { saved: true } }
        }
      }));
    },
  });
  runtime.auth.setSession({ token: jwt(Math.floor(Date.now() / 1000) + 900) });

  const result = await runtime.api.request({ url: '/objects.php', method: 'POST', requestId: 'req-cloud', data: { name: 'object' } });

  assert.equal(captured.name, 'api-proxy');
  assert.equal(captured.data.protocol_version, 1);
  assert.equal(captured.data.route, '/objects.php');
  assert.equal(captured.data.method, 'POST');
  assert.equal(captured.data.header.Authorization.startsWith('Bearer '), true);
  assert.equal(result.data.saved, true);
});

test('cloud transport 为认证请求选择 auth-proxy', async () => {
  let functionName = '';
  const runtime = createRuntime({
    globalData: { cloudbase: { TRANSPORT: 'cloud' } },
    callFunction(options) {
      functionName = options.name;
      setImmediate(() => options.success({ result: { upstream_status: 200, body: { code: 0, data: { token: 'new' } } } }));
    },
  });

  await runtime.api.request({ url: '/auth-jwt.php', method: 'POST', auth: false });

  assert.equal(functionName, 'auth-proxy');
});

test('cloud transport 保持 409 冲突恢复元数据', async () => {
  const runtime = createRuntime({
    globalData: { cloudbase: { TRANSPORT: 'cloud' } },
    callFunction(options) {
      setImmediate(() => options.success({
        result: {
          upstream_status: 409,
          body: {
            code: 409,
            message: '版本冲突',
            data: {
              conflict_type: 'state_version',
              base_version: 3,
              current_version: 4,
              authoritative_state: { value: 10 },
              recovery_action: 'reload'
            }
          }
        }
      }));
    },
  });
  runtime.auth.setSession({ token: jwt(Math.floor(Date.now() / 1000) + 900) });

  await assert.rejects(
    runtime.api.request({ url: '/workload/save-report.php', method: 'POST' }),
    error => error.category === 'conflict'
      && error.conflictType === 'state_version'
      && error.baseVersion === 3
      && error.currentVersion === 4
      && error.recoveryAction === 'reload',
  );
});

test('cloud transport 映射超时、校验错误和服务端错误分类', async () => {
  const cases = [
    { statusCode: 422, code: 422, category: 'validation' },
    { statusCode: 500, code: 500, category: 'server' },
  ];

  for (const item of cases) {
    const runtime = createRuntime({
      globalData: { cloudbase: { TRANSPORT: 'cloud' } },
      callFunction(options) {
        setImmediate(() => options.success({ result: { upstream_status: item.statusCode, body: { code: item.code, message: 'failed' } } }));
      },
    });
    runtime.auth.setSession({ token: jwt(Math.floor(Date.now() / 1000) + 900) });

    await assert.rejects(
      runtime.api.request({ url: '/objects.php' }),
      error => error.statusCode === item.statusCode && error.category === item.category,
    );
  }

  const timeoutRuntime = createRuntime({
    globalData: { cloudbase: { TRANSPORT: 'cloud' } },
    callFunction(options) {
      setImmediate(() => options.fail({ errMsg: 'cloud callFunction:fail timeout' }));
    },
  });
  timeoutRuntime.auth.setSession({ token: jwt(Math.floor(Date.now() / 1000) + 900) });

  await assert.rejects(
    timeoutRuntime.api.request({ url: '/objects.php' }),
    error => error.category === 'timeout' && error.retryable === true,
  );
});

test('shadow transport 只对只读请求触发影子对照并记录差异摘要', async () => {
  const previousRate = cloudConfig.SHADOW_SAMPLE_RATE;
  cloudConfig.SHADOW_SAMPLE_RATE = 1;
  try {
    let cloudCalls = 0;
    let directCalls = 0;
    const runtime = createRuntime({
      globalData: { cloudbase: { TRANSPORT: 'shadow' } },
      request(options) {
        directCalls += 1;
        setImmediate(() => options.success({ statusCode: 200, data: { code: 0, data: { items: [1, 2], message: 'ok', token: 'secret-value' } } }));
      },
      callFunction(options) {
        cloudCalls += 1;
        setImmediate(() => options.success({ result: { upstream_status: 200, body: { code: 0, data: { items: [1, 2], message: 'ok', token: 'different-secret' } } } }));
      },
    });
    runtime.auth.setSession({ token: jwt(Math.floor(Date.now() / 1000) + 900) });

    const result = await runtime.api.request({ url: '/objects.php', method: 'GET', requestId: 'req-shadow' });
    await new Promise(resolve => setImmediate(resolve));

    assert.equal(result.data.items.length, 2);
    assert.equal(cloudCalls, 1);
    assert.equal(directCalls, 1);
    const records = runtime.storage.get('shadow_request_diffs');
    assert.equal(Array.isArray(records), true);
    assert.equal(records.length, 1);
    assert.equal(records[0].requestId, 'req-shadow');
    assert.equal(records[0].status, 'matched');
    assert.deepEqual(records[0].diffCategories, []);

    const postRuntime = createRuntime({
      globalData: { cloudbase: { TRANSPORT: 'shadow' } },
      request(options) {
        directCalls += 1;
        setImmediate(() => options.success({ statusCode: 200, data: { code: 0, data: { ok: true } } }));
      },
      callFunction(options) {
        cloudCalls += 1;
        setImmediate(() => options.success({ result: { upstream_status: 200, body: { code: 0, data: { ok: true } } } }));
      },
    });
    postRuntime.auth.setSession({ token: jwt(Math.floor(Date.now() / 1000) + 900) });

    await postRuntime.api.request({ url: '/objects.php', method: 'POST' });
    await new Promise(resolve => setImmediate(resolve));

    assert.equal(cloudCalls, 2);
    assert.equal(directCalls, 1);
  } finally {
    cloudConfig.SHADOW_SAMPLE_RATE = previousRate;
  }
});

test('版本化 transport 依据客户端版本和紧急配置选择通道', () => {
  const lowVersionRuntime = createRuntime({
    globalData: { cloudbase: { TRANSPORT: 'versioned', TRANSPORT_POLICY_VERSION: 1, TRANSPORT_MIN_CLIENT_VERSION: '2.0.0' } },
  });
  lowVersionRuntime.app.globalData.deviceInfo = { app_version: '1.5.0' };
  assert.equal(lowVersionRuntime.api.resolveTransport({ method: 'GET' }), directTransport);

  const shadowRuntime = createRuntime({
    globalData: { cloudbase: { TRANSPORT: 'versioned', TRANSPORT_POLICY_VERSION: 1, TRANSPORT_MIN_CLIENT_VERSION: '1.0.0' } },
  });
  shadowRuntime.app.globalData.deviceInfo = { app_version: '2.1.0' };
  assert.equal(shadowRuntime.api.resolveTransport({ method: 'GET' }), shadowTransport);
  assert.equal(shadowRuntime.api.resolveTransport({ method: 'POST' }), cloudTransport);

  const emergencyRuntime = createRuntime({
    globalData: { cloudbase: { TRANSPORT: 'versioned', TRANSPORT_POLICY_VERSION: 1, TRANSPORT_MIN_CLIENT_VERSION: '1.0.0', TRANSPORT_EMERGENCY_MODE: 'direct', TRANSPORT_EMERGENCY_ACTIVE: true } },
  });
  emergencyRuntime.app.globalData.deviceInfo = { app_version: '9.9.9' };
  assert.equal(emergencyRuntime.api.resolveTransport({ method: 'GET' }), directTransport);
});

test('cloud transport 通过 auth-proxy 刷新设备会话并重放请求', async () => {
  const calls = [];
  const runtime = createRuntime({
    globalData: { cloudbase: { TRANSPORT: 'cloud' } },
    callFunction(options) {
      calls.push(options);
      if (options.data.route.includes('mini-program-session.php?action=refresh')) {
        setImmediate(() => options.success({
          result: {
            upstream_status: 200,
            body: {
              code: 0,
              data: {
                token: jwt(Math.floor(Date.now() / 1000) + 900),
                refresh_token: 'psr_' + 'c'.repeat(64),
                session_id: 'd'.repeat(32),
                session_type: 'device'
              }
            }
          }
        }));
        return;
      }
      setImmediate(() => options.success({ result: { upstream_status: 200, body: { code: 0, data: { ok: true } } } }));
    },
  });
  runtime.storage.set('device_id', 'device-cloud-refresh');
  runtime.auth.setSession({
    token: jwt(Math.floor(Date.now() / 1000) - 10),
    refresh_token: 'psr_' + 'a'.repeat(64),
    session_id: 'b'.repeat(32),
    session_version: 2,
    session_type: 'device',
  });

  const result = await runtime.api.request({ url: '/objects.php' });

  assert.equal(result.data.ok, true);
  assert.equal(calls[0].name, 'auth-proxy');
  assert.equal(calls[0].data.route, '/auth/mini-program-session.php?action=refresh');
  assert.equal(calls[1].name, 'api-proxy');
  assert.equal(runtime.auth.getRefreshToken(), 'psr_' + 'c'.repeat(64));
});

test('direct 与 cloud transport 对固定输入保持业务响应等价', async () => {
  const fixtures = [
    { url: '/todos/my.php', method: 'GET', data: {} },
    { url: '/workload/save-report.php', method: 'POST', data: { staff_id: 7, value: 3 }, idempotencyKey: 'idem-workload' },
    { url: '/points/checkin.php', method: 'POST', data: { staff_id: 7 }, idempotencyKey: 'idem-checkin' },
  ];

  for (const fixture of fixtures) {
    const body = { code: 0, data: { employee_id: 7, route: fixture.url, method: fixture.method, accepted: true } };
    const directRuntime = createRuntime({
      request(options) {
        setImmediate(() => options.success({ statusCode: 200, data: body }));
      },
    });
    directRuntime.auth.setSession({ token: jwt(Math.floor(Date.now() / 1000) + 900) });
    const directResult = await directRuntime.api.request(Object.assign({}, fixture, { transport: 'direct' }));

    const cloudRuntime = createRuntime({
      globalData: { cloudbase: { TRANSPORT: 'cloud' } },
      callFunction(options) {
        setImmediate(() => options.success({ result: { upstream_status: 200, body } }));
      },
    });
    cloudRuntime.auth.setSession({ token: jwt(Math.floor(Date.now() / 1000) + 900) });

    const cloudResult = await cloudRuntime.api.request(fixture);

    assert.deepEqual(cloudResult, directResult);
  }
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
