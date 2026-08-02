import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import vm from 'node:vm';

const authSource = readFileSync(new URL('../js/app-auth.js', import.meta.url), 'utf8');

function createHarness(fetchHandler) {
  const cookies = new Map([['platform_csrf', 'csrf-initial']]);
  const channels = new Set();
  const messages = [];
  let lockTail = Promise.resolve();

  class SessionChannel {
    constructor(name) {
      this.name = name;
      this.onmessage = null;
      channels.add(this);
    }

    postMessage(message) {
      messages.push(structuredClone(message));
      for (const channel of channels) {
        if (channel !== this && channel.name === this.name && channel.onmessage) {
          queueMicrotask(() => channel.onmessage({ data: structuredClone(message) }));
        }
      }
    }
  }

  function createTab(pathname) {
    const localValues = new Map();
    const sessionValues = new Map();
    const storage = values => ({
      getItem: key => values.get(key) || null,
      setItem: (key, value) => values.set(key, String(value)),
      removeItem: key => values.delete(key),
    });
    const document = {};
    Object.defineProperty(document, 'cookie', {
      get() {
        return [...cookies].map(([key, value]) => `${key}=${encodeURIComponent(value)}`).join('; ');
      },
      set(serialized) {
        const [pair] = serialized.split(';');
        const separator = pair.indexOf('=');
        const key = pair.slice(0, separator);
        const value = decodeURIComponent(pair.slice(separator + 1));
        if (/Max-Age=0/i.test(serialized)) cookies.delete(key);
        else cookies.set(key, value);
      },
    });
    const location = { protocol: 'https:', pathname, search: '', href: '' };
    const events = [];
    const context = {
      BroadcastChannel: SessionChannel,
      document,
      fetch: (url, options = {}) => fetchHandler({ url, options, tab: context }),
      localStorage: storage(localValues),
      sessionStorage: storage(sessionValues),
      location,
      navigator: {
        locks: {
          request(name, callback) {
            assert.equal(name, 'platform-session-refresh');
            const result = lockTail.then(callback);
            lockTail = result.catch(() => {});
            return result;
          },
        },
      },
      queueMicrotask,
      setTimeout,
      clearTimeout,
      structuredClone,
      URLSearchParams,
      CustomEvent: class CustomEvent {
        constructor(type, init = {}) { this.type = type; this.detail = init.detail; }
      },
      dispatchEvent: event => { events.push(event); return true; },
      atob: value => Buffer.from(value, 'base64').toString('binary'),
      escape,
    };
    context.window = context;
    context.__events = events;
    vm.runInNewContext(authSource, context, { filename: 'app-auth.js' });
    return context;
  }

  function broadcast(message) {
    messages.push(structuredClone(message));
    for (const channel of channels) {
      if (channel.onmessage) queueMicrotask(() => channel.onmessage({ data: structuredClone(message) }));
    }
  }

  return { broadcast, cookies, createTab, messages };
}

function response(status, body) {
  return { status, ok: status >= 200 && status < 300, json: async () => body };
}

test('PWA tabs serialize refresh rotation and broadcast metadata without tokens', async () => {
  let refreshCalls = 0;
  let activeRefreshes = 0;
  let maxActiveRefreshes = 0;
  const harness = createHarness(async ({ url }) => {
    assert.equal(url, '/api/auth/refresh.php');
    activeRefreshes += 1;
    maxActiveRefreshes = Math.max(maxActiveRefreshes, activeRefreshes);
    await new Promise(resolve => setTimeout(resolve, 5));
    activeRefreshes -= 1;
    refreshCalls += 1;
    return response(200, { code: 0, data: { access_token: `access-${refreshCalls}`, session_version: 7 } });
  });

  const firstTab = harness.createTab('/mobile/dashboard.html');
  const secondTab = harness.createTab('/mobile/mine.html');
  await Promise.all([
    firstTab.AppAuth.ensureAccessToken(false),
    secondTab.AppAuth.ensureAccessToken(false),
  ]);

  assert.equal(refreshCalls, 2);
  assert.equal(maxActiveRefreshes, 1);
  assert.match(firstTab.AppAuth.getToken(), /^access-/);
  assert.match(secondTab.AppAuth.getToken(), /^access-/);
  assert.equal(harness.messages.every(message => !JSON.stringify(message).toLowerCase().includes('token')), true);
  assert.equal(harness.messages.every(message => message.type === 'session-updated' && message.session_version === 7), true);
});

test('PWA delayed concurrent 401 responses share the refreshed token without a second rotation', async () => {
  let refreshCalls = 0;
  let oldTokenCalls = 0;
  let replayCalls = 0;
  const harness = createHarness(async ({ url, options }) => {
    if (url === '/api/auth/refresh.php') {
      refreshCalls += 1;
      return response(200, { code: 0, data: { access_token: `access-${refreshCalls}`, session_version: 9 } });
    }
    if (options.headers.Authorization === 'Bearer access-1') {
      oldTokenCalls += 1;
      await new Promise(resolve => setTimeout(resolve, oldTokenCalls === 1 ? 0 : 15));
      return response(401, { code: 401, message: 'expired' });
    }
    replayCalls += 1;
    return response(200, { code: 0, data: { replayed: true } });
  });
  const tab = harness.createTab('/mobile/dashboard.html');
  await tab.AppAuth.ensureAccessToken(false);
  const refreshBaseline = refreshCalls;

  const results = await Promise.all([
    tab.AppAuth.authFetch('/api/one.php'),
    tab.AppAuth.authFetch('/api/two.php'),
  ]);

  assert.equal(refreshCalls - refreshBaseline, 1);
  assert.equal(replayCalls, 2);
  assert.equal(results.every(result => result.status === 200), true);
});

test('PWA session revocation clears in-memory authorization in every tab', async () => {
  const harness = createHarness(async ({ url }) => {
    if (url.includes('action=logout')) return response(200, { code: 0 });
    return response(200, { code: 0, data: { access_token: 'access-current', session_version: 3 } });
  });
  const firstTab = harness.createTab('/mobile/dashboard.html');
  const secondTab = harness.createTab('/mobile/mine.html');
  await Promise.all([
    firstTab.AppAuth.ensureAccessToken(false),
    secondTab.AppAuth.ensureAccessToken(false),
  ]);

  harness.broadcast({ type: 'session-revoked', session_version: 4 });
  await new Promise(resolve => setTimeout(resolve, 0));

  assert.equal(firstTab.AppAuth.getToken(), '');
  assert.equal(secondTab.AppAuth.getToken(), '');
  assert.equal(firstTab.__events.some(event => event.type === 'app-auth:sensitive-clear' && event.detail.reason === 'session-revoked'), true);
  assert.equal(secondTab.__events.some(event => event.type === 'app-auth:sensitive-clear' && event.detail.reason === 'session-revoked'), true);
});

test('PWA session version changes publish a sensitive draft cleanup event', async () => {
  const harness = createHarness(async () => response(200, { code: 0, data: { access_token: 'access-current', session_version: 3 } }));
  const tab = harness.createTab('/mobile/workload-v2.html');
  await tab.AppAuth.ensureAccessToken(false);

  harness.broadcast({ type: 'session-updated', session_version: 4 });
  await new Promise(resolve => setTimeout(resolve, 0));

  assert.equal(tab.__events.some(event => event.type === 'app-auth:sensitive-clear' && event.detail.reason === 'session-version-changed'), true);
});
