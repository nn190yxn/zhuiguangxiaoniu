import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { test } from 'node:test';

function canonicalize(value) {
  if (Array.isArray(value)) return value.map(canonicalize);
  if (value && typeof value === 'object') {
    return Object.fromEntries(Object.keys(value).sort().map((key) => [key, canonicalize(value[key])]));
  }
  return value;
}

const fingerprint = (request) => createHash('sha256').update(JSON.stringify(canonicalize(request))).digest('hex');
const identity = ({ actor, operation, scope, key }) => `${actor}|${operation}|${scope}|${key}`;

class IdempotencyModel {
  records = new Map();
  callbackCount = 0;

  execute(input, request, now, callback) {
    const recordKey = identity(input);
    const requestFingerprint = fingerprint(request);
    const existing = this.records.get(recordKey);
    if (existing && existing.expiresAt > now) {
      if (existing.fingerprint !== requestFingerprint) return { type: 'conflict', status: 409 };
      if (existing.status === 'processing') return { type: 'processing', status: 409, requestId: existing.requestId };
      return { ...existing.result, replayed: true };
    }
    this.records.set(recordKey, {
      fingerprint: requestFingerprint,
      status: 'processing',
      requestId: input.requestId,
      expiresAt: now + input.ttl,
    });
    this.callbackCount += 1;
    const result = callback();
    this.records.set(recordKey, {
      fingerprint: requestFingerprint,
      status: result.status >= 400 ? 'failed' : 'completed',
      requestId: input.requestId,
      expiresAt: now + input.ttl,
      result,
    });
    return { ...result, replayed: false };
  }
}

function pseudoRandom(seed) {
  let state = seed >>> 0;
  return () => {
    state = (state * 1664525 + 1013904223) >>> 0;
    return state;
  };
}

test('任意重复序列最多执行一次并完整重放首次结果', () => {
  const random = pseudoRandom(20260904);
  for (let sample = 0; sample < 200; sample += 1) {
    const model = new IdempotencyModel();
    const value = random();
    const input = {
      actor: `staff-${random() % 20}`,
      operation: `operation-${random() % 5}`,
      scope: `scope-${random() % 9}`,
      key: `key-${random()}`,
      requestId: `request-${random()}`,
      ttl: 3600,
    };
    const request = { nested: { b: value, a: sample }, list: [sample, value] };
    const expected = { status: 200, payload: { result: random() } };
    const attempts = 2 + (random() % 12);
    const results = Array.from({ length: attempts }, () => model.execute(input, request, 100, () => expected));
    assert.equal(model.callbackCount, 1);
    assert.deepEqual(results[0], { ...expected, replayed: false });
    for (const replay of results.slice(1)) assert.deepEqual(replay, { ...expected, replayed: true });
  }
});

test('同键异指纹稳定冲突且处理中返回首次请求 ID', () => {
  const model = new IdempotencyModel();
  const input = { actor: 'staff-1', operation: 'redeem', scope: 'store-1', key: 'same', requestId: 'request-first', ttl: 60 };
  model.records.set(identity(input), {
    fingerprint: fingerprint({ amount: 1 }),
    status: 'processing',
    requestId: input.requestId,
    expiresAt: 100,
  });
  assert.deepEqual(model.execute(input, { amount: 1 }, 50, () => assert.fail()), {
    type: 'processing',
    status: 409,
    requestId: 'request-first',
  });
  assert.deepEqual(model.execute(input, { amount: 2 }, 50, () => assert.fail()), {
    type: 'conflict',
    status: 409,
  });
  assert.equal(model.callbackCount, 0);
});

test('身份、操作和业务作用域隔离同名键，过期后允许产生新结果', () => {
  const model = new IdempotencyModel();
  const base = { actor: 'staff-1', operation: 'export', scope: 'lesson-1', key: 'shared', requestId: 'request-1', ttl: 10 };
  const variants = [
    base,
    { ...base, actor: 'staff-2' },
    { ...base, operation: 'create' },
    { ...base, scope: 'lesson-2' },
  ];
  variants.forEach((input, index) => {
    const result = model.execute(input, { version: 1 }, 0, () => ({ status: 200, payload: { index } }));
    assert.equal(result.payload.index, index);
  });
  assert.equal(model.callbackCount, variants.length);
  const renewed = model.execute(base, { version: 2 }, 11, () => ({ status: 201, payload: { index: 99 } }));
  assert.deepEqual(renewed, { status: 201, payload: { index: 99 }, replayed: false });
  assert.equal(model.callbackCount, variants.length + 1);
});
