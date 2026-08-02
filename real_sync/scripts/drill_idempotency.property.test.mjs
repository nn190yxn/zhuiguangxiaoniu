import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

function canonicalize(value) {
  if (Array.isArray(value)) return value.map(canonicalize);
  if (value && typeof value === 'object') {
    return Object.fromEntries(Object.keys(value).sort().map((key) => [key, canonicalize(value[key])]));
  }
  return value;
}

function hashRequest(request) {
  return createHash('sha256').update(JSON.stringify(canonicalize(request))).digest('hex');
}

class IdempotencyModel {
  records = new Map();

  execute(userId, action, key, request, operation) {
    const identity = `${userId}:${action}:${key}`;
    const requestHash = hashRequest(request);
    const existing = this.records.get(identity);
    if (existing) {
      if (existing.requestHash !== requestHash) return { status: 409 };
      return { status: 200, result: existing.result, idempotent: true };
    }
    const result = operation();
    this.records.set(identity, { requestHash, result });
    return { status: 200, result, idempotent: false };
  }
}

function random(seed) {
  let state = seed >>> 0;
  return () => {
    state = (state * 1664525 + 1013904223) >>> 0;
    return state / 0x100000000;
  };
}

test('[validates 4.2, Property 1] one user, action, and idempotency key commits one business result', () => {
  for (let seed = 1; seed <= 128; seed++) {
    const next = random(seed);
    const model = new IdempotencyModel();
    let writes = 0;
    for (let step = 0; step < 256; step++) {
      const userId = 1 + Math.floor(next() * 4);
      const action = ['attempt.create', 'turn.finalize', 'legacy.step.complete'][Math.floor(next() * 3)];
      const key = `key-${step}`;
      const value = Math.floor(next() * 8);
      const request = step % 2 === 0 ? { value, nested: { a: 1, b: 2 } } : { nested: { b: 2, a: 1 }, value };
      const first = model.execute(userId, action, key, request, () => ({ write_id: ++writes }));
      const replay = model.execute(userId, action, key, request, () => ({ write_id: ++writes }));
      assert.equal(first.status, 200);
      assert.equal(replay.status, 200);
      assert.equal(replay.idempotent, true);
      assert.deepEqual(replay.result, first.result);

      const conflict = model.execute(userId, action, key, { ...request, value: value + 100 }, () => ({ write_id: ++writes }));
      assert.equal(conflict.status, 409);
    }
    assert.equal(writes, model.records.size);
  }
});

test('production contract persists the same idempotency identity under a row lock', () => {
  const service = readFileSync(new URL('../api/drill/v2/services/DrillIdempotencyService.php', import.meta.url), 'utf8');
  const migration = readFileSync(new URL('../database/migrations/202607270001_drill_api_foundation.sql', import.meta.url), 'utf8');
  assert.match(service, /user_id = \? AND action = \? AND idempotency_key = \? FOR UPDATE/);
  assert.match(service, /hash_equals\(\(string\) \$row\['request_hash'\], \$hash\)/);
  assert.match(migration, /UNIQUE KEY uq_drill_idempotency_identity \(user_id, action, idempotency_key\)/);
});
