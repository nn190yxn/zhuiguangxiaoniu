import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../js/api-client.js', import.meta.url), 'utf8');

function response(status, body, headers = {}) {
  const normalized = new Map(Object.entries(headers).map(([key, value]) => [key.toLowerCase(), value]));
  return {
    status,
    ok: status >= 200 && status < 300,
    headers: { get: (name) => normalized.get(String(name).toLowerCase()) || '' },
    json: async () => body
  };
}

function createClient(transport) {
  const calls = [];
  const window = {
    location: { origin: 'https://supercalf.com' },
    crypto: { randomUUID: () => '00000000-0000-4000-8000-000000000001' },
    AbortController,
    FormData,
    fetch: async (url, options) => transport(url, options, calls),
    AppAuth: {
      authFetch: async (url, options) => transport(url, options, calls),
      redirectToLogin: () => { window.redirected = true; }
    }
  };
  const context = { window, URL, Map, FormData, AbortController, setTimeout, clearTimeout, console };
  vm.runInNewContext(source, context);
  return { client: window.ApiClient, calls, window };
}

test('统一请求层传播请求 ID 并委托 AppAuth 协调刷新', async () => {
  const { client, calls } = createClient(async (url, options, captured) => {
    captured.push({ url, options });
    return response(200, { code: 0, message: 'success', data: { ok: true } }, { 'X-Request-ID': 'server-request' });
  });

  const result = await client.get('/api/example.php', { requestId: 'client-request' });
  assert.equal(calls.length, 1);
  assert.equal(calls[0].options.headers['X-Request-ID'], 'client-request');
  assert.equal(calls[0].options.credentials, 'same-origin');
  assert.equal(result.request_id, 'server-request');
});

test('写请求支持幂等键和可配置状态版本字段', async () => {
  const { client, calls } = createClient(async (url, options, captured) => {
    captured.push({ url, options });
    return response(200, { code: 0, data: {} });
  });

  await client.post('/api/object.php', { value: 7 }, {
    idempotencyKey: 'stable-operation-7',
    stateVersion: 3,
    stateVersionField: 'expected_state_version'
  });

  assert.equal(calls[0].options.headers['Idempotency-Key'], 'stable-operation-7');
  assert.deepEqual(JSON.parse(calls[0].options.body), { value: 7, expected_state_version: 3 });
});

test('ETag 和增量游标支持条件读取及 304 结果', async () => {
  let count = 0;
  const { client, calls } = createClient(async (url, options, captured) => {
    captured.push({ url, options });
    count += 1;
    if (count === 1) return response(200, { code: 0, data: { next_cursor: 'cursor-2' } }, { ETag: '"version-1"' });
    return response(304, null, { 'X-Request-ID': 'not-modified-request' });
  });

  const first = await client.get('/api/platform/sync.php?action=changes', { cursor: 'cursor-1', etag: true });
  const second = await client.get('/api/platform/sync.php?action=changes', { cursor: 'cursor-1', etag: true });

  assert.equal(calls[0].url, '/api/platform/sync.php?action=changes&cursor=cursor-1');
  assert.equal(first.next_cursor, 'cursor-2');
  assert.equal(first.etag, '"version-1"');
  assert.equal(calls[1].options.headers['If-None-Match'], '"version-1"');
  assert.equal(second.not_modified, true);
  assert.equal(second.request_id, 'not-modified-request');
});

test('409 冲突暴露权威状态并按恢复决策仅重试一次', async () => {
  let count = 0;
  const { client, calls } = createClient(async (url, options, captured) => {
    captured.push({ url, options });
    count += 1;
    if (count === 1) {
      return response(409, {
        code: 'version_conflict',
        message: '状态已更新',
        data: {
          conflict_type: 'state_version',
          base_version: 1,
          current_version: 4,
          authoritative_state: { status: 'submitted' },
          recovery_action: 'refresh',
          retryable: true
        }
      });
    }
    return response(200, { code: 0, data: { saved: true } });
  });

  const result = await client.post('/api/object.php', { value: 9 }, {
    stateVersion: 1,
    onConflict: async (error) => {
      assert.equal(error.category, 'conflict');
      assert.equal(error.currentVersion, 4);
      assert.deepEqual(error.authoritativeState, { status: 'submitted' });
      assert.equal(error.recoveryAction, 'refresh');
      return { retry: true, stateVersion: error.currentVersion };
    }
  });

  assert.equal(calls.length, 2);
  assert.equal(JSON.parse(calls[0].options.body).state_version, 1);
  assert.equal(JSON.parse(calls[1].options.body).state_version, 4);
  assert.equal(result.data.saved, true);
});

test('网络错误映射为稳定分类并保留请求 ID', async () => {
  const { client } = createClient(async () => { throw new TypeError('offline'); });

  await assert.rejects(
    client.get('/api/example.php', { requestId: 'offline-request' }),
    (error) => error.name === 'ApiClientError'
      && error.category === 'network'
      && error.code === 0
      && error.requestId === 'offline-request'
  );
});
