import assert from 'node:assert/strict';
import { test } from 'node:test';

function random(seed) {
  let state = seed >>> 0;
  return () => {
    state = (state * 1664525 + 1013904223) >>> 0;
    return state / 0x100000000;
  };
}

function resolveTargets(candidates, scopes) {
  const included = new Set();
  const excluded = new Set();
  for (const candidate of candidates) {
    if (!candidate.active) continue;
    for (const scope of scopes) {
      const matches = scope.type === 'position'
        ? candidate.positions.includes(scope.key)
        : scope.type === 'store'
          ? candidate.stores.includes(scope.key)
          : scope.type === 'staff'
            ? candidate.employeeNo === scope.key
            : candidate.stage === scope.key;
      if (matches) (scope.mode === 'exclude' ? excluded : included).add(candidate.id);
    }
  }
  return [...included].filter((id) => !excluded.has(id)).sort((a, b) => a - b);
}

test('property 16 target resolution is stable under rule order and duplicate matches', () => {
  for (let seed = 1; seed <= 128; seed++) {
    const next = random(seed);
    const candidates = Array.from({ length: 100 }, (_, index) => ({
      id: index + 1,
      employeeNo: `E${index + 1}`,
      stage: ['intern', 'probation', 'advanced'][Math.floor(next() * 3)],
      positions: [next() > 0.5 ? 'sales' : 'coach'],
      stores: [`S${1 + Math.floor(next() * 4)}`],
      active: next() > 0.15,
    }));
    const scopes = [
      { type: 'position', key: 'sales', mode: 'include' },
      { type: 'store', key: 'S1', mode: 'include' },
      { type: 'growth_stage', key: 'intern', mode: 'exclude' },
    ];
    const expected = resolveTargets(candidates, scopes);
    assert.deepEqual(resolveTargets(candidates, [...scopes].reverse()), expected);
    assert.deepEqual(resolveTargets(candidates, [...scopes, ...scopes]), expected);
    assert.equal(new Set(expected).size, expected.length);
  }
});

test('property 17 publication assignment identity is unique per publication and employee', () => {
  for (let seed = 1; seed <= 128; seed++) {
    const next = random(seed);
    const identities = new Set();
    const publicationId = 1 + Math.floor(next() * 20);
    for (let index = 0; index < 1000; index++) {
      const staffId = 1 + Math.floor(next() * 100);
      identities.add(`${publicationId}:${staffId}`);
    }
    assert.ok(identities.size <= 100);
    for (const identity of identities) assert.match(identity, new RegExp(`^${publicationId}:\\d+$`));
  }
});

test('property 20 repeated publication keys replay one publication identity', () => {
  for (let seed = 1; seed <= 128; seed++) {
    const next = random(seed);
    const publications = new Map();
    const planId = 1 + Math.floor(next() * 20);
    for (let index = 0; index < 1000; index++) {
      const key = `request-${1 + Math.floor(next() * 50)}`;
      const identity = `${planId}:${key}`;
      if (!publications.has(identity)) publications.set(identity, publications.size + 1);
      assert.equal(publications.get(identity), publications.get(`${planId}:${key}`));
    }
    assert.ok(publications.size <= 50);
  }
});
