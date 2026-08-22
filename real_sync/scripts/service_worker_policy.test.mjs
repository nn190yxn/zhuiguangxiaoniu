import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import vm from 'node:vm';

const workerSource = readFileSync(new URL('../sw.js', import.meta.url), 'utf8');

function createHarness(options = {}) {
  const handlers = new Map();
  const writes = [];
  const deleted = [];
  const added = [];
  let skipWaitingCalls = 0;
  const offlineResponse = { source: 'offline' };
  const cache = {
    addAll(paths) {
      added.push(...paths);
      return Promise.resolve();
    },
    match(request) {
      const key = typeof request === 'string' ? request : request.url;
      if (key === '/mobile/offline.html') return Promise.resolve(offlineResponse);
      return Promise.resolve(undefined);
    },
    put(request, response) {
      writes.push({ request, response });
      return Promise.resolve();
    },
  };
  const context = {
    URL,
    Promise,
    fetch: options.fetch || (() => Promise.resolve({ ok: true, type: 'basic', clone() { return this; } })),
    caches: {
      open: () => Promise.resolve(cache),
      keys: () => Promise.resolve(options.cacheKeys || []),
      delete: (key) => {
        deleted.push(key);
        return Promise.resolve(true);
      },
      match: (request) => cache.match(request),
    },
    self: {
      location: { origin: 'https://supercalf.com' },
      clients: { claim: () => Promise.resolve() },
      skipWaiting: () => {
        skipWaitingCalls += 1;
        return Promise.resolve();
      },
      addEventListener: (name, handler) => handlers.set(name, handler),
    },
  };
  vm.runInNewContext(workerSource, context);
  return { added, deleted, handlers, offlineResponse, skipWaitingCalls: () => skipWaitingCalls, writes };
}

function lifecycleEvent() {
  let pending;
  return {
    event: { waitUntil(value) { pending = value; } },
    done: () => pending,
  };
}

function fetchEvent(path, overrides = {}) {
  let responsePromise;
  return {
    event: {
      request: {
        method: 'GET',
        mode: 'same-origin',
        destination: 'script',
        url: `https://supercalf.com${path}`,
        ...overrides,
      },
      respondWith(value) { responsePromise = value; },
    },
    response: () => responsePromise,
  };
}

test('安装仅预缓存批准应用壳并保持新 Worker 等待', async () => {
  const harness = createHarness();
  const install = lifecycleEvent();
  harness.handlers.get('install')(install.event);
  await install.done();

  assert.ok(harness.added.includes('/mobile/offline.html'));
  assert.ok(harness.added.includes('/js/mobile-pwa.js'));
  assert.ok(harness.added.includes('/js/draft-store.js'));
  assert.ok(!harness.added.some((path) => path.startsWith('/api/')));
  assert.equal(harness.skipWaitingCalls(), 0);
});

test('API、管理端、上传和未批准页面完全绕过共享缓存', () => {
  const harness = createHarness();
  for (const path of ['/api/auth/me.php', '/admin/dashboard.html', '/uploads/private.jpg', '/mobile/unknown.html']) {
    const request = fetchEvent(path);
    harness.handlers.get('fetch')(request.event);
    assert.equal(request.response(), undefined, `${path} should bypass the worker`);
  }
  assert.equal(harness.writes.length, 0);
});

test('批准公共资源成功响应写入版本化应用壳缓存', async () => {
  const harness = createHarness();
  const request = fetchEvent('/js/mobile-pwa.js?v=4');
  harness.handlers.get('fetch')(request.event);
  await request.response();

  assert.equal(harness.writes.length, 1);
  assert.equal(harness.writes[0].request.url, 'https://supercalf.com/js/mobile-pwa.js?v=4');
});

test('批准页面离线导航返回专用离线壳', async () => {
  const harness = createHarness({ fetch: () => Promise.reject(new Error('offline')) });
  const request = fetchEvent('/mobile/mine.html', { mode: 'navigate', destination: 'document' });
  harness.handlers.get('fetch')(request.event);

  assert.equal(await request.response(), harness.offlineResponse);
});

test('激活清理旧 PWA 缓存且保留无关缓存', async () => {
  const harness = createHarness({ cacheKeys: ['zgxn-pwa-shell-v18', 'zgxn-pwa-shell-v19', 'zgxn-pwa-shell-v20', 'other-cache'] });
  const activate = lifecycleEvent();
  harness.handlers.get('activate')(activate.event);
  await activate.done();

  assert.deepEqual(harness.deleted, ['zgxn-pwa-shell-v18', 'zgxn-pwa-shell-v19']);
});

test('只有用户确认消息触发 waiting Worker 切换', () => {
  const harness = createHarness();
  const handler = harness.handlers.get('message');
  handler({ data: { type: 'GET_VERSION' }, ports: [{ postMessage() {} }] });
  assert.equal(harness.skipWaitingCalls(), 0);
  handler({ data: 'SKIP_WAITING', ports: [] });
  assert.equal(harness.skipWaitingCalls(), 1);
});
