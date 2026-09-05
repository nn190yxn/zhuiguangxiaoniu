import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import vm from 'node:vm';

const read = (relativePath) => readFileSync(new URL(relativePath, import.meta.url), 'utf8');
const internalAuth = read('../internal-auth.js');
const foundationTraining = read('../training/09-foundation/index.html');

test('internal auth loads the PWA session runtime when a refresh session exists', () => {
  assert.match(internalAuth, /if \(!readCookie\('platform_csrf'\)\) \{/);
  assert.match(internalAuth, /script\.src = APP_AUTH_PATH/);
  assert.match(internalAuth, /await ensureAppAuthAvailable\(\)/);
  assert.match(internalAuth, /window\.AppAuth\.ensureAccessToken\(false\)/);
});

test('internal auth keeps legacy token sessions independent from the PWA runtime loader', () => {
  assert.match(internalAuth, /return Promise\.resolve\(null\)/);
  assert.match(internalAuth, /return readStoredValue\(\['jwt_token', 'token'\]\)/);
});

test('foundation training requests the fixed internal auth version', () => {
  assert.match(foundationTraining, /internal-auth\.js\?v=20260822-pwa-session1/);
});

test('internal auth restores a refresh-cookie session before checking the current user', async () => {
  const localValues = new Map();
  const sessionValues = new Map();
  const storage = values => ({
    getItem: key => values.get(key) || null,
    setItem: (key, value) => values.set(key, String(value)),
    removeItem: key => values.delete(key),
  });
  let loadedScript = '';
  let refreshCalls = 0;
  let currentUserCalls = 0;

  const context = {
    document: {
      cookie: 'platform_csrf=csrf-current',
      readyState: 'complete',
      querySelector: () => null,
      createElement: tagName => ({ tagName, dataset: {} }),
      head: {
        appendChild(script) {
          loadedScript = script.src;
          context.AppAuth = {
            ensureAccessToken: async () => {
              refreshCalls += 1;
              return 'access-current';
            },
            getToken: () => 'access-current',
            authHeaders: extra => ({ ...extra, Authorization: 'Bearer access-current' }),
          };
          queueMicrotask(() => script.onload());
        },
      },
    },
    fetch: async (url, options) => {
      currentUserCalls += 1;
      assert.equal(url, '/api/auth/me.php');
      assert.equal(options.headers.Authorization, 'Bearer access-current');
      return {
        ok: true,
        status: 200,
        text: async () => JSON.stringify({ code: 0, data: { id: 18, role: 'coach' } }),
      };
    },
    localStorage: storage(localValues),
    sessionStorage: storage(sessionValues),
    location: {
      protocol: 'https:',
      pathname: '/training/09-foundation/',
      search: '',
      hash: '',
      href: 'https://supercalf.com/training/09-foundation/',
    },
    queueMicrotask,
    setTimeout,
    clearTimeout,
  };
  context.window = context;

  vm.runInNewContext(internalAuth, context, { filename: 'internal-auth.js' });
  await new Promise(resolve => setTimeout(resolve, 10));

  const appAuthPath = internalAuth.match(/const APP_AUTH_PATH = '([^']+)'/)?.[1];
  assert.equal(loadedScript, appAuthPath);
  assert.equal(refreshCalls, 1);
  assert.equal(currentUserCalls, 1);
  assert.equal(context.location.href, 'https://supercalf.com/training/09-foundation/');
  assert.deepEqual(JSON.parse(localValues.get('user_info')), { id: 18, role: 'coach' });
});
