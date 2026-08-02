import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../js/draft-store.js', import.meta.url), 'utf8');

function createHarness(handler = async () => ({ code: 0, data: { draft: null } })) {
  const values = new Map();
  const requests = [];
  const storage = {
    getItem: key => values.get(key) ?? null,
    setItem: (key, value) => values.set(key, String(value)),
    removeItem: key => values.delete(key),
    key: index => [...values.keys()][index] ?? null,
    get length() { return values.size; },
  };
  const ApiClient = {
    async get(url, options) {
      requests.push({ method: 'GET', url, options });
      return handler({ method: 'GET', url, options });
    },
    async put(url, body, options) {
      requests.push({ method: 'PUT', url, body, options });
      return handler({ method: 'PUT', url, body, options });
    },
    async delete(url, body, options) {
      requests.push({ method: 'DELETE', url, body, options });
      return handler({ method: 'DELETE', url, body, options });
    },
  };
  const context = {
    ApiClient,
    crypto: { randomUUID: () => 'device-fixed' },
    Date,
    JSON,
    localStorage: storage,
    URL,
    URLSearchParams,
  };
  context.window = context;
  vm.runInNewContext(source, context, { filename: 'draft-store.js' });
  return { DraftStore: context.DraftStore, requests, values };
}

function workloadStore(DraftStore, schemaVersion = '1') {
  return DraftStore.create({
    domain: 'workload',
    objectType: 'daily_report',
    objectId: '2026-08-01:2:sales',
    schemaVersion,
    allowedFields: ['report_date', 'metrics', 'notes', 'attachments'],
  });
}

test('受控草稿仅保存批准字段并按身份、对象和 schema 版本隔离', () => {
  const { DraftStore, values } = createHarness();
  DraftStore.setIdentity({ userId: 11, staffId: 22, sessionVersion: 3 });
  const store = workloadStore(DraftStore);

  store.saveLocal({
    report_date: '2026-08-01',
    metrics: { calls: '8' },
    notes: '待核对',
    attachments: [],
    access_token: 'must-not-persist',
  });

  assert.deepEqual(JSON.parse(JSON.stringify(store.getLocal().payload)), {
    report_date: '2026-08-01', metrics: { calls: '8' }, notes: '待核对', attachments: [],
  });
  assert.equal([...values.values()].some(value => value.includes('must-not-persist')), false);

  DraftStore.setIdentity({ userId: 12, staffId: 22, sessionVersion: 3 });
  assert.equal(workloadStore(DraftStore).getLocal(), null);
  DraftStore.setIdentity({ userId: 11, staffId: 22, sessionVersion: 3 });
  assert.equal(workloadStore(DraftStore, '2').getLocal(), null);
});

test('本地草稿有效期最多 24 小时且读取时清理过期记录', () => {
  const { DraftStore, values } = createHarness();
  DraftStore.setIdentity({ userId: 11, staffId: 22, sessionVersion: 3 });
  const store = workloadStore(DraftStore);
  const saved = store.saveLocal({ notes: 'short lived' }, { ttlMs: 7 * 24 * 60 * 60 * 1000 });

  assert.ok(saved.expires_at - saved.updated_at <= 24 * 60 * 60 * 1000);
  const [key, raw] = [...values.entries()].find(([storageKey]) => storageKey.startsWith('zgxn_sensitive_draft:'));
  const expired = JSON.parse(raw);
  expired.expires_at = Date.now() - 1;
  values.set(key, JSON.stringify(expired));

  assert.equal(store.getLocal(), null);
  assert.equal(values.has(key), false);
});

test('设备标识保持稳定且敏感草稿清理不影响普通偏好', () => {
  const { DraftStore, values } = createHarness();
  DraftStore.setIdentity({ userId: 11, staffId: 22, sessionVersion: 3 });
  workloadStore(DraftStore).saveLocal({ notes: 'clear me' });
  values.set('theme', 'dark');

  assert.equal(DraftStore.getDeviceId(), 'device-fixed');
  assert.equal(DraftStore.getDeviceId(), 'device-fixed');
  assert.equal(DraftStore.clearSensitive(), 1);
  assert.equal(values.get('theme'), 'dark');
  assert.equal([...values.keys()].some(key => key.startsWith('zgxn_sensitive_draft:')), false);
});

test('远端草稿请求携带版本、设备、批准 payload 与 24 小时 TTL', async () => {
  const { DraftStore, requests } = createHarness(async request => ({
    code: 0,
    data: { draft: request.method === 'GET' ? null : { draft_version: 5, payload: request.body.payload } },
  }));
  DraftStore.setIdentity({ userId: 11, staffId: 22, sessionVersion: 3 });
  const store = workloadStore(DraftStore);

  await store.getRemote();
  const remote = await store.saveRemote({ notes: 'remote', password: 'blocked' }, {
    draftVersion: 4,
    baseStateVersion: 0,
  });

  assert.match(requests[0].url, /^\/api\/platform\/sync\.php\?action=draft&/);
  assert.match(requests[0].url, /domain=workload/);
  assert.match(requests[0].url, /object_type=daily_report/);
  assert.deepEqual(JSON.parse(JSON.stringify(requests[1].body.payload)), { notes: 'remote' });
  assert.equal(requests[1].body.draft_version, 4);
  assert.equal(requests[1].body.base_state_version, 0);
  assert.equal(requests[1].body.source_client, 'pwa');
  assert.equal(requests[1].body.source_device_id, 'device-fixed');
  assert.equal(requests[1].body.ttl_seconds, 86400);
  assert.equal(remote.draft_version, 5);
});
