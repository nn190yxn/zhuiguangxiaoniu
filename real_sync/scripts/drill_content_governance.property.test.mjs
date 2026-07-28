import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';

const policyPath = fileURLToPath(new URL('../api/drill/v2/services/DrillContentPolicy.php', import.meta.url));

function callPolicy(method, args) {
  const php = [
    `require_once ${JSON.stringify(policyPath)};`,
    `$args = json_decode(${JSON.stringify(JSON.stringify(args))}, true, 512, JSON_THROW_ON_ERROR);`,
    'try {',
    `  $value = DrillContentPolicy::${method}(...$args);`,
    "  echo json_encode(['ok' => true, 'value' => $value], JSON_THROW_ON_ERROR);",
    '} catch (Throwable $error) {',
    "  echo json_encode(['ok' => false, 'message' => $error->getMessage()], JSON_THROW_ON_ERROR);",
    '}',
  ].join('\n');
  const result = spawnSync('php', ['-r', php], { encoding: 'utf8', timeout: 10_000 });
  assert.equal(result.status, 0, result.stderr);
  return JSON.parse(result.stdout);
}

function random(seed) {
  let state = seed >>> 0;
  return () => {
    state = (state * 1664525 + 1013904223) >>> 0;
    return state / 0x100000000;
  };
}

test('property 14: every AI candidate publication requires a human review identity and time', () => {
  for (let seed = 1; seed <= 96; seed++) {
    const next = random(seed);
    const reviewedBy = next() > 0.5 ? 1 + Math.floor(next() * 1000) : null;
    const reviewedAt = next() > 0.5 ? '2026-07-27 12:00:00' : null;
    const result = callPolicy('assertHumanReviewedCandidate', ['ai_candidate', reviewedBy, reviewedAt]);
    assert.equal(result.ok, reviewedBy !== null && reviewedAt !== null, `seed ${seed}`);
  }
});

test('property 19: reference material is eligible only with authorization, validity, hash, and no open blocker', () => {
  const at = '2026-07-27 12:00:00';
  for (let seed = 1; seed <= 128; seed++) {
    const next = random(seed);
    const authorized = next() > 0.5;
    const valid = next() > 0.5;
    const goodHash = next() > 0.5;
    const blocked = next() > 0.5;
    const material = {
      authorization_status: authorized ? 'authorized' : 'pending',
      authorization_reference: authorized ? `approval-${seed}` : null,
      effective_from: valid ? '2026-07-01 00:00:00' : '2026-08-01 00:00:00',
      effective_until: valid ? '2026-08-01 00:00:00' : '2026-09-01 00:00:00',
      content_hash: goodHash ? 'a'.repeat(64) : 'invalid',
    };
    const issues = blocked ? [{ status: 'open', severity: 'blocking' }] : [];
    const result = callPolicy('referencePreflight', [material, at, issues]);
    assert.equal(result.ok, true);
    assert.equal(result.value.length === 0, authorized && valid && goodHash && !blocked, `seed ${seed}`);
  }
});

test('training domain routing never allows new signing rubrics in renewal', () => {
  for (const context of ['real_call_review', 'training_demo', 'ai_roleplay']) {
    const newSigning = callPolicy('rubricCodeForContext', ['new_signing', context]);
    assert.equal(newSigning.ok, true);
    const renewal = callPolicy('rubricCodeForContext', ['renewal', context]);
    assert.equal(renewal.ok, false);
  }
});
