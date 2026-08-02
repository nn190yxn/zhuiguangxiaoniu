import assert from 'node:assert/strict';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

import { buildPlatformContractSnapshot, compareContractSnapshots } from './platform_contract_snapshot.mjs';

const projectRoot = fileURLToPath(new URL('../', import.meta.url));

test('API 与客户端契约快照保持确定性并覆盖全部 API 资产', () => {
  const first = buildPlatformContractSnapshot({ projectRoot });
  const second = buildPlatformContractSnapshot({ projectRoot });

  assert.deepEqual(first, second);
  assert.ok(first.summary.endpoint_count > 300);
  assert.equal(new Set(first.endpoints.map(({ path }) => path)).size, first.endpoints.length);
  assert.ok(first.endpoints.every(({ methods, signals, group_ids: groupIds }) => methods.length > 0 && Array.isArray(signals) && groupIds.length > 0));
  assert.ok(first.clients.every(({ endpoint }) => endpoint.startsWith('api/') && endpoint.endsWith('.php')));
});

test('契约快照记录认证、响应、幂等和运行时 DDL 信号', () => {
  const snapshot = buildPlatformContractSnapshot({ projectRoot });

  assert.ok(snapshot.summary.auth_signal_count > 0);
  assert.ok(snapshot.summary.request_id_count > 0);
  assert.ok(snapshot.summary.idempotency_count > 0);
  assert.ok(snapshot.summary.runtime_ddl_count > 0);
  assert.ok(snapshot.endpoints.some(({ signals }) => signals.includes('json_envelope')));
});

test('契约比较器识别新增、删除和字段漂移', () => {
  const expected = {
    endpoints: [
      { path: 'api/a.php', methods: ['GET'], actions: [], signals: ['jwt'] },
      { path: 'api/b.php', methods: ['POST'], actions: ['save'], signals: ['transaction'] },
    ],
  };
  const actual = {
    endpoints: [
      { path: 'api/a.php', methods: ['POST'], actions: [], signals: ['jwt'] },
      { path: 'api/c.php', methods: ['GET'], actions: [], signals: [] },
    ],
  };
  const changes = compareContractSnapshots(expected, actual);

  assert.deepEqual(changes.map(({ type }) => type).sort(), ['added_endpoint', 'changed_contract', 'removed_endpoint']);
  assert.ok(changes.some(({ type, path, field }) => type === 'changed_contract' && path === 'api/a.php' && field === 'methods'));
});
