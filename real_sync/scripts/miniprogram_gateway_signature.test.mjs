import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { join } from 'node:path';
import test from 'node:test';

const require = createRequire(import.meta.url);
const projectRoot = new URL('../', import.meta.url).pathname;
const proxy = require('../cloudfunctions/api-proxy/index.js');

test('PHP GatewaySignature 具备 HMAC、时间窗口、nonce 和 body hash 校验结构', () => {
  const source = readFileSync(join(projectRoot, 'api/cloud/GatewaySignature.php'), 'utf8');

  for (const fragment of [
    'hash_hmac',
    'hash_equals',
    'signature_timestamp_invalid',
    'signature_nonce_replayed',
    'signature_body_hash_invalid',
    'signature_mismatch',
    'HTTP_X_CLOUD_SIGNATURE_VERSION',
    'HTTP_X_CLOUD_BODY_SHA256'
  ]) {
    assert.ok(source.includes(fragment), `缺少签名校验片段: ${fragment}`);
  }
});

test('api-proxy 在提供密钥时追加网关签名请求头', async () => {
  let captured = null;
  const handler = proxy.createHandler({
    gatewaySecret: 'test-secret-only-for-unit-test',
    now: 1800000000,
    transport(options) {
      captured = options;
      return Promise.resolve({ statusCode: 200, body: { code: 0, data: {} } });
    }
  });

  await handler({
    protocol_version: 1,
    type: 'request',
    route: '/workload/save-report.php',
    method: 'POST',
    request_id: 'req-sign',
    data: { value: 1 }
  });

  assert.equal(captured.headers['x-cloud-signature-version'], 'v1');
  assert.equal(captured.headers['x-cloud-timestamp'], '1800000000');
  assert.match(captured.headers['x-cloud-nonce'], /^[a-f0-9]{32}$/);
  assert.match(captured.headers['x-cloud-body-sha256'], /^[a-f0-9]{64}$/);
  assert.match(captured.headers['x-cloud-signature'], /^[a-f0-9]{64}$/);
});
