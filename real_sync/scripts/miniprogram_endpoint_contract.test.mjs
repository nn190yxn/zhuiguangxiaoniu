import assert from 'node:assert/strict';
import test from 'node:test';

import {
  assertEndpointCollectionsEqual,
  compareEndpointSets,
  normalizeEndpoint,
} from './miniprogram_endpoint_contract.mjs';

test('[validates 8.2, 8.3] endpoint key normalizes method, path and sorted action query', () => {
  assert.equal(
    normalizeEndpoint('get', '/policy/notify.php?z=last&action=read&a=first'),
    'GET /policy/notify.php?action=read',
  );
  assert.equal(
    normalizeEndpoint({ method: 'POST', path: '/auth/mini-program-session.php?action=refresh' }),
    'POST /auth/mini-program-session.php?action=refresh',
  );
  assert.notEqual(normalizeEndpoint('GET', '/todos/my.php'), normalizeEndpoint('POST', '/todos/my.php'));
});

test('[validates 8.2, 8.3] endpoint comparison reports normalized differences and duplicates', () => {
  const comparison = compareEndpointSets(
    [
      { method: 'get', path: '/todos/my.php' },
      { method: 'POST', path: '/policy/notify.php?action=read&z=1' },
      { method: 'POST', path: '/policy/notify.php?z=1&action=read' },
    ],
    [
      { method: 'GET', path: '/todos/my.php' },
      { method: 'POST', path: '/policy/notify.php?action=confirm' },
    ],
  );

  assert.deepEqual(comparison.leftOnly, ['POST /policy/notify.php?action=read']);
  assert.deepEqual(comparison.rightOnly, ['POST /policy/notify.php?action=confirm']);
  assert.deepEqual(comparison.duplicateLeft, ['POST /policy/notify.php?action=read']);
  assert.deepEqual(comparison.duplicateRight, []);
  assert.equal(comparison.equal, false);
});

test('equal normalized endpoint collections pass the assertion helper', () => {
  const comparison = assertEndpointCollectionsEqual(
    [{ method: 'GET', path: '/items.php?action=list&scope=all' }],
    [{ method: 'get', path: '/items.php?scope=all&action=list' }],
  );
  assert.equal(comparison.equal, true);
});
