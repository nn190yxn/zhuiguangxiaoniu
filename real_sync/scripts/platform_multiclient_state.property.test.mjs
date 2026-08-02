import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { test } from 'node:test';

const root = new URL('..', import.meta.url);

function random(seed) {
  let state = seed >>> 0;
  return () => {
    state = (Math.imul(state, 1664525) + 1013904223) >>> 0;
    return state / 0x100000000;
  };
}

test('[validates 4.1, 4.3, 4.10, Property 4] all clients derive one state for a stable ID and version', () => {
  const fixtures = [];
  for (let seed = 1; seed <= 128; seed += 1) {
    const next = random(0x04000000 + seed);
    const state = {
      status: ['draft', 'submitted', 'approved'][Math.floor(next() * 3)],
      score: Math.floor(next() * 101),
      metadata: { store_id: 1 + Math.floor(next() * 8), active: next() >= 0.5 },
    };
    fixtures.push({
      object_type: 'approval',
      object_id: `approval-${1 + Math.floor(next() * 32)}`,
      state_version: 1 + Math.floor(next() * 64),
      state,
      reordered_state: {
        metadata: { active: state.metadata.active, store_id: state.metadata.store_id },
        score: state.score,
        status: state.status,
      },
    });
  }

  const php = String.raw`
    require 'api/kernel/bootstrap.php';
    $fixtures = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $results = [];
    foreach ($fixtures as $fixture) {
      $states = [];
      foreach (['web', 'pwa', 'mini_program'] as $client) {
        $state = $client === 'mini_program' ? $fixture['reordered_state'] : $fixture['state'];
        $states[$client] = PlatformSyncProtocol::syncObject(
          $fixture['object_type'],
          $fixture['object_id'],
          $fixture['state_version'],
          '2026-07-31 12:00:00',
          'A',
          $state
        );
      }
      $results[] = $states;
    }
    echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
  `;
  const result = spawnSync('php', ['-r', php], {
    cwd: root,
    encoding: 'utf8',
    input: JSON.stringify(fixtures),
  });
  assert.equal(result.status, 0, result.stderr);

  for (const clients of JSON.parse(result.stdout)) {
    assert.equal(clients.web.object_id, clients.pwa.object_id);
    assert.equal(clients.web.object_id, clients.mini_program.object_id);
    assert.equal(clients.web.state_version, clients.pwa.state_version);
    assert.equal(clients.web.state_version, clients.mini_program.state_version);
    assert.deepEqual(clients.web.state, clients.pwa.state);
    assert.deepEqual(clients.web.state, clients.mini_program.state);
    assert.equal(clients.web.etag, clients.pwa.etag);
    assert.equal(clients.web.etag, clients.mini_program.etag);
  }
});
